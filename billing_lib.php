<?php
/**
 * MedBot — billing, insurance, payments, verification and staff messages (shared helpers).
 *
 * How a payment is verified automatically:
 *  1. A staff member creates an invoice for a patient. The system splits it: the insurer's share
 *     and the amount the patient must pay.
 *  2. The patient pays from THEIR OWN portal (so the payer is known to be that patient).
 *  3. The provider (MTN MoMo, Airtel Money, ...) confirms through api/payment_webhook.php, or an
 *     admin confirms a cashier payment. The payer's phone number is compared with the phone
 *     registered for the patient (match / mismatch).
 *  4. The system immediately sends an automatic message to the staff who provided the services
 *     and to the admins. Admins can then send a direct message from payments.php.
 *
 * PAYMENT_MODE (Render environment): "sandbox" (default) = nothing real is charged and an admin can
 * simulate the provider's confirmation; "live" = a real gateway must be connected in
 * bill_provider_request() below.
 */
require_once __DIR__ . '/auth.php';

const BILL_METHODS = [
    'mtn_momo'     => 'MTN Mobile Money',
    'airtel_money' => 'Airtel Money',
    'card'         => 'Card (Visa / Mastercard)',
    'cash'         => 'Cash at the cashier',
];

function bill_mode(): string {
    $m = strtolower((string) getenv('PAYMENT_MODE'));
    return $m === 'live' ? 'live' : 'sandbox';
}

/* ───────────── tables ───────────── */

function bill_ensure_tables(mysqli $db): void {
    $db->query("CREATE TABLE IF NOT EXISTS insurers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        kind ENUM('none','public','private') NOT NULL DEFAULT 'private',
        coverage_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 100,
        UNIQUE KEY uq_insurer_name (name)
    )");
    $db->query("CREATE TABLE IF NOT EXISTS patient_profile (
        patient_id INT PRIMARY KEY,
        phone VARCHAR(20) NULL,
        insurer_id INT NULL,
        member_no VARCHAR(60) NULL,
        coverage_override DECIMAL(5,2) NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    $db->query("CREATE TABLE IF NOT EXISTS invoices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        patient_id INT NOT NULL,
        created_by INT NULL,
        insurer_id INT NULL,
        insurer_name VARCHAR(120),
        coverage_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
        total_amount INT NOT NULL,
        insurance_amount INT NOT NULL,
        patient_amount INT NOT NULL,
        status ENUM('unpaid','pending','paid','cancelled') NOT NULL DEFAULT 'unpaid',
        note VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        paid_at TIMESTAMP NULL,
        KEY idx_invoice_patient (patient_id, status)
    )");
    $db->query("CREATE TABLE IF NOT EXISTS invoice_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_id INT NOT NULL,
        kind ENUM('consultation','medication','other') NOT NULL DEFAULT 'other',
        description VARCHAR(200) NOT NULL,
        qty INT NOT NULL DEFAULT 1,
        unit_price INT NOT NULL,
        amount INT NOT NULL,
        provided_by INT NULL,
        provided_by_name VARCHAR(150) NULL,
        KEY idx_item_invoice (invoice_id)
    )");
    $db->query("CREATE TABLE IF NOT EXISTS payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_id INT NOT NULL,
        patient_id INT NOT NULL,
        method ENUM('mtn_momo','airtel_money','card','cash') NOT NULL,
        amount INT NOT NULL,
        payer_phone VARCHAR(20) NULL,
        reference VARCHAR(40) NOT NULL,
        provider_txn VARCHAR(80) NULL,
        status ENUM('pending','confirmed','failed') NOT NULL DEFAULT 'pending',
        payer_check ENUM('unchecked','match','mismatch') NOT NULL DEFAULT 'unchecked',
        mode VARCHAR(10) NOT NULL DEFAULT 'sandbox',
        failure_note VARCHAR(200) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        confirmed_at TIMESTAMP NULL,
        UNIQUE KEY uq_payment_reference (reference),
        KEY idx_payment_invoice (invoice_id)
    )");
    $db->query("CREATE TABLE IF NOT EXISTS messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        from_user INT NULL,
        to_user INT NOT NULL,
        subject VARCHAR(150) NOT NULL,
        body TEXT NOT NULL,
        invoice_id INT NULL,
        payment_id INT NULL,
        is_auto TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        read_at TIMESTAMP NULL,
        KEY idx_message_inbox (to_user, read_at)
    )");
    // Starting list. Rates differ by scheme, facility level and policy: the admin can edit them (insurers.php).
    $db->query("INSERT IGNORE INTO insurers (name, kind, coverage_pct, sort_order) VALUES
        ('No insurance (patient pays all)', 'none', 0, 0),
        ('Mutuelle de Santé (CBHI)', 'public', 90, 1),
        ('RAMA (RSSB)', 'public', 85, 2),
        ('MMI (Military Medical Insurance)', 'public', 85, 3),
        ('Radiant Insurance', 'private', 85, 4),
        ('SORAS (Sanlam)', 'private', 85, 5),
        ('SONARWA', 'private', 85, 6),
        ('Prime Insurance', 'private', 85, 7),
        ('UAP Old Mutual', 'private', 85, 8),
        ('CORAR', 'private', 85, 9),
        ('Other private insurance', 'private', 85, 10)");
}

function bill_boot(mysqli $db): void {
    $probe = $db->query('SELECT 1 FROM payments LIMIT 1');
    if ($probe) { $probe->free(); return; }
    bill_ensure_tables($db);
}

/* ───────────── small helpers ───────────── */

function bill_rwf(int $n): string {
    return number_format($n, 0, '.', ',') . ' RWF';
}

/** Rwandan mobile number as 07XXXXXXXX, or '' when it does not look valid. */
function bill_phone($p): string {
    $d = preg_replace('/\D+/', '', (string) $p);
    if (strlen($d) === 12 && strpos($d, '250') === 0) $d = '0' . substr($d, 3);
    elseif (strlen($d) === 9) $d = '0' . $d;
    return (strlen($d) === 10 && $d[0] === '0' && $d[1] === '7') ? $d : '';
}

function bill_mask_phone(string $p): string {
    return $p === '' ? '' : substr($p, 0, 3) . '****' . substr($p, -3);
}

/** Splits a total into [insurer's share, patient's share] in whole francs. */
function bill_split(int $total, float $pct): array {
    $pct = max(0.0, min(100.0, $pct));
    $ins = (int) round($total * $pct / 100);
    $ins = max(0, min($total, $ins));
    return [$ins, $total - $ins];
}

/** The patient's insurer and the percentage the insurer pays. */
function bill_coverage(mysqli $db, int $patientId): array {
    $p = auth_row($db, 'SELECT pp.insurer_id, pp.coverage_override, i.name, i.coverage_pct FROM patient_profile pp LEFT JOIN insurers i ON i.id = pp.insurer_id WHERE pp.patient_id = ?', 'i', [$patientId]);
    if (!$p || $p['insurer_id'] === null) return ['insurer_id' => null, 'name' => 'No insurance', 'pct' => 0.0];
    $pct = $p['coverage_override'] !== null ? (float) $p['coverage_override'] : (float) $p['coverage_pct'];
    return ['insurer_id' => (int) $p['insurer_id'], 'name' => (string) $p['name'], 'pct' => $pct];
}

function bill_staff(mysqli $db): array {
    $out = [];
    $res = $db->query("SELECT id, full_name, role FROM users WHERE active = 1 AND role IN ('admin','doctor','nurse') ORDER BY full_name");
    if ($res) { while ($r = $res->fetch_assoc()) $out[] = $r; $res->free(); }
    return $out;
}

/* ───────────── messages between staff ───────────── */

function msg_send(mysqli $db, ?int $from, int $to, string $subject, string $body, ?int $invoiceId = null, ?int $paymentId = null, bool $auto = false): bool {
    $a = $auto ? 1 : 0;
    $n = auth_exec(
        $db,
        'INSERT INTO messages (from_user, to_user, subject, body, invoice_id, payment_id, is_auto) VALUES (?,?,?,?,?,?,?)',
        'iissiii',
        [$from, $to, mb_substr($subject, 0, 150), mb_substr($body, 0, 2000), $invoiceId, $paymentId, $a]
    );
    return $n !== null && $n > 0;
}

function msg_unread_count(mysqli $db, int $userId): int {
    try {
        $r = auth_row($db, 'SELECT COUNT(*) AS n FROM messages WHERE to_user = ? AND read_at IS NULL', 'i', [$userId]);
        return $r ? (int) $r['n'] : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

/* ───────────── payments ───────────── */

/**
 * Asks the mobile-money provider to send the payment prompt to the patient's phone.
 * Sandbox: nothing real happens. Live: connect your gateway here (MTN MoMo, Airtel Money,
 * IremboPay, Flutterwave, an aggregator...). It must make the provider call api/payment_webhook.php
 * with the payment "reference" when the patient approves.
 * Returns [ok, message].
 */
function bill_provider_request(array $payment, string $phone): array {
    if (bill_mode() === 'sandbox') return [true, 'Sandbox mode: no real money moves. An administrator confirms the test payment.'];
    return [false, 'The live payment provider is not connected yet. Please pay at the cashier.'];
}

/**
 * Marks a pending payment as confirmed, checks WHO paid, and tells the staff involved.
 * $payerPhone comes from the provider (or the simulation). Returns [ok, message].
 */
function bill_confirm_payment(mysqli $db, int $paymentId, ?string $payerPhone, ?string $providerTxn): array {
    $pay = auth_row($db, 'SELECT * FROM payments WHERE id = ?', 'i', [$paymentId]);
    if (!$pay) return [false, 'Payment not found.'];
    if ($pay['status'] !== 'pending') return [false, 'This payment is already ' . $pay['status'] . '.'];

    $inv = auth_row($db, 'SELECT * FROM invoices WHERE id = ?', 'i', [(int) $pay['invoice_id']]);
    $pt  = auth_row($db, 'SELECT pt.name, pp.phone FROM patients pt LEFT JOIN patient_profile pp ON pp.patient_id = pt.id WHERE pt.id = ?', 'i', [(int) $pay['patient_id']]);
    if (!$inv || !$pt) return [false, 'Invoice or patient not found.'];

    $isMobile = in_array($pay['method'], ['mtn_momo', 'airtel_money'], true);
    $payer    = bill_phone($payerPhone) !== '' ? bill_phone($payerPhone) : bill_phone($pay['payer_phone']);
    $known    = bill_phone($pt['phone']);
    $check    = ($isMobile && $payer !== '' && $known !== '') ? ($payer === $known ? 'match' : 'mismatch') : 'unchecked';

    $n = auth_exec(
        $db,
        "UPDATE payments SET status = 'confirmed', confirmed_at = NOW(), payer_phone = ?, provider_txn = ?, payer_check = ? WHERE id = ? AND status = 'pending'",
        'sssi',
        [$payer, (string) $providerTxn, $check, $paymentId]
    );
    if (!$n) return [false, 'This payment was already handled.'];
    auth_exec($db, "UPDATE invoices SET status = 'paid', paid_at = NOW() WHERE id = ?", 'i', [(int) $pay['invoice_id']]);

    // ── automatic messages ──
    $methodName = BILL_METHODS[$pay['method']] ?? $pay['method'];
    $amount     = bill_rwf((int) $pay['amount']);
    $checkText  = $check === 'match' ? 'Payer verified: the paying number matches the patient’s registered phone.'
        : ($check === 'mismatch' ? 'ATTENTION: the paying number does NOT match the patient’s registered phone. Please check.'
        : 'Payer could not be compared automatically (cash or no phone on record).');

    $items = [];
    $byUser = [];
    $res = $db->query('SELECT description, amount, provided_by FROM invoice_items WHERE invoice_id = ' . (int) $pay['invoice_id']);
    if ($res) {
        while ($it = $res->fetch_assoc()) {
            $items[] = $it;
            if ($it['provided_by'] !== null) $byUser[(int) $it['provided_by']][] = $it['description'] . ' (' . bill_rwf((int) $it['amount']) . ')';
        }
        $res->free();
    }
    if ($inv['created_by'] !== null && !isset($byUser[(int) $inv['created_by']])) $byUser[(int) $inv['created_by']] = [];

    $admins = [];
    $ar = $db->query("SELECT id FROM users WHERE role = 'admin' AND active = 1");
    if ($ar) { while ($a = $ar->fetch_assoc()) $admins[(int) $a['id']] = true; $ar->free(); }

    foreach ($byUser as $uid => $lines) {
        if (isset($admins[$uid])) continue;      // admins get the verification message below
        $body = $pt['name'] . ' has paid ' . $amount . ' (' . $methodName . ') for invoice #' . $pay['invoice_id'] . ".\n"
              . ($lines ? 'You provided: ' . implode('; ', $lines) . ".\n" : '')
              . $checkText;
        msg_send($db, null, $uid, 'Payment received — ' . $pt['name'], $body, (int) $pay['invoice_id'], $paymentId, true);
    }
    $names = [];
    foreach ($items as $it) $names[] = $it['description'];
    foreach (array_keys($admins) as $adminId) {
        $body = 'Payment verification for ' . $pt['name'] . ":\n"
              . 'Invoice #' . $pay['invoice_id'] . ' · ' . $amount . ' · ' . $methodName . ' · reference ' . $pay['reference'] . ".\n"
              . $checkText . "\n"
              . 'Items: ' . implode(', ', $names) . ".\nThe staff who provided these have been informed automatically.";
        msg_send($db, null, $adminId, ($check === 'mismatch' ? '⚠ ' : '') . 'Payment verified — ' . $pt['name'], $body, (int) $pay['invoice_id'], $paymentId, true);
    }
    return [true, 'Payment confirmed. ' . $checkText];
}

/** Marks a pending payment as failed and lets the patient try again. */
function bill_fail_payment(mysqli $db, int $paymentId, string $reason): void {
    $pay = auth_row($db, 'SELECT invoice_id, status FROM payments WHERE id = ?', 'i', [$paymentId]);
    if (!$pay || $pay['status'] !== 'pending') return;
    auth_exec($db, "UPDATE payments SET status = 'failed', failure_note = ? WHERE id = ? AND status = 'pending'", 'si', [mb_substr($reason, 0, 200), $paymentId]);
    auth_exec($db, "UPDATE invoices SET status = 'unpaid' WHERE id = ? AND status = 'pending'", 'i', [(int) $pay['invoice_id']]);
}
