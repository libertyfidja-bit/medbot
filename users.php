<?php
require_once 'connect.php';
require_once 'layout.php';

// Only administrators can open this page (enforced by the gate as well)
$me = auth_user();
auth_start_session();
$db = getDB();
auth_ensure_tables($db);
$myId = (int) $me['id'];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $flash = ['type' => 'error', 'msg' => 'This page expired. Please try again.'];

    if ($me['role'] === 'admin' && auth_csrf_ok()) {
        $act = (string) ($_POST['action'] ?? '');

        if ($act === 'create') {
            $role     = (string) ($_POST['role'] ?? '');
            $name     = mb_substr(trim(strip_tags((string) ($_POST['full_name'] ?? ''))), 0, 150);
            $username = strtolower(trim((string) ($_POST['username'] ?? '')));
            if (!in_array($role, ['nurse', 'doctor', 'admin'], true)) {
                $flash = ['type' => 'error', 'msg' => 'Choose nurse, doctor or admin. Patients are registered from “Register patient”.'];
            } elseif (mb_strlen($name) < 2) {
                $flash = ['type' => 'error', 'msg' => 'Enter the full name.'];
            } elseif (!auth_valid_username($username)) {
                $flash = ['type' => 'error', 'msg' => 'Username: 3–40 characters, only lowercase letters, numbers, dot, dash or underscore.'];
            } elseif (auth_row($db, 'SELECT id FROM users WHERE username = ?', 's', [$username])) {
                $flash = ['type' => 'error', 'msg' => 'That username is already taken.'];
            } else {
                $temp = auth_temp_password();
                if (auth_create_user($db, $username, $temp, $role, $name, null, true, $myId > 0 ? $myId : null)) {
                    $flash = ['type' => 'ok', 'msg' => 'Account created for ' . $name . '.', 'temp' => [$username, $temp]];
                } else {
                    $flash = ['type' => 'error', 'msg' => 'Could not create the account. Please try again.'];
                }
            }
        } elseif ($act === 'reset' || $act === 'toggle') {
            $t = auth_row($db, 'SELECT id, username, role, full_name, active FROM users WHERE id = ?', 'i', [(int) ($_POST['id'] ?? 0)]);
            if (!$t) {
                $flash = ['type' => 'error', 'msg' => 'Account not found.'];
            } elseif ((int) $t['id'] === $myId) {
                $flash = ['type' => 'error', 'msg' => 'You cannot do this to your own account here. Use “Change password”.'];
            } elseif ($act === 'reset' && $t['role'] === 'patient') {
                // Patients have no password: make a fresh access link and forget their old phones
                auth_revoke_devices($db, (int) $t['id']);
                $token = auth_new_access_link($db, (int) $t['id'], $myId > 0 ? $myId : null);
                $flash = ['type' => 'ok', 'msg' => 'New access link for ' . $t['full_name'] . '. Their old phones were signed out.', 'link' => auth_access_url($token), 'who' => $t['full_name']];
            } elseif ($act === 'reset') {
                $temp = auth_temp_password();
                auth_exec($db, 'UPDATE users SET password_hash = ?, must_change = 1 WHERE id = ?', 'si', [password_hash($temp, PASSWORD_DEFAULT), (int) $t['id']]);
                auth_exec($db, 'DELETE FROM login_attempts WHERE username = ?', 's', [$t['username']]);
                $flash = ['type' => 'ok', 'msg' => 'New temporary password for ' . $t['full_name'] . '.', 'temp' => [$t['username'], $temp]];
            } else {
                $newActive = (int) $t['active'] === 1 ? 0 : 1;
                $others = auth_row($db, "SELECT COUNT(*) AS n FROM users WHERE role = 'admin' AND active = 1 AND id <> ?", 'i', [(int) $t['id']]);
                if ($newActive === 0 && $t['role'] === 'admin' && (!$others || (int) $others['n'] < 1)) {
                    $flash = ['type' => 'error', 'msg' => 'There must always be at least one active administrator.'];
                } else {
                    auth_exec($db, 'UPDATE users SET active = ? WHERE id = ?', 'ii', [$newActive, (int) $t['id']]);
                    if ($newActive === 0) auth_revoke_devices($db, (int) $t['id']);
                    $flash = ['type' => 'ok', 'msg' => $t['full_name'] . ($newActive ? ' can sign in again.' : ' can no longer sign in.')];
                }
            }
        }
    } elseif ($me['role'] !== 'admin') {
        $flash = ['type' => 'error', 'msg' => 'Only an administrator can manage accounts.'];
    }
    $_SESSION['flash'] = $flash;
    header('Location: users.php');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$users = [];
$res = $db->query('SELECT u.id, u.username, u.role, u.full_name, u.active, u.must_change, u.last_login, p.name AS patient_name, p.room_number
                   FROM users u LEFT JOIN patients p ON p.id = u.patient_id ORDER BY u.role, u.full_name');
if ($res) { while ($r = $res->fetch_assoc()) $users[] = $r; $res->free(); }
$csrf = auth_csrf();

renderHead('Accounts', 'users');
renderTopbar('Accounts', 'Staff sign-ins and patient access (administrator only)');
?>
<div class="page-content">

<?php if ($flash): ?>
    <div class="alert <?= $flash['type'] === 'ok' ? 'alert-success' : 'alert-error' ?>" role="status">
        <span class="alert-icon"><?= $flash['type'] === 'ok' ? '✅' : '⚠️' ?></span>
        <div style="flex:1;min-width:0">
            <?= auth_h($flash['msg']) ?>
            <?php if (!empty($flash['temp'])): ?>
                <div style="margin-top:8px">Username: <strong style="font-family:var(--mono)"><?= auth_h($flash['temp'][0]) ?></strong><br>
                Temporary password: <strong style="font-family:var(--mono);font-size:16px"><?= auth_h($flash['temp'][1]) ?></strong><br>
                <span style="font-size:12px">Give this to the person privately. It is shown only once, and they must choose their own password the first time they sign in.</span></div>
            <?php endif; ?>
            <?php if (!empty($flash['link'])): ?>
                <div style="margin-top:10px;display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start">
                    <div id="qr" style="background:#fff;padding:8px;border-radius:8px;width:176px;height:176px"></div>
                    <div style="flex:1;min-width:220px">
                        Give <strong><?= auth_h($flash['who']) ?></strong> this QR code or link. It works once and expires in 72 hours.
                        <input id="access-link" type="text" readonly value="<?= auth_h($flash['link']) ?>" style="margin-top:8px;font-family:var(--mono);font-size:12px" onclick="this.select()">
                        <div style="margin-top:8px"><button type="button" class="btn btn-secondary btn-sm" id="copy-link">📋 Copy link</button></div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

    <div class="card">
        <div class="card-header"><div class="card-title">➕ Create a staff account</div></div>
        <div class="card-body">
            <form method="post" action="users.php" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= auth_h($csrf) ?>"><input type="hidden" name="action" value="create">
                <div class="form-grid">
                    <div class="form-group"><label class="form-label" for="u-role">Role</label>
                        <select id="u-role" name="role"><option value="nurse">Nurse / technician</option><option value="doctor">Doctor</option><option value="admin">Admin</option></select></div>
                    <div class="form-group"><label class="form-label" for="u-name">Full name</label><input id="u-name" name="full_name" type="text" maxlength="150" required></div>
                    <div class="form-group"><label class="form-label" for="u-username">Username</label><input id="u-username" name="username" type="text" maxlength="40" autocapitalize="none" spellcheck="false" required><div class="input-hint">Lowercase letters, numbers, dot, dash or underscore.</div></div>
                </div>
                <div style="margin-top:14px"><button class="btn btn-primary" type="submit">Create account</button></div>
            </form>
            <div style="margin-top:10px;font-size:12.5px;color:var(--text-3)">Patients are registered by doctors and nurses from <a href="register_patient.php" style="color:var(--accent)">Register patient</a>. Only you, the administrator, can make a new patient link, reset a password, or switch an account off.</div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><div class="card-title">👥 All accounts</div></div>
        <div class="table-wrap">
        <table class="dt">
            <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Last sign-in</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (!$users): ?><tr><td colspan="6" style="text-align:center;color:var(--text-3);padding:24px">No accounts yet.</td></tr><?php endif; ?>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= auth_h($u['full_name']) ?><?php if ($u['patient_name']): ?><div style="font-size:11.5px;color:var(--text-3)">Patient record<?= $u['room_number'] ? ' · Room ' . auth_h($u['room_number']) : '' ?></div><?php endif; ?></td>
                    <td style="font-family:var(--mono)"><?= $u['role'] === 'patient' ? '<span style="color:var(--text-3)">link / QR</span>' : auth_h($u['username']) ?></td>
                    <td><span class="badge badge-info"><?= auth_h($u['role']) ?></span></td>
                    <td><?= $u['last_login'] ? auth_h($u['last_login']) : '<span style="color:var(--text-3)">never</span>' ?><?= (int) $u['must_change'] === 1 ? '<div style="font-size:11.5px;color:var(--warning)">must change password</div>' : '' ?></td>
                    <td><span class="badge <?= (int) $u['active'] === 1 ? 'badge-safe' : 'badge-danger' ?>"><?= (int) $u['active'] === 1 ? 'Active' : 'Switched off' ?></span></td>
                    <td>
                        <?php if ((int) $u['id'] === $myId): ?>
                            <span style="color:var(--text-3);font-size:12px">You · <a href="change_password.php" style="color:var(--accent)">change password</a></span>
                        <?php else: ?>
                            <div style="display:flex;gap:6px;flex-wrap:wrap">
                                <form method="post" action="users.php" onsubmit="return confirm('<?= $u['role'] === 'patient' ? 'Make a new access link? Their old phones will be signed out.' : 'Make a new temporary password for this person?' ?>')">
                                    <input type="hidden" name="csrf" value="<?= auth_h($csrf) ?>"><input type="hidden" name="action" value="reset"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                    <button class="btn btn-secondary btn-xs" type="submit"><?= $u['role'] === 'patient' ? '🔗 New access link' : '🔑 Reset password' ?></button>
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

<?php if ($flash && !empty($flash['link'])): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
(function () {
    var link = document.getElementById('access-link').value;
    try { new QRCode(document.getElementById('qr'), { text: link, width: 160, height: 160, correctLevel: QRCode.CorrectLevel.M }); }
    catch (e) { document.getElementById('qr').textContent = 'QR unavailable — use the link.'; }
    document.getElementById('copy-link').addEventListener('click', function () {
        var i = document.getElementById('access-link'); i.select();
        try { navigator.clipboard.writeText(i.value); } catch (e) { document.execCommand('copy'); }
        this.textContent = '✅ Copied';
    });
})();
</script>
<?php endif; ?>
<?php renderFoot(); ?>
