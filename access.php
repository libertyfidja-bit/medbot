<?php
/**
 * Patient access: no password. A nurse or doctor gives the patient a one-time link or QR code
 * when registering them. Opening it signs this phone in and remembers it for 30 days.
 * A link works once and expires after 72 hours; staff can make a new one if it is lost.
 */
require_once __DIR__ . '/auth.php';
auth_start_session();

$token = (string) ($_GET['t'] ?? '');

if ($token !== '' && auth_use_access_token($token)) {
    auth_redirect('patient.php');
}

// Already signed in on this phone? Just go to the portal.
$user = auth_user();
if ($user && $user['role'] === 'patient') auth_redirect('patient.php');

http_response_code(410);
auth_page_head('Link not valid');
?>
    <h1>This link cannot be used</h1>
    <p>It may have been used already, or it has expired. Please ask a nurse or doctor to give you a new link or QR code.</p>
    <p style="font-size:12.5px">Seul un lien ou un code QR valide permet d’ouvrir votre espace. Demandez-en un nouveau à l’infirmière.<br>
    Ikoresha ihuza cyangwa QR code nshya. Saba umuforomo cyangwa umuganga kuguha indi.</p>
<?php auth_page_foot(); ?>
