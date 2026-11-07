<?php
/**
 * First-time setup: creates the FIRST administrator account.
 * Only works while no accounts exist, and only with the secret SETUP_KEY that you set
 * in Render (Environment). Without that key nobody else can claim the system.
 */
require_once __DIR__ . '/auth.php';
auth_start_session();

$db = getDB();
auth_ensure_tables($db);

$setupKey = (string) getenv('SETUP_KEY');
$error = '';
$done  = false;

if (auth_has_users()) {
    auth_page_head('Setup finished');
    echo '<h1>Setup is finished</h1><p>An administrator account already exists.</p><a class="btn btn-primary" href="login.php">Go to sign in</a>';
    auth_page_foot();
    exit;
}

if ($setupKey === '' || strlen($setupKey) < 8) {
    auth_page_head('Setup');
    echo '<h1>One step before setup</h1>';
    echo '<p>In Render, open your web service, go to <strong>Environment</strong>, add a variable named <code>SETUP_KEY</code> with a long secret value (at least 8 characters), save, and wait for the redeploy. Then reload this page.</p>';
    auth_page_foot();
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $key      = (string) ($_POST['setup_key'] ?? '');
    $name     = trim((string) ($_POST['full_name'] ?? ''));
    $username = strtolower(trim((string) ($_POST['username'] ?? '')));
    $pw       = (string) ($_POST['password'] ?? '');
    $pw2      = (string) ($_POST['password2'] ?? '');

    if (!auth_csrf_ok()) {
        $error = 'This page expired. Please try again.';
    } elseif (!hash_equals($setupKey, $key)) {
        sleep(2);
        $error = 'The setup key is not correct.';
    } elseif (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
        $error = 'Enter the administrator’s full name.';
    } elseif (!auth_valid_username($username)) {
        $error = 'Username: 3–40 characters, only lowercase letters, numbers, dot, dash or underscore.';
    } elseif ($pw !== $pw2) {
        $error = 'The two passwords are not the same.';
    } elseif (($pwErr = auth_password_error($pw, $username)) !== null) {
        $error = $pwErr;
    } elseif (!auth_create_user($db, $username, $pw, 'admin', mb_substr(strip_tags($name), 0, 150), null, false, null)) {
        $error = 'Could not create the account. Please try again.';
    } else {
        $done = true;
    }
}

auth_page_head('Setup');
if ($done) {
    echo '<div class="auth-ok">Administrator account created.</div><a class="btn btn-primary" href="login.php">Sign in now</a>';
    auth_page_foot();
    exit;
}
?>
    <h1>First-time setup</h1>
    <p>Create the first administrator. This page stops working as soon as the account exists.</p>
    <?php if ($error !== ''): ?><div class="auth-error" role="alert"><?= auth_h($error) ?></div><?php endif; ?>
    <form method="post" action="setup.php" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= auth_h(auth_csrf()) ?>">
        <div class="form-group"><label class="form-label" for="setup_key">Setup key (from Render)</label><input id="setup_key" name="setup_key" type="password" required></div>
        <div class="form-group"><label class="form-label" for="full_name">Full name</label><input id="full_name" name="full_name" type="text" maxlength="150" required></div>
        <div class="form-group"><label class="form-label" for="username">Username</label><input id="username" name="username" type="text" maxlength="40" autocapitalize="none" spellcheck="false" required></div>
        <div class="form-group"><label class="form-label" for="password">Password</label><input id="password" name="password" type="password" autocomplete="new-password" required><div class="input-hint">At least 8 characters, with a letter and a number.</div></div>
        <div class="form-group"><label class="form-label" for="password2">Repeat password</label><input id="password2" name="password2" type="password" autocomplete="new-password" required></div>
        <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center;padding:11px">Create administrator</button>
    </form>
<?php auth_page_foot(); ?>
