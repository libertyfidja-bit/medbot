<?php
/**
 * MedBot — sign-in and access control (shared by every page).
 *
 * gate.php runs BEFORE every PHP page (the Dockerfile sets auto_prepend_file), so even pages
 * and APIs written earlier are protected without being edited. Rules live in auth_allowed().
 *
 * Roles: admin, doctor, nurse (also covers technicians/robot operators), patient.
 *
 * Emergency switch: setting the environment variable AUTH_DISABLED=1 turns the gate off.
 * Use it only to recover from a mistake and remove it straight afterwards.
 */
require_once __DIR__ . '/connect.php';

const AUTH_STAFF        = ['admin', 'doctor', 'nurse'];
const AUTH_IDLE_STAFF   = 1800;    // sign out after 30 minutes without activity
const AUTH_IDLE_PATIENT = 3600;    // patients: 60 minutes
const AUTH_MAX_AGE      = 43200;   // nobody stays signed in longer than 12 hours
const AUTH_DUMMY_HASH   = '$2y$10$yvGJRvPeqLq1F7EaWxmM35v5LOcSrkwl2.KFqZj1o3gr0nPm/GhTy';

/* ───────────── small helpers ───────────── */

function auth_h($s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function auth_is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    return stripos((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''), 'https') === 0;
}

function auth_row(mysqli $db, string $sql, string $types = '', array $params = []): ?array {
    $stmt = $db->prepare($sql);
    if (!$stmt) return null;
    if ($types !== '') $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) { $stmt->close(); return null; }
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function auth_exec(mysqli $db, string $sql, string $types = '', array $params = []): ?int {
    $stmt = $db->prepare($sql);
    if (!$stmt) return null;
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $ok = $stmt->execute();
    $n = $ok ? $stmt->affected_rows : null;
    $stmt->close();
    return $n;
}

/* ───────────── sessions ───────────── */

function auth_start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('medbot_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => auth_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function auth_destroy_session(): void {
    auth_start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => 'Lax',
        ]);
    }
    session_destroy();
}

function auth_csrf(): string {
    auth_start_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function auth_csrf_ok(): bool {
    auth_start_session();
    $sent = (string) ($_POST['csrf'] ?? '');
    return $sent !== '' && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $sent);
}

/* ───────────── database tables ───────────── */

function auth_ensure_tables(mysqli $db): void {
    $db->query("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(60) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('admin','doctor','nurse','patient') NOT NULL,
        full_name VARCHAR(150) NOT NULL,
        patient_id INT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        must_change TINYINT(1) NOT NULL DEFAULT 0,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_login TIMESTAMP NULL,
        UNIQUE KEY uq_users_username (username)
    )");
    $db->query("CREATE TABLE IF NOT EXISTS access_links (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        expires_at TIMESTAMP NOT NULL,
        used_at TIMESTAMP NULL,
        UNIQUE KEY uq_access_token (token_hash)
    )");
    $db->query("CREATE TABLE IF NOT EXISTS patient_devices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_used TIMESTAMP NULL,
        expires_at TIMESTAMP NOT NULL,
        UNIQUE KEY uq_device_token (token_hash)
    )");
    $db->query("CREATE TABLE IF NOT EXISTS login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(60) NOT NULL,
        ip VARCHAR(45),
        success TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_login_user_time (username, created_at)
    )");
}

function auth_has_users(): bool {
    $db = getDB();
    $res = $db->query('SELECT 1 FROM users LIMIT 1');
    if (!$res) return false;
    $has = $res->num_rows > 0;
    $res->free();
    return $has;
}

/* ───────────── who is signed in ───────────── */

function auth_user(): ?array {
    static $cache = false;
    if ($cache !== false) return $cache;

    if (getenv('AUTH_DISABLED') === '1') {
        return $cache = ['id' => 0, 'username' => 'auth-disabled', 'role' => 'admin', 'full_name' => '(security is off)', 'patient_id' => null, 'must_change' => 0];
    }

    auth_start_session();
    $id = (int) ($_SESSION['uid'] ?? 0);

    // A patient's phone that opened a valid access link is remembered (30 days): no password needed
    if ($id <= 0 && !empty($_COOKIE['medbot_pt']) && preg_match('/^[a-f0-9]{64}$/', (string) $_COOKIE['medbot_pt'])) {
        $dev = auth_row(getDB(), "SELECT d.id, d.user_id FROM patient_devices d JOIN users u ON u.id = d.user_id WHERE d.token_hash = ? AND d.expires_at > NOW() AND u.active = 1 AND u.role = 'patient'", 's', [auth_hash_token((string) $_COOKIE['medbot_pt'])]);
        if ($dev) {
            session_regenerate_id(true);
            $_SESSION = ['uid' => (int) $dev['user_id'], 'started' => time(), 'last_seen' => time()];
            $id = (int) $dev['user_id'];
            auth_exec(getDB(), 'UPDATE patient_devices SET last_used = NOW() WHERE id = ?', 'i', [(int) $dev['id']]);
        }
    }
    if ($id <= 0) return $cache = null;

    $row = auth_row(getDB(), 'SELECT id, username, role, full_name, patient_id, active, must_change FROM users WHERE id = ?', 'i', [$id]);
    if (!$row || (int) $row['active'] !== 1) {
        auth_destroy_session();
        return $cache = null;
    }

    $now   = time();
    $limit = $row['role'] === 'patient' ? AUTH_IDLE_PATIENT : AUTH_IDLE_STAFF;
    $seen  = (int) ($_SESSION['last_seen'] ?? $now);
    $start = (int) ($_SESSION['started'] ?? $now);
    if ($now - $seen > $limit || $now - $start > AUTH_MAX_AGE) {
        auth_destroy_session();
        return $cache = null;
    }
    $_SESSION['last_seen'] = $now;

    $row['id']          = (int) $row['id'];
    $row['patient_id']  = $row['patient_id'] === null ? null : (int) $row['patient_id'];
    $row['must_change'] = (int) $row['must_change'];
    return $cache = $row;
}

function auth_is_staff(?array $u = null): bool {
    $u = $u ?? auth_user();
    return $u !== null && in_array($u['role'], AUTH_STAFF, true);
}

/* ───────────── URLs ───────────── */

function auth_base_url(): string {
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/');
    $dir = str_replace('\\', '/', dirname($script));
    if (substr($dir, -4) === '/api') $dir = substr($dir, 0, -4);
    return ($dir === '/' || $dir === '.' || $dir === '') ? '' : rtrim($dir, '/');
}

function auth_redirect(string $path): never {
    header('Location: ' . auth_base_url() . '/' . ltrim($path, '/'));
    exit;
}

function auth_home_path(array $u): string {
    return $u['role'] === 'patient' ? 'patient.php' : 'home.php';
}

function auth_safe_next($n): string {
    $n = (string) $n;
    if ($n === '' || $n[0] !== '/' || strpos($n, '//') === 0 || strpos($n, '\\') !== false) return '';
    if (!preg_match('#^/[A-Za-z0-9_\-./]*(\?[A-Za-z0-9_\-=&%.]*)?$#', $n)) return '';
    return $n;
}

/* ───────────── passwords and accounts ───────────── */

function auth_password_error(string $pw, string $username = ''): ?string {
    if (strlen($pw) < 8)   return 'Use at least 8 characters.';
    if (strlen($pw) > 200) return 'That password is too long.';
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) return 'Use at least one letter and one number.';
    if ($username !== '' && strcasecmp($pw, $username) === 0) return 'The password must not be the same as the username.';
    $weak = ['password1', 'password123', 'admin1234', 'admin1234!', '12345678a', 'qwerty123', 'medbot123', 'medbot2026'];
    if (in_array(strtolower($pw), $weak, true)) return 'That password is too easy to guess.';
    return null;
}

function auth_temp_password(): string {
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ';
    $out = '';
    for ($i = 0; $i < 8; $i++) $out .= $chars[random_int(0, strlen($chars) - 1)];
    return $out . random_int(10, 99);
}

function auth_valid_username(string $u): bool {
    return (bool) preg_match('/^[a-z0-9._-]{3,40}$/', $u);
}

function auth_create_user(mysqli $db, string $username, string $password, string $role, string $fullName, ?int $patientId, bool $mustChange, ?int $createdBy): bool {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $must = $mustChange ? 1 : 0;
    $n = auth_exec(
        $db,
        'INSERT INTO users (username, password_hash, role, full_name, patient_id, must_change, created_by) VALUES (?,?,?,?,?,?,?)',
        'ssssiii',
        [$username, $hash, $role, $fullName, $patientId, $must, $createdBy]
    );
    return $n !== null && $n > 0;
}

/** Returns [ok, message, user]. Locks a username for 10 minutes after 5 wrong passwords. */
function auth_login(string $username, string $password): array {
    $db = getDB();
    auth_ensure_tables($db);
    $username = strtolower(trim($username));
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

    auth_exec($db, 'DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)');

    $fails = auth_row($db, "SELECT COUNT(*) AS n FROM login_attempts WHERE username = ? AND success = 0 AND created_at > (NOW() - INTERVAL 10 MINUTE)", 's', [$username]);
    if ($fails && (int) $fails['n'] >= 5) {
        return [false, 'Too many wrong attempts. Please wait 10 minutes, or ask an administrator to reset your password.', null];
    }

    $row  = auth_row($db, 'SELECT id, password_hash, role, full_name, patient_id, active, must_change FROM users WHERE username = ?', 's', [$username]);
    $hash = $row ? $row['password_hash'] : AUTH_DUMMY_HASH;     // same work whether or not the user exists
    $ok   = password_verify($password, $hash) && $row && (int) $row['active'] === 1;

    if (!$ok) {
        auth_exec($db, 'INSERT INTO login_attempts (username, ip, success) VALUES (?,?,0)', 'ss', [$username, $ip]);
        return [false, 'Wrong username or password.', null];
    }

    auth_exec($db, 'DELETE FROM login_attempts WHERE username = ?', 's', [$username]);
    auth_exec($db, 'UPDATE users SET last_login = NOW() WHERE id = ?', 'i', [(int) $row['id']]);

    auth_start_session();
    session_regenerate_id(true);
    $_SESSION = [
        'uid'       => (int) $row['id'],
        'started'   => time(),
        'last_seen' => time(),
    ];
    $row['id'] = (int) $row['id'];
    return [true, '', $row];
}

/* ───────────── patients: access by link / QR code (no password) ───────────── */

function auth_hash_token(string $t): string {
    return hash('sha256', $t);
}

/** Creates the patient's sign-in account. The random password is never shown or used. */
function auth_create_patient_account(mysqli $db, int $patientId, string $fullName, ?int $createdBy): ?int {
    $username = 'pt' . $patientId . '-' . bin2hex(random_bytes(2));
    if (!auth_create_user($db, $username, bin2hex(random_bytes(16)), 'patient', $fullName, $patientId, false, $createdBy)) return null;
    $row = auth_row($db, 'SELECT id FROM users WHERE username = ?', 's', [$username]);
    return $row ? (int) $row['id'] : null;
}

/** One-time link (valid 72 hours) that signs a patient's phone in. Returns the secret token. */
function auth_new_access_link(mysqli $db, int $userId, ?int $createdBy): string {
    auth_exec($db, 'DELETE FROM access_links WHERE user_id = ? AND used_at IS NULL', 'i', [$userId]);
    $token = bin2hex(random_bytes(20));
    auth_exec($db, 'INSERT INTO access_links (user_id, token_hash, expires_at, created_by) VALUES (?,?,(NOW() + INTERVAL 72 HOUR),?)', 'isi', [$userId, auth_hash_token($token), $createdBy]);
    return $token;
}

function auth_access_url(string $token): string {
    $scheme = auth_is_https() ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . auth_base_url() . '/access.php?t=' . $token;
}

/** Forgets every phone that was signed in for this patient. */
function auth_revoke_devices(mysqli $db, int $userId): void {
    auth_exec($db, 'DELETE FROM patient_devices WHERE user_id = ?', 'i', [$userId]);
}

/** Uses an access link: signs the phone in and remembers it. Returns false when the link is not valid. */
function auth_use_access_token(string $token): bool {
    if (!preg_match('/^[a-f0-9]{40}$/', $token)) return false;
    $db = getDB();
    $link = auth_row($db, "SELECT l.id, l.user_id FROM access_links l JOIN users u ON u.id = l.user_id WHERE l.token_hash = ? AND l.used_at IS NULL AND l.expires_at > NOW() AND u.active = 1 AND u.role = 'patient'", 's', [auth_hash_token($token)]);
    if (!$link) return false;
    $n = auth_exec($db, 'UPDATE access_links SET used_at = NOW() WHERE id = ? AND used_at IS NULL', 'i', [(int) $link['id']]);
    if (!$n) return false;

    $device = bin2hex(random_bytes(32));
    auth_exec($db, 'INSERT INTO patient_devices (user_id, token_hash, expires_at) VALUES (?,?,(NOW() + INTERVAL 30 DAY))', 'is', [(int) $link['user_id'], auth_hash_token($device)]);
    setcookie('medbot_pt', $device, [
        'expires'  => time() + 30 * 86400,
        'path'     => '/',
        'secure'   => auth_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    auth_start_session();
    session_regenerate_id(true);
    $_SESSION = ['uid' => (int) $link['user_id'], 'started' => time(), 'last_seen' => time()];
    auth_exec($db, 'UPDATE users SET last_login = NOW() WHERE id = ?', 'i', [(int) $link['user_id']]);
    return true;
}

/** Signs out for real: also forgets this phone, so a patient is not signed straight back in. */
function auth_logout(): void {
    if (!empty($_COOKIE['medbot_pt']) && preg_match('/^[a-f0-9]{64}$/', (string) $_COOKIE['medbot_pt'])) {
        auth_exec(getDB(), 'DELETE FROM patient_devices WHERE token_hash = ?', 's', [auth_hash_token((string) $_COOKIE['medbot_pt'])]);
    }
    setcookie('medbot_pt', '', ['expires' => time() - 42000, 'path' => '/', 'secure' => auth_is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    auth_destroy_session();
}

/* ───────────── standalone page chrome (login, setup, password, patient) ───────────── */

function auth_page_head(string $title): void { ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= auth_h($title) ?> — MedBot</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/medbot/assets/style.css">
<style>
.auth-body{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px;background:var(--bg-0)}
.auth-card{width:100%;max-width:420px;background:var(--bg-card);border:1px solid var(--border-grey);border-radius:var(--radius);padding:26px;box-shadow:var(--shadow)}
.auth-logo{display:flex;align-items:center;gap:12px;margin-bottom:18px}
.auth-card h1{font-size:19px;margin:0 0 4px;color:var(--text-1)}
.auth-card p{color:var(--text-2);font-size:13.5px;margin:0 0 14px}
.auth-card .form-group{margin-bottom:12px}
.auth-error{background:rgba(255,77,109,.1);border:1px solid rgba(255,77,109,.35);color:var(--danger);border-radius:var(--radius-sm);padding:10px 12px;font-size:13px;margin-bottom:12px}
.auth-ok{background:rgba(0,212,170,.08);border:1px solid rgba(0,212,170,.3);color:var(--teal);border-radius:var(--radius-sm);padding:10px 12px;font-size:13px;margin-bottom:12px}
.auth-card input{font-size:16px}
.auth-links{margin-top:14px;font-size:12.5px;color:var(--text-3)}
.auth-links a{color:var(--accent)}
</style>
</head>
<body class="auth-body">
<div class="auth-card">
    <div class="auth-logo"><div class="logo-icon">🤖</div><div><div class="logo-text">MedBot</div><div class="logo-sub">Medication Robot</div></div></div>
<?php }

function auth_page_foot(): void { ?>
</div>
</body>
</html>
<?php }

/* ───────────── access rules ───────────── */

function auth_script_path(): string {
    $file = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) ?: '';
    $root = realpath(__DIR__) ?: __DIR__;
    if ($file !== '' && strpos($file, $root . DIRECTORY_SEPARATOR) === 0) {
        return str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($root) + 1));
    }
    return basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
}

function auth_json_error(int $code, string $msg, array $extra = []): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(array_merge(['error' => true, 'message' => $msg], $extra));
    exit;
}

function auth_html_error(int $code, string $title, string $msg, ?array $user): never {
    http_response_code($code);
    auth_page_head($title);
    echo '<h1>' . auth_h($title) . '</h1><p>' . auth_h($msg) . '</p>';
    if ($user) {
        echo '<a class="btn btn-primary" href="' . auth_h(auth_base_url() . '/' . auth_home_path($user)) . '">Go to my home page</a>';
    } else {
        echo '<a class="btn btn-primary" href="' . auth_h(auth_base_url() . '/login.php') . '">Sign in</a>';
    }
    auth_page_foot();
    exit;
}

/** Who may open which page or API. Anything not listed here is for staff only. */
function auth_allowed(string $rel, array $user): bool {
    $role = $user['role'];
    if ($rel === 'change_password.php') return $role !== 'patient';     // patients have no password
    if (in_array($rel, ['patient.php', 'api/me.php', 'api/pay.php'], true)) return $role === 'patient';
    if ($rel === 'api/symptoms.php') return true;               // checked again inside the API
    if ($role === 'patient') return false;                      // patients: nothing else
    if (in_array($rel, ['users.php', 'payments.php', 'insurers.php'], true)) return $role === 'admin';   // admin only
    if (stripos($rel, 'prescription') !== false) {
        if ($role === 'nurse') return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET';   // nurses may look, not change
        return in_array($role, ['doctor', 'admin'], true);
    }
    return in_array($role, AUTH_STAFF, true);
}

function auth_check_origin(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '') return;
    $host = (string) parse_url($origin, PHP_URL_HOST);
    $port = parse_url($origin, PHP_URL_PORT);
    $theirs = $host . ($port ? ':' . $port : '');
    if (strcasecmp($theirs, (string) ($_SERVER['HTTP_HOST'] ?? '')) !== 0) {
        auth_json_error(403, 'Request blocked (it came from another site).');
    }
}

function auth_gate(): void {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');

    if (PHP_SAPI === 'cli' || getenv('AUTH_DISABLED') === '1') return;

    $rel   = auth_script_path();
    $isApi = strpos($rel, 'api/') === 0;

    // Include files are never pages
    if (in_array($rel, ['auth.php', 'gate.php', 'connect.php', 'layout.php', 'billing_lib.php'], true)) {
        http_response_code(404);
        exit;
    }

    // Payment provider callback: not a person, it proves itself with a secret signature
    if ($rel === 'api/payment_webhook.php') return;

    auth_check_origin();

    // Pages anyone can open
    if (in_array($rel, ['login.php', 'logout.php', 'setup.php', 'access.php'], true)) {
        auth_start_session();
        return;
    }

    $user = auth_user();
    if (!$user) {
        if ($isApi) auth_json_error(401, 'Please sign in again.', ['login' => true]);
        if (!auth_has_users()) auth_redirect('setup.php');
        $next = (string) ($_SERVER['REQUEST_URI'] ?? '');
        auth_redirect('login.php' . ($next !== '' && auth_safe_next($next) !== '' ? '?next=' . rawurlencode($next) : ''));
    }

    if ($user['must_change'] === 1 && !in_array($rel, ['change_password.php', 'logout.php'], true)) {
        if ($isApi) auth_json_error(403, 'Please choose a new password first.');
        auth_redirect('change_password.php');
    }

    if (!auth_allowed($rel, $user)) {
        if ($isApi) auth_json_error(403, 'You do not have access to this.');
        if ($user['role'] === 'patient') auth_redirect('patient.php');
        auth_html_error(403, 'No access', 'Your role (' . $user['role'] . ') cannot open this page.', $user);
    }

    // APIs never write to the session, so release it (lets several requests run at once)
    if ($isApi && session_status() === PHP_SESSION_ACTIVE) session_write_close();
}
