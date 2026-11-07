<?php
require_once 'connect.php';
require_once 'layout.php';

$me = auth_user();
auth_start_session();
$db = getDB();
auth_ensure_tables($db);

$isAdmin     = $me['role'] === 'admin';
$createRoles = $isAdmin ? ['patient', 'nurse', 'doctor', 'admin'] : ['patient'];
$myId        = (int) $me['id'];

/** Loads an account this person is allowed to manage, or null. */
function users_target(mysqli $db, int $id, bool $isAdmin): ?array {
    $row = auth_row($db, 'SELECT id, username, role, full_name, active FROM users WHERE id = ?', 'i', [$id]);
    if (!$row) return null;
    if (!$isAdmin && $row['role'] !== 'patient') return null;
    return $row;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $flash = ['type' => 'error', 'msg' => 'This page expired. Please try again.'];

    if (auth_csrf_ok()) {
        $act = (string) ($_POST['action'] ?? '');

        if ($act === 'create') {
            $role     = (string) ($_POST['role'] ?? '');
            $name     = mb_substr(trim(strip_tags((string) ($_POST['full_name'] ?? ''))), 0, 150);
            $username = strtolower(trim((string) ($_POST['username'] ?? '')));
            $pid      = (int) ($_POST['patient_id'] ?? 0);

            if (!in_array($role, $createRoles, true)) {
                $flash = ['type' => 'error', 'msg' => 'You cannot create that kind of account.'];
            } elseif (mb_strlen($name) < 2) {
                $flash = ['type' => 'error', 'msg' => 'Enter the full name.'];
            } elseif (!auth_valid_username($username)) {
                $flash = ['type' => 'error', 'msg' => 'Username: 3–40 characters, only lowercase letters, numbers, dot, dash or underscore.'];
            } elseif (auth_row($db, 'SELECT id FROM users WHERE username = ?', 's', [$username])) {
                $flash = ['type' => 'error', 'msg' => 'That username is already taken.'];
            } elseif ($role === 'patient' && (!auth_row($db, 'SELECT id FROM patients WHERE id = ?', 'i', [$pid]))) {
                $flash = ['type' => 'error', 'msg' => 'Choose the patient record for this account.'];
            } elseif ($role === 'patient' && auth_row($db, 'SELECT id FROM users WHERE patient_id = ?', 'i', [$pid])) {
                $flash = ['type' => 'error', 'msg' => 'That patient already has an account.'];
            } else {
                $temp = auth_temp_password();
                if (auth_create_user($db, $username, $temp, $role, $name, $role === 'patient' ? $pid : null, true, $myId > 0 ? $myId : null)) {
                    $flash = ['type' => 'ok', 'msg' => 'Account created for ' . $name . '.', 'temp' => [$username, $temp]];
                } else {
                    $flash = ['type' => 'error', 'msg' => 'Could not create the account. Please try again.'];
                }
            }
        } elseif ($act === 'reset') {
            $t = users_target($db, (int) ($_POST['id'] ?? 0), $isAdmin);
            if (!$t) {
                $flash = ['type' => 'error', 'msg' => 'You cannot manage that account.'];
            } elseif ((int) $t['id'] === $myId) {
                $flash = ['type' => 'error', 'msg' => 'Use “Change password” for your own account.'];
            } else {
                $temp = auth_temp_password();
                $hash = password_hash($temp, PASSWORD_DEFAULT);
                auth_exec($db, 'UPDATE users SET password_hash = ?, must_change = 1 WHERE id = ?', 'si', [$hash, (int) $t['id']]);
                auth_exec($db, 'DELETE FROM login_attempts WHERE username = ?', 's', [$t['username']]);
                $flash = ['type' => 'ok', 'msg' => 'New temporary password for ' . $t['full_name'] . '.', 'temp' => [$t['username'], $temp]];
            }
        } elseif ($act === 'toggle') {
            $t = users_target($db, (int) ($_POST['id'] ?? 0), $isAdmin);
            if (!$t) {
                $flash = ['type' => 'error', 'msg' => 'You cannot manage that account.'];
            } elseif ((int) $t['id'] === $myId) {
                $flash = ['type' => 'error', 'msg' => 'You cannot switch off your own account.'];
            } else {
                $newActive = (int) $t['active'] === 1 ? 0 : 1;
                $others = auth_row($db, "SELECT COUNT(*) AS n FROM users WHERE role = 'admin' AND active = 1 AND id <> ?", 'i', [(int) $t['id']]);
                if ($newActive === 0 && $t['role'] === 'admin' && (!$others || (int) $others['n'] < 1)) {
                    $flash = ['type' => 'error', 'msg' => 'There must always be at least one active administrator.'];
                } else {
                    auth_exec($db, 'UPDATE users SET active = ? WHERE id = ?', 'ii', [$newActive, (int) $t['id']]);
                    $flash = ['type' => 'ok', 'msg' => $t['full_name'] . ($newActive ? ' can sign in again.' : ' can no longer sign in.')];
                }
            }
        }
    }
    $_SESSION['flash'] = $flash;
    header('Location: users.php');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$patients = [];
$res = $db->query('SELECT id, name, room_number FROM patients WHERE id NOT IN (SELECT patient_id FROM users WHERE patient_id IS NOT NULL) ORDER BY name');
if ($res) { while ($r = $res->fetch_assoc()) $patients[] = $r; $res->free(); }

$users = [];
$sql = 'SELECT u.id, u.username, u.role, u.full_name, u.active, u.must_change, u.last_login, p.name AS patient_name, p.room_number
        FROM users u LEFT JOIN patients p ON p.id = u.patient_id '
     . ($isAdmin ? '' : "WHERE u.role = 'patient' ")
     . 'ORDER BY u.role, u.full_name';
$res = $db->query($sql);
if ($res) { while ($r = $res->fetch_assoc()) $users[] = $r; $res->free(); }

$csrf = auth_csrf();
renderHead('Accounts', 'users');
renderTopbar('Accounts', $isAdmin ? 'Create and manage staff and patient sign-ins' : 'Sign patients up and reset their passwords');
?>
<div class="page-content">

<?php if ($flash): ?>
    <div class="alert <?= $flash['type'] === 'ok' ? 'alert-success' : 'alert-error' ?>" role="status">
        <span class="alert-icon"><?= $flash['type'] === 'ok' ? '✅' : '⚠️' ?></span>
        <div>
            <?= auth_h($flash['msg']) ?>
            <?php if (!empty($flash['temp'])): ?>
                <div style="margin-top:8px">Username: <strong style="font-family:var(--mono)"><?= auth_h($flash['temp'][0]) ?></strong><br>
                Temporary password: <strong style="font-family:var(--mono);font-size:16px"><?= auth_h($flash['temp'][1]) ?></strong><br>
                <span style="font-size:12px">Give this to the person privately. It is shown only once, and they must choose their own password the first time they sign in.</span></div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

    <div class="card">
        <div class="card-header"><div class="card-title">➕ Create an account</div></div>
        <div class="card-body">
            <form method="post" action="users.php" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= auth_h($csrf) ?>">
                <input type="hidden" name="action" value="create">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label" for="u-role">Role</label>
                        <select id="u-role" name="role">
                            <?php foreach ($createRoles as $r): ?>
                                <option value="<?= auth_h($r) ?>"><?= auth_h(ucfirst($r)) ?><?= $r === 'nurse' ? ' / technician' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" id="u-patient-wrap">
                        <label class="form-label" for="u-patient">Patient record</label>
                        <select id="u-patient" name="patient_id">
                            <option value="">— Choose a patient —</option>
                            <?php foreach ($patients as $p): ?>
                                <option value="<?= (int) $p['id'] ?>" data-name="<?= auth_h($p['name']) ?>"><?= auth_h($p['name']) ?><?= $p['room_number'] ? ' — Room ' . auth_h($p['room_number']) : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="input-hint">Only patients without an account are listed.</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="u-name">Full name</label>
                        <input id="u-name" name="full_name" type="text" maxlength="150" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="u-username">Username</label>
                        <input id="u-username" name="username" type="text" maxlength="40" autocapitalize="none" spellcheck="false" required>
                        <div class="input-hint">Lowercase letters, numbers, dot, dash or underscore.</div>
                    </div>
                </div>
                <div style="margin-top:14px"><button class="btn btn-primary" type="submit">Create account</button></div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><div class="card-title">👥 <?= $isAdmin ? 'All accounts' : 'Patient accounts' ?></div></div>
        <div class="table-wrap">
        <table class="dt">
            <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Last sign-in</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (!$users): ?>
                <tr><td colspan="6" style="text-align:center;color:var(--text-3);padding:24px">No accounts yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= auth_h($u['full_name']) ?><?php if ($u['patient_name']): ?><div style="font-size:11.5px;color:var(--text-3)">Record: <?= auth_h($u['patient_name']) ?><?= $u['room_number'] ? ' · Room ' . auth_h($u['room_number']) : '' ?></div><?php endif; ?></td>
                    <td style="font-family:var(--mono)"><?= auth_h($u['username']) ?></td>
                    <td><span class="badge badge-info"><?= auth_h($u['role']) ?></span></td>
                    <td><?= $u['last_login'] ? auth_h($u['last_login']) : '<span style="color:var(--text-3)">never</span>' ?><?= (int) $u['must_change'] === 1 ? '<div style="font-size:11.5px;color:var(--warning)">must change password</div>' : '' ?></td>
                    <td><span class="badge <?= (int) $u['active'] === 1 ? 'badge-safe' : 'badge-danger' ?>"><?= (int) $u['active'] === 1 ? 'Active' : 'Switched off' ?></span></td>
                    <td>
                        <?php if ((int) $u['id'] === $myId): ?>
                            <span style="color:var(--text-3);font-size:12px">You · <a href="change_password.php" style="color:var(--accent)">change password</a></span>
                        <?php else: ?>
                            <div style="display:flex;gap:6px;flex-wrap:wrap">
                                <form method="post" action="users.php" onsubmit="return confirm('Make a new temporary password for this person?')">
                                    <input type="hidden" name="csrf" value="<?= auth_h($csrf) ?>"><input type="hidden" name="action" value="reset"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                    <button class="btn btn-secondary btn-xs" type="submit">🔑 Reset password</button>
                                </form>
                                <form method="post" action="users.php" onsubmit="return confirm('<?= (int) $u['active'] === 1 ? 'Switch off this account? They will not be able to sign in.' : 'Switch this account on again?' ?>')">
                                    <input type="hidden" name="csrf" value="<?= auth_h($csrf) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                    <button class="btn <?= (int) $u['active'] === 1 ? 'btn-danger' : 'btn-teal' ?> btn-xs" type="submit"><?= (int) $u['active'] === 1 ? '⛔ Switch off' : '✅ Switch on' ?></button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<script>
(function () {
    var role = document.getElementById('u-role'), wrap = document.getElementById('u-patient-wrap');
    var pat = document.getElementById('u-patient'), name = document.getElementById('u-name'), user = document.getElementById('u-username');
    function sync() { wrap.style.display = role.value === 'patient' ? '' : 'none'; }
    role.addEventListener('change', sync); sync();
    pat.addEventListener('change', function () {
        var opt = pat.options[pat.selectedIndex];
        if (!opt || !opt.dataset.name) return;
        if (!name.value) name.value = opt.dataset.name;
        if (!user.value) user.value = opt.dataset.name.toLowerCase().replace(/[^a-z0-9]+/g, '.').replace(/^\.+|\.+$/g, '').slice(0, 40);
    });
})();
</script>
<?php renderFoot(); ?>
