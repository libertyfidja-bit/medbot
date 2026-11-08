<?php
require_once 'connect.php';
require_once 'layout.php';
require_once __DIR__ . '/billing_lib.php';

$me = auth_user();
auth_start_session();
$db = getDB();
auth_ensure_tables($db);
bill_boot($db);
$myId = (int) $me['id'];
$mode = bill_mode();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $flash = ['type' => 'error', 'msg' => 'This page expired. Please try again.'];

    if (auth_csrf_ok()) {
        $act = (string) ($_POST['action'] ?? '');
        $pid = (int) ($_POST['payment_id'] ?? 0);
        $pay = auth_row($db, 'SELECT p.*, pt.name AS patient_name, pp.phone AS patient_phone FROM payments p JOIN patients pt ON pt.id = p.patient_id LEFT JOIN patient_profile pp ON pp.patient_id = p.patient_id WHERE p.id = ?', 'i', [$pid]);

        if ($act === 'simulate') {
            // Sandbox only: pretends the provider confirmed the payment
            if ($mode !== 'sandbox') {
                $flash = ['type' => 'error', 'msg' => 'Simulation is only available in sandbox mode.'];
            } elseif (!$pay || $pay['status'] !== 'pending' || $pay['method'] === 'cash') {
                $flash = ['type' => 'error', 'msg' => 'This payment cannot be simulated.'];
            } else {
                $wrong = !empty($_POST['wrong_payer']);
                $payer = $wrong ? '0780000000' : ($pay['payer_phone'] ?: $pay['patient_phone']);
                [$ok, $msg] = bill_confirm_payment($db, $pid, (string) $payer, 'SANDBOX-' . strtoupper(bin2hex(random_bytes(3))));
                $flash = ['type' => $ok ? 'ok' : 'error', 'msg' => $msg];
            }
        } elseif ($act === 'confirm_cash') {
            if (!$pay || $pay['status'] !== 'pending' || $pay['method'] !== 'cash') {
                $flash = ['type' => 'error', 'msg' => 'This is not a pending cash payment.'];
            } else {
                [$ok, $msg] = bill_confirm_payment($db, $pid, null, 'CASH-' . $myId);
                $flash = ['type' => $ok ? 'ok' : 'error', 'msg' => $msg];
            }
        } elseif ($act === 'reject') {
            if ($pay && $pay['status'] === 'pending') {
                bill_fail_payment($db, $pid, 'Rejected by ' . $me['full_name']);
                $flash = ['type' => 'ok', 'msg' => 'Payment marked as failed. The patient can try again.'];
            } else {
                $flash = ['type' => 'error', 'msg' => 'Only a pending payment can be rejected.'];
            }
        } elseif ($act === 'message') {
            $to   = (int) ($_POST['to_user'] ?? 0);
            $text = mb_substr(trim(strip_tags((string) ($_POST['body'] ?? ''))), 0, 2000);
            if (!$pay) {
                $flash = ['type' => 'error', 'msg' => 'Payment not found.'];
            } elseif ($text === '') {
                $flash = ['type' => 'error', 'msg' => 'Write a message first.'];
            } elseif (!auth_row($db, "SELECT id FROM users WHERE id = ? AND active = 1 AND role IN ('doctor','nurse','admin')", 'i', [$to])) {
                $flash = ['type' => 'error', 'msg' => 'Choose who receives the message.'];
            } else {
                msg_send($db, $myId > 0 ? $myId : null, $to, 'About payment — ' . $pay['patient_name'], $text, (int) $pay['invoice_id'], $pid, false);
                $flash = ['type' => 'ok', 'msg' => 'Message sent.'];
            }
        }
    }
    $_SESSION['flash'] = $flash;
    header('Location: payments.php');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$rows = [];
$res = $db->query('SELECT p.id, p.invoice_id, p.method, p.amount, p.payer_phone, p.status, p.payer_check, p.reference, p.mode, p.failure_note, p.created_at, p.confirmed_at,
                          pt.name AS patient_name, pp.phone AS patient_phone, v.total_amount, v.insurance_amount, v.insurer_name
                   FROM payments p JOIN patients pt ON pt.id = p.patient_id LEFT JOIN patient_profile pp ON pp.patient_id = p.patient_id JOIN invoices v ON v.id = p.invoice_id
                   ORDER BY (p.status = \'pending\') DESC, p.id DESC LIMIT 60');
if ($res) { while ($r = $res->fetch_assoc()) $rows[] = $r; $res->free(); }

// who provided the services on each invoice
$providers = [];
if ($rows) {
    $ids = implode(',', array_map(fn($r) => (int) $r['invoice_id'], $rows));
    $res = $db->query('SELECT DISTINCT invoice_id, provided_by, provided_by_name FROM invoice_items WHERE provided_by IS NOT NULL AND invoice_id IN (' . $ids . ')');
    if ($res) { while ($r = $res->fetch_assoc()) $providers[(int) $r['invoice_id']][(int) $r['provided_by']] = $r['provided_by_name']; $res->free(); }
}
$staff = bill_staff($db);

$counts = ['pending' => 0, 'mismatch' => 0, 'confirmed' => 0];
foreach ($rows as $r) {
    if ($r['status'] === 'pending') $counts['pending']++;
    if ($r['status'] === 'confirmed') $counts['confirmed']++;
    if ($r['payer_check'] === 'mismatch') $counts['mismatch']++;
}

$csrf = auth_csrf();
$statusBadge = ['pending' => 'badge-warning', 'confirmed' => 'badge-safe', 'failed' => 'badge-danger'];
$checkBadge  = ['match' => ['✅ Payer verified', 'badge-safe'], 'mismatch' => ['⚠️ Different number', 'badge-danger'], 'unchecked' => ['— not compared', 'badge-gray']];

renderHead('Payments', 'payments');
renderTopbar('Payments', 'Verification of what patients pay');
?>
<div class="page-content">

<?php if ($mode === 'sandbox'): ?>
    <div class="alert alert-warning" role="status"><span class="alert-icon">🧪</span><div><strong>Sandbox mode.</strong> No real money moves. You can simulate the provider’s confirmation below. To take real payments, a payment provider must be connected and <code>PAYMENT_MODE</code> set to <code>live</code>.</div></div>
<?php endif; ?>

<?php if ($flash): ?>
    <div class="alert <?= $flash['type'] === 'ok' ? 'alert-success' : 'alert-error' ?>" role="status"><span class="alert-icon"><?= $flash['type'] === 'ok' ? '✅' : '⚠️' ?></span><div><?= auth_h($flash['msg']) ?></div></div>
<?php endif; ?>

    <div class="stats-grid">
        <div class="stat-card"><div class="stat-icon">⏳</div><div class="stat-value"><?= $counts['pending'] ?></div><div class="stat-label">Waiting for confirmation</div></div>
        <div class="stat-card"><div class="stat-icon">✅</div><div class="stat-value teal"><?= $counts['confirmed'] ?></div><div class="stat-label">Confirmed (latest 60)</div></div>
        <div class="stat-card"><div class="stat-icon">⚠️</div><div class="stat-value danger"><?= $counts['mismatch'] ?></div><div class="stat-label">Payer number differs</div></div>
    </div>

<?php if (!$rows): ?>
    <div class="card"><div class="card-body" style="text-align:center;color:var(--text-3);padding:32px">No payments yet.</div></div>
<?php endif; ?>

<?php foreach ($rows as $r):
    $prov = $providers[(int) $r['invoice_id']] ?? [];
    $chk  = $checkBadge[$r['payer_check']] ?? $checkBadge['unchecked']; ?>
    <div class="card">
        <div class="card-header">
            <div class="card-title"><?= auth_h($r['patient_name']) ?> <span style="color:var(--text-3);font-weight:400">· invoice #<?= (int) $r['invoice_id'] ?></span></div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                <span class="badge <?= $statusBadge[$r['status']] ?? 'badge-gray' ?>"><?= auth_h($r['status']) ?></span>
                <span class="badge <?= $chk[1] ?>"><?= $chk[0] ?></span>
                <?php if ($r['mode'] === 'sandbox'): ?><span class="badge badge-gray">sandbox</span><?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;font-size:13px;color:var(--text-2)">
                <div>Patient paid<br><strong style="font-size:16px;color:var(--text-1)"><?= auth_h(bill_rwf((int) $r['amount'])) ?></strong></div>
                <div>Method<br><strong style="color:var(--text-1)"><?= auth_h(BILL_METHODS[$r['method']] ?? $r['method']) ?></strong></div>
                <div>Paying number<br><strong style="font-family:var(--mono);color:var(--text-1)"><?= auth_h(bill_mask_phone(bill_phone($r['payer_phone']))) ?: '—' ?></strong></div>
                <div>Registered number<br><strong style="font-family:var(--mono);color:var(--text-1)"><?= auth_h(bill_mask_phone(bill_phone($r['patient_phone']))) ?: '—' ?></strong></div>
                <div>Invoice total<br><strong style="color:var(--text-1)"><?= auth_h(bill_rwf((int) $r['total_amount'])) ?></strong><br><span style="font-size:11.5px"><?= auth_h($r['insurer_name']) ?> paid share: <?= auth_h(bill_rwf((int) $r['insurance_amount'])) ?></span></div>
                <div>Provided by<br><strong style="color:var(--text-1)"><?= $prov ? auth_h(implode(', ', $prov)) : '—' ?></strong></div>
                <div>Reference<br><span style="font-family:var(--mono)"><?= auth_h($r['reference']) ?></span><br><span style="font-size:11.5px"><?= auth_h($r['created_at']) ?></span></div>
            </div>
            <?php if ($r['failure_note']): ?><div style="margin-top:8px;font-size:12.5px;color:var(--danger)"><?= auth_h($r['failure_note']) ?></div><?php endif; ?>

            <?php if ($r['status'] === 'pending'): ?>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
                    <?php if ($r['method'] === 'cash'): ?>
                        <form method="post" action="payments.php"><input type="hidden" name="csrf" value="<?= auth_h($csrf) ?>"><input type="hidden" name="action" value="confirm_cash"><input type="hidden" name="payment_id" value="<?= (int) $r['id'] ?>"><button class="btn btn-teal btn-sm" type="submit">💵 Cash received — confirm</button></form>
                    <?php elseif ($mode === 'sandbox'): ?>
                        <form method="post" action="payments.php" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center"><input type="hidden" name="csrf" value="<?= auth_h($csrf) ?>"><input type="hidden" name="action" value="simulate"><input type="hidden" name="payment_id" value="<?= (int) $r['id'] ?>">
                            <button class="btn btn-teal btn-sm" type="submit">🧪 Simulate provider confirmation</button>
                            <label style="font-size:12px;display:flex;gap:6px;align-items:center"><input type="checkbox" name="wrong_payer" value="1" style="width:auto"> simulate a different payer number</label></form>
                    <?php endif; ?>
                    <form method="post" action="payments.php" onsubmit="return confirm('Mark this payment as failed?')"><input type="hidden" name="csrf" value="<?= auth_h($csrf) ?>"><input type="hidden" name="action" value="reject"><input type="hidden" name="payment_id" value="<?= (int) $r['id'] ?>"><button class="btn btn-danger btn-sm" type="submit">Reject</button></form>
                </div>
            <?php endif; ?>

            <form method="post" action="payments.php" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;align-items:center">
                <input type="hidden" name="csrf" value="<?= auth_h($csrf) ?>"><input type="hidden" name="action" value="message"><input type="hidden" name="payment_id" value="<?= (int) $r['id'] ?>">
                <select name="to_user" style="flex:0 1 220px" aria-label="Send to" required>
                    <option value="">Send message to…</option>
                    <?php foreach ($staff as $s): ?>
                        <option value="<?= (int) $s['id'] ?>"<?= isset($prov[(int) $s['id']]) ? ' selected' : '' ?>><?= auth_h($s['full_name']) ?> (<?= auth_h($s['role']) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <input name="body" type="text" maxlength="2000" placeholder="Direct message about this payment" style="flex:1 1 240px" required>
                <button class="btn btn-secondary btn-sm" type="submit">✉️ Send</button>
            </form>
        </div>
    </div>
<?php endforeach; ?>
</div>
<?php renderFoot(); ?>
