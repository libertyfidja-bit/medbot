<?php
require_once 'connect.php';
require_once 'layout.php';
require_once __DIR__ . '/billing_lib.php';

$me = auth_user();
auth_start_session();
$db = getDB();
auth_ensure_tables($db);
bill_boot($db);
$myId    = (int) $me['id'];
$isAdmin = $me['role'] === 'admin';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $flash = ['type' => 'error', 'msg' => 'This page expired. Please try again.'];
    if (auth_csrf_ok() && $isAdmin && (string) ($_POST['action'] ?? '') === 'send') {
        $to      = (int) ($_POST['to_user'] ?? 0);
        $subject = mb_substr(trim(strip_tags((string) ($_POST['subject'] ?? ''))), 0, 150);
        $body    = mb_substr(trim(strip_tags((string) ($_POST['body'] ?? ''))), 0, 2000);
        if (!auth_row($db, "SELECT id FROM users WHERE id = ? AND active = 1 AND role IN ('doctor','nurse','admin')", 'i', [$to])) {
            $flash = ['type' => 'error', 'msg' => 'Choose who receives the message.'];
        } elseif ($subject === '' || $body === '') {
            $flash = ['type' => 'error', 'msg' => 'Write a subject and a message.'];
        } else {
            msg_send($db, $myId > 0 ? $myId : null, $to, $subject, $body);
            $flash = ['type' => 'ok', 'msg' => 'Message sent.'];
        }
    }
    $_SESSION['flash'] = $flash;
    header('Location: messages.php');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$msgs = [];
$stmt = $db->prepare('SELECT m.id, m.subject, m.body, m.is_auto, m.created_at, m.read_at, u.full_name AS sender
                      FROM messages m LEFT JOIN users u ON u.id = m.from_user WHERE m.to_user = ? ORDER BY m.id DESC LIMIT 60');
if ($stmt) {
    $stmt->bind_param('i', $myId);
    if ($stmt->execute()) {
        $r = $stmt->get_result();
        while ($r && ($row = $r->fetch_assoc())) $msgs[] = $row;
    }
    $stmt->close();
}
// Opening the inbox marks everything as read (the page still shows which ones were new)
auth_exec($db, 'UPDATE messages SET read_at = NOW() WHERE to_user = ? AND read_at IS NULL', 'i', [$myId]);

$staff = $isAdmin ? bill_staff($db) : [];
$csrf  = auth_csrf();

renderHead('Messages', 'messages');
renderTopbar('Messages', 'Payment notices and messages from the administrator');
?>
<div class="page-content">

<?php if ($flash): ?>
    <div class="alert <?= $flash['type'] === 'ok' ? 'alert-success' : 'alert-error' ?>" role="status"><span class="alert-icon"><?= $flash['type'] === 'ok' ? '✅' : '⚠️' ?></span><div><?= auth_h($flash['msg']) ?></div></div>
<?php endif; ?>

<?php if ($isAdmin): ?>
    <div class="card">
        <div class="card-header"><div class="card-title">✉️ Send a direct message</div></div>
        <div class="card-body">
            <form method="post" action="messages.php" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= auth_h($csrf) ?>"><input type="hidden" name="action" value="send">
                <div class="form-grid">
                    <div class="form-group"><label class="form-label" for="m-to">To</label>
                        <select id="m-to" name="to_user" required><option value="">— Choose —</option>
                            <?php foreach ($staff as $s): if ((int) $s['id'] === $myId) continue; ?>
                                <option value="<?= (int) $s['id'] ?>"><?= auth_h($s['full_name']) ?> (<?= auth_h($s['role']) ?>)</option>
                            <?php endforeach; ?>
                        </select></div>
                    <div class="form-group"><label class="form-label" for="m-sub">Subject</label><input id="m-sub" name="subject" type="text" maxlength="150" required></div>
                    <div class="form-group full"><label class="form-label" for="m-body">Message</label><textarea id="m-body" name="body" rows="3" maxlength="2000" required></textarea></div>
                </div>
                <div style="margin-top:12px"><button class="btn btn-primary" type="submit">Send</button></div>
            </form>
        </div>
    </div>
<?php endif; ?>

    <div class="card">
        <div class="card-header"><div class="card-title">📥 My messages</div></div>
        <?php if (!$msgs): ?><div style="padding:28px;text-align:center;color:var(--text-3)">No messages yet.</div><?php endif; ?>
        <?php foreach ($msgs as $m): ?>
            <div style="padding:14px 18px;border-bottom:1px solid var(--border);<?= $m['read_at'] === null ? 'background:rgba(124,109,255,.07)' : '' ?>">
                <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap">
                    <strong style="color:var(--text-1)"><?= $m['read_at'] === null ? '🔵 ' : '' ?><?= auth_h($m['subject']) ?></strong>
                    <span style="font-size:12px;color:var(--text-3)"><?= $m['is_auto'] ? '🤖 automatic' : 'from ' . auth_h($m['sender'] ?? 'unknown') ?> · <?= auth_h($m['created_at']) ?></span>
                </div>
                <div style="margin-top:6px;font-size:13.5px;color:var(--text-2);white-space:pre-wrap;word-break:break-word"><?= auth_h($m['body']) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php renderFoot(); ?>
