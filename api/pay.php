<?php
/**
 * MedBot — the signed-in PATIENT pays their own invoices      (place this file at: api/pay.php)
 *
 *   GET  ?action=invoices                      -> own invoices with the insurance share, the patient's share, items
 *   POST {action:"start", invoice_id, method, phone}   -> starts a payment
 *   POST {action:"cancel", payment_id}                 -> cancels a payment that is still waiting
 *
 * Only the patient role can open this file (auth_allowed), and every query is tied to the
 * patient record of the signed-in account, so a patient can never pay or see someone else's invoice.
 * The payment is confirmed LATER by the provider (api/payment_webhook.php) or by an admin;
 * this file never confirms anything itself.
 */
require_once __DIR__ . '/../connect.php';
require_once __DIR__ . '/../billing_lib.php';
mysqli_report(MYSQLI_REPORT_OFF);
header('Cache-Control: no-store');

function fail(string $msg, int $code = 400): never {
    jsonResponse(['error' => true, 'message' => $msg], $code);
}

try {
    $me = auth_user();
    if (!$me || $me['role'] !== 'patient') fail('Patients only.', 403);
    $pid = (int) ($me['patient_id'] ?? 0);
    if ($pid <= 0) fail('Your account is not linked to a patient record yet. Please ask a nurse.', 409);

    $db = getDB();
    mysqli_report(MYSQLI_REPORT_OFF);
    bill_boot($db);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $in = [];
    if ($method === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true);
        if (!is_array($in)) $in = $_POST;
    }
    $action = $in['action'] ?? ($_GET['action'] ?? 'invoices');

    switch ($action) {

        case 'invoices': {
            $profile = auth_row($db, 'SELECT phone FROM patient_profile WHERE patient_id = ?', 'i', [$pid]);
            $invoices = [];
            $ids = [];
            $stmt = $db->prepare("SELECT id, total_amount, insurance_amount, patient_amount, insurer_name, coverage_pct, status, note, created_at FROM invoices WHERE patient_id = ? AND status <> 'cancelled' ORDER BY id DESC LIMIT 15");
            if ($stmt) {
                $stmt->bind_param('i', $pid);
                if ($stmt->execute()) {
                    $r = $stmt->get_result();
                    while ($r && ($row = $r->fetch_assoc())) {
                        $row['id']               = (int) $row['id'];
                        $row['total_amount']     = (int) $row['total_amount'];
                        $row['insurance_amount'] = (int) $row['insurance_amount'];
                        $row['patient_amount']   = (int) $row['patient_amount'];
                        $row['coverage_pct']     = (float) $row['coverage_pct'];
                        $row['items']            = [];
                        $row['payment']          = null;
                        $invoices[$row['id']]    = $row;
                        $ids[]                   = $row['id'];
                    }
                }
                $stmt->close();
            }
            if ($ids) {
                $list = implode(',', array_map('intval', $ids));
                $res = $db->query('SELECT invoice_id, kind, description, qty, amount FROM invoice_items WHERE invoice_id IN (' . $list . ') ORDER BY id');
                if ($res) {
                    while ($it = $res->fetch_assoc()) {
                        $invoices[(int) $it['invoice_id']]['items'][] = [
                            'kind' => $it['kind'], 'description' => $it['description'], 'qty' => (int) $it['qty'], 'amount' => (int) $it['amount'],
                        ];
                    }
                    $res->free();
                }
                $res = $db->query("SELECT id, invoice_id, method, status FROM payments WHERE status = 'pending' AND invoice_id IN (" . $list . ') ORDER BY id DESC');
                if ($res) {
                    while ($p = $res->fetch_assoc()) {
                        $k = (int) $p['invoice_id'];
                        if ($invoices[$k]['payment'] === null) $invoices[$k]['payment'] = ['id' => (int) $p['id'], 'method' => $p['method']];
                    }
                    $res->free();
                }
            }
            jsonResponse([
                'error'    => false,
                'mode'     => bill_mode(),
                'phone'    => $profile ? bill_phone($profile['phone']) : '',
                'invoices' => array_values($invoices),
            ]);
        }

        case 'start': {
            if ($method !== 'POST') fail('POST required.', 405);
            $invId  = (int) ($in['invoice_id'] ?? 0);
            $how    = (string) ($in['method'] ?? '');
            if (!isset(BILL_METHODS[$how])) fail('Choose how you want to pay.');

            $inv = auth_row($db, 'SELECT id, patient_amount, status FROM invoices WHERE id = ? AND patient_id = ?', 'ii', [$invId, $pid]);
            if (!$inv) fail('That invoice was not found.', 404);
            if ($inv['status'] === 'paid') fail('This invoice is already paid.', 409);
            if ($inv['status'] !== 'unpaid') fail('This invoice cannot be paid right now (' . $inv['status'] . ').', 409);
            if ((int) $inv['patient_amount'] <= 0) fail('Nothing is left for you to pay on this invoice.', 409);

            $phone = '';
            if (in_array($how, ['mtn_momo', 'airtel_money'], true)) {
                $profile = auth_row($db, 'SELECT phone FROM patient_profile WHERE patient_id = ?', 'i', [$pid]);
                $phone = bill_phone($in['phone'] ?? '') !== '' ? bill_phone($in['phone']) : bill_phone($profile ? $profile['phone'] : '');
                if ($phone === '') fail('Enter a valid mobile money number (07XXXXXXXX).');
            }

            $reference = 'MB' . date('ymd') . strtoupper(bin2hex(random_bytes(3)));
            $amount    = (int) $inv['patient_amount'];
            $mode      = bill_mode();
            $n = auth_exec(
                $db,
                'INSERT INTO payments (invoice_id, patient_id, method, amount, payer_phone, reference, mode) VALUES (?,?,?,?,?,?,?)',
                'iisisss',
                [$invId, $pid, $how, $amount, $phone, $reference, $mode]
            );
            $paymentId = (int) $db->insert_id;
            if (!$n || $paymentId <= 0) fail('Could not start the payment. Please try again.', 500);

            // only one waiting payment per invoice
            $marked = auth_exec($db, "UPDATE invoices SET status = 'pending' WHERE id = ? AND status = 'unpaid'", 'i', [$invId]);
            if (!$marked) {
                auth_exec($db, "UPDATE payments SET status = 'failed', failure_note = 'duplicate' WHERE id = ?", 'i', [$paymentId]);
                fail('A payment is already in progress for this invoice.', 409);
            }

            $note = '';
            if ($how === 'cash') {
                $note = 'Go to the cashier with this reference. The cashier will confirm your payment.';
            } else {
                [$ok, $msg] = bill_provider_request(['id' => $paymentId, 'reference' => $reference, 'amount' => $amount, 'method' => $how], $phone);
                if (!$ok) {
                    bill_fail_payment($db, $paymentId, $msg);
                    fail($msg, 502);
                }
                $note = $msg;
            }
            jsonResponse(['error' => false, 'payment_id' => $paymentId, 'reference' => $reference, 'mode' => $mode, 'message' => $note], 201);
        }

        case 'cancel': {
            if ($method !== 'POST') fail('POST required.', 405);
            $payId = (int) ($in['payment_id'] ?? 0);
            $p = auth_row($db, 'SELECT id, status FROM payments WHERE id = ? AND patient_id = ?', 'ii', [$payId, $pid]);
            if (!$p) fail('Payment not found.', 404);
            if ($p['status'] !== 'pending') fail('This payment is no longer waiting.', 409);
            bill_fail_payment($db, $payId, 'Cancelled by the patient');
            jsonResponse(['error' => false]);
        }

        default:
            fail('Unknown action.');
    }
} catch (Throwable $e) {
    error_log('pay.php: ' . $e->getMessage());
    fail('Server error.', 500);
}
