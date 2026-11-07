<?php
require_once __DIR__ . '/auth.php';
auth_start_session();

$user = auth_user();
if (!$user) auth_redirect('login.php');
$forced = (int) $user['must_change'] === 1;

$error = '';
$done  = false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $current = (string) ($_POST['current'] ?? '');
    $new     = (string) ($_POST['new'] ?? '');
    $new2    = (string) ($_POST['new2'] ?? '');
    $db      = getDB();
    $row     = auth_row($db, 'SELECT password_hash FROM users WHERE id = ?', 'i', [(int) $user['id']]);

    if (!auth_csrf_ok()) {
        $error = 'This page expired. Please try again.';
    } elseif (!$row || !password_verify($current, $row['password_hash'])) {
        $error = 'Your current password is not correct.';
    } elseif ($new !== $new2) {
        $error = 'The two new passwords are not the same.';
    } elseif ($new === $current) {
        $error = 'Choose a password different from the old one.';
    } elseif (($pwErr = auth_password_error($new, (string) $user['username'])) !== null) {
        $error = $pwErr;
    } else {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        auth_exec($db, 'UPDATE users SET password_hash = ?, must_change = 0 WHERE id = ?', 'si', [$hash, (int) $user['id']]);
        session_regenerate_id(true);
        $done = true;
    }
}

auth_page_head('Change password');
if ($done) {
    echo '<div class="auth-ok">Your password was changed.</div>';
    echo '<a class="btn btn-primary" href="' . auth_h(auth_home_path($user)) . '">Continue</a>';
    auth_page_foot();
    exit;
}
?>
    <h1>Change password</h1>
    <p><?= $forced ? 'You are using a temporary password. Please choose your own before you continue.' : 'Choose a new password.' ?></p>
    <?php if ($error !== ''): ?><div class="auth-error" role="alert"><?= auth_h($error) ?></div><?php endif; ?>
    <form method="post" action="change_password.php" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= auth_h(auth_csrf()) ?>">
        <div class="form-group"><label class="form-label" for="current"><?= $forced ? 'Temporary password' : 'Current password' ?></label><input id="current" name="current" type="password" autocomplete="current-password" required autofocus></div>
        <div class="form-group"><label class="form-label" for="new">New password</label><input id="new" name="new" type="password" autocomplete="new-password" required><div class="input-hint">At least 8 characters, with a letter and a number.</div></div>
        <div class="form-group"><label class="form-label" for="new2">Repeat new password</label><input id="new2" name="new2" type="password" autocomplete="new-password" required></div>
        <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center;padding:11px">Save new password</button>
    </form>
    <div class="auth-links"><?php if (!$forced): ?><a href="<?= auth_h(auth_home_path($user)) ?>">Cancel</a> · <?php endif; ?><a href="logout.php">Sign out</a></div>
<?php auth_page_foot(); ?>
