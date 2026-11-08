<?php
require_once __DIR__ . '/auth.php';
auth_start_session();

if (!auth_has_users() && getenv('AUTH_DISABLED') !== '1') auth_redirect('setup.php');

$user = auth_user();
if ($user) auth_redirect(auth_home_path($user));

$error = '';
$next  = auth_safe_next($_POST['next'] ?? ($_GET['next'] ?? ''));

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!auth_csrf_ok()) {
        $error = 'This page expired. Please try again.';
    } else {
        [$ok, $msg, $row] = auth_login((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''));
        if ($ok) {
            if ((int) $row['must_change'] === 1) auth_redirect('change_password.php');
            if ($next !== '' && $row['role'] !== 'patient') {
                header('Location: ' . $next);
                exit;
            }
            auth_redirect($row['role'] === 'patient' ? 'patient.php' : 'home.php');
        }
        $error = $msg;
    }
}

auth_page_head('Sign in');
?>
    <h1>Sign in</h1>
    <p>Use the username and password you were given by the hospital.</p>
    <?php if ($error !== ''): ?><div class="auth-error" role="alert"><?= auth_h($error) ?></div><?php endif; ?>
    <form method="post" action="login.php" autocomplete="on">
        <input type="hidden" name="csrf" value="<?= auth_h(auth_csrf()) ?>">
        <input type="hidden" name="next" value="<?= auth_h($next) ?>">
        <div class="form-group">
            <label class="form-label" for="username">Username</label>
            <input id="username" name="username" type="text" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
        </div>
        <div class="form-group">
            <label class="form-label" for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
        </div>
        <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center;padding:11px">Sign in</button>
    </form>
    <div class="auth-links">Staff forgot their password? Ask an administrator.<br><strong>Patients:</strong> there is no password. Open the link or scan the QR code a nurse gave you. If you lost it, ask staff for a new one.<br>Abarwayi: nta jambo ry’ibanga rikenewe. Koresha ihuza cyangwa QR code wahawe n’umuforomo.</div>
<?php auth_page_foot(); ?>
