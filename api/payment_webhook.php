<?php
/**
 * MedBot — payment provider callback              (place this file at: api/payment_webhook.php)
 *
 * The payment provider (MTN MoMo, Airtel Money, IremboPay, Flutterwave, an aggregator...) calls this
 * address when a patient approves or refuses a payment. It is NOT used by people, so it has no
 * sign-in; instead every request must carry a valid signature:
 *
 *   header  X-Signature: <hex HMAC-SHA256 of the raw request body, using PAYMENT_WEBHOOK_SECRET>
 *   body    {"reference":"MB240601A1B2C3","status":"success","payer_phone":"0781234567",
 *            "provider_txn":"123456789","amount":5000}
 *
 * Set PAYMENT_WEBHOOK_SECRET (at least 16 random characters) in Render. Without it this endpoint
 * refuses everything. Each provider signs differently, so when you connect a real gateway a small
 * adapter turns its callback into the format above (ask for it when you have your merchant keys).
 */
require_once __DIR__ . '/../connect.php';
require_once __DIR__ . '/../billing_lib.php';
mysqli_report(MYSQLI_REPORT_OFF);
header('Content-Type: application/json');
header('Cache-Control: no-store');

function hook_out(int $code, array $data): never {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

$secret = (string) getenv('PAYMENT_WEBHOOK_SECRET');
if (strlen($secret) < 16) hook_out(503, ['error' => true, 'message' => 'The payment callback is not configured.']);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') hook_out(405, ['error' => true, 'message' => 'POST only.']);

$raw = (string) file_get_contents('php://input');
if ($raw === '' || strlen($raw) > 20000) hook_out(400, ['error' => true, 'message' => 'Bad request.']);

$sent = strtolower(trim((string) ($_SERVER['HTTP_X_SIGNATURE'] ?? '')));
if ($sent === '' || !hash_equals(hash_hmac('sha256', $raw, $secret), $sent)) {
    hook_out(401, ['error' => true, 'message' => 'Invalid signature.']);
}

$data = json_decode($raw, true);
if (!is_array($data)) hook_out(400, ['error' => true, 'message' => 'Bad JSON.']);

$ref = (string) ($data['reference'] ?? '');
if (!preg_match('/^MB[0-9]{6}[0-9A-F]{6}$/', $ref)) hook_out(400, ['error' => true, 'message' => 'Bad reference.']);

try {
    $db = getDB();
    mysqli_report(MYSQLI_REPORT_OFF);
    bill_boot($db);

    $pay = auth_row($db, 'SELECT id, amount, status FROM payments WHERE reference = ?', 's', [$ref]);
    if (!$pay) hook_out(404, ['error' => true, 'message' => 'Unknown reference.']);
    if ($pay['status'] !== 'pending') hook_out(200, ['ok' => true, 'note' => 'Already ' . $pay['status'] . '.']);   // safe to repeat

    $status = strtolower((string) ($data['status'] ?? ''));
    if ($status === 'success') {
        if (isset($data['amount']) && (int) $data['amount'] !== (int) $pay['amount']) {
            bill_fail_payment($db, (int) $pay['id'], 'Amount received (' . (int) $data['amount'] . ') did not match the amount due (' . (int) $pay['amount'] . ').');
            hook_out(409, ['error' => true, 'message' => 'Amount mismatch.']);
        }
        [$ok, $msg] = bill_confirm_payment(
            $db,
            (int) $pay['id'],
            isset($data['payer_phone']) ? (string) $data['payer_phone'] : null,
            isset($data['provider_txn']) ? mb_substr((string) $data['provider_txn'], 0, 80) : null
        );
        hook_out($ok ? 200 : 409, ['ok' => $ok, 'note' => $msg]);
    }
    if ($status === 'failed') {
        bill_fail_payment($db, (int) $pay['id'], 'The provider reported that the payment failed or was refused.');
        hook_out(200, ['ok' => true, 'note' => 'Marked as failed.']);
    }
    hook_out(400, ['error' => true, 'message' => 'Unknown status.']);
} catch (Throwable $e) {
    error_log('payment_webhook.php: ' . $e->getMessage());
    hook_out(500, ['error' => true, 'message' => 'Server error.']);
}
