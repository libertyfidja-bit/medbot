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

/* ───────────── medicines: release after payment, dose schedule, reminders ───────────── */

const MEDS_HANDOVER = [
    'nurse'    => 'A nurse hands the medicines to the patient',
    'pharmacy' => 'The patient collects them at the pharmacy',
    'robot'    => 'The robot brings them to the patient’s room',
];

/** Current time in Rwanda. Dose times are stored in Rwandan local time (no time zone conversion needed). */
function meds_now(): DateTimeImmutable {
    return new DateTimeImmutable('now', new DateTimeZone('Africa/Kigali'));
}

function meds_boot(mysqli $db): void {
    $probe = $db->query('SELECT 1 FROM med_doses LIMIT 1');
    if ($probe) { $probe->free(); return; }
    $db->query("CREATE TABLE IF NOT EXISTS med_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_id INT NOT NULL,
        item_id INT NOT NULL,
        patient_id INT NOT NULL,
        medication VARCHAR(200) NOT NULL,
        qty INT NOT NULL DEFAULT 1,
        dose_text VARCHAR(150) NOT NULL,
        times_per_day TINYINT NOT NULL DEFAULT 1,
        days SMALLINT NOT NULL DEFAULT 1,
        instructions VARCHAR(255) NULL,
        handover ENUM('nurse','pharmacy','robot') NOT NULL DEFAULT 'nurse',
        released_by INT NULL,
        released_by_name VARCHAR(150) NULL,
        override_reason VARCHAR(255) NULL,
        released_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_order_item (item_id),
        KEY idx_order_patient (patient_id)
    )");
    $db->query("CREATE TABLE IF NOT EXISTS med_doses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        patient_id INT NOT NULL,
        due_at DATETIME NOT NULL,
        status ENUM('pending','taken','missed') NOT NULL DEFAULT 'pending',
        taken_at DATETIME NULL,
        KEY idx_dose_patient (patient_id, due_at),
        KEY idx_dose_order (order_id)
    )");
}

/** Dose times for a course. Optionally starts with a dose right now. Returns 'Y-m-d H:i:s' strings. */
function meds_generate_doses(DateTimeImmutable $now, int $perDay, int $days, bool $firstNow): array {
    $presets = [1 => ['08:00'], 2 => ['08:00', '20:00'], 3 => ['08:00', '14:00', '20:00'], 4 => ['06:00', '12:00', '18:00', '22:00']];
    $times = $presets[$perDay] ?? $presets[1];
    $out = [];
    if ($firstNow) $out[] = $now->format('Y-m-d H:i:00');
    for ($d = 0; $d < $days; $d++) {
        $day = $now->modify('+' . $d . ' day')->format('Y-m-d');
        foreach ($times as $t) {
            $due = new DateTimeImmutable($day . ' ' . $t . ':00', $now->getTimezone());
            if ($due <= $now->modify('+30 minutes')) continue;        // already past
            $out[] = $due->format('Y-m-d H:i:s');
        }
    }
    $out = array_values(array_unique($out));
    sort($out);
    return array_slice($out, 0, $perDay * $days);                      // the course is perDay × days doses
}

/**
 * Releases the medicines of a PAID invoice: records the order, creates the dose schedule and tells the
 * patient (they see it, with reminders, in their portal). A doctor or admin may release before payment
 * in an emergency ($override) with a written reason; the admins are told.
 * $in is the submitted form. Returns [ok, message, handover].
 */
function meds_release(mysqli $db, int $invoiceId, array $me, array $in, bool $override, string $reason): array {
    $inv = auth_row($db, 'SELECT i.id, i.patient_id, i.status, p.name AS patient_name FROM invoices i JOIN patients p ON p.id = i.patient_id WHERE i.id = ?', 'i', [$invoiceId]);
    if (!$inv) return [false, 'Invoice not found.', ''];
    if ($inv['status'] === 'cancelled') return [false, 'This invoice was cancelled.', ''];
    $reason = mb_substr(trim(strip_tags($reason)), 0, 255);
    if ($inv['status'] !== 'paid') {
        if (!$override) return [false, 'Medicines can only be released after the payment is confirmed.', ''];
        if (mb_strlen($reason) < 5) return [false, 'Write the reason for releasing before payment (at least 5 characters).', ''];
    }

    $handover = (string) ($in['handover'] ?? 'nurse');
    if (!isset(MEDS_HANDOVER[$handover])) $handover = 'nurse';
    $firstNow = !empty($in['first_now']);

    $items = [];
    $res = $db->query('SELECT ii.id, ii.description, ii.qty FROM invoice_items ii LEFT JOIN med_orders mo ON mo.item_id = ii.id WHERE ii.invoice_id = ' . (int) $invoiceId . " AND ii.kind = 'medication' AND mo.id IS NULL ORDER BY ii.id");
    if ($res) { while ($r = $res->fetch_assoc()) $items[] = $r; $res->free(); }
    if (!$items) return [false, 'There are no medicines left to release on this invoice.', ''];

    $plans = [];
    foreach ($items as $it) {
        $id    = (int) $it['id'];
        $dose  = mb_substr(trim(strip_tags((string) (($in['dose_text'] ?? [])[$id] ?? ''))), 0, 150);
        $per   = (int) (($in['times'] ?? [])[$id] ?? 0);
        $days  = (int) (($in['days'] ?? [])[$id] ?? 0);
        $note  = mb_substr(trim(strip_tags((string) (($in['note'] ?? [])[$id] ?? ''))), 0, 255);
        if ($dose === '') return [false, 'Write the dose for “' . $it['description'] . '” (for example: 1 tablet).', ''];
        if ($per < 1 || $per > 4) return [false, 'Times per day for “' . $it['description'] . '” must be 1 to 4.', ''];
        if ($days < 1 || $days > 60) return [false, 'Days for “' . $it['description'] . '” must be 1 to 60.', ''];
        $plans[] = [$it, $dose, $per, $days, $note];
    }

    $now = meds_now();
    $by  = (int) $me['id'] > 0 ? (int) $me['id'] : null;
    $db->begin_transaction();
    $ok = true;
    $totalDoses = 0;
    foreach ($plans as [$it, $dose, $per, $days, $note]) {
        $n = auth_exec(
            $db,
            'INSERT INTO med_orders (invoice_id, item_id, patient_id, medication, qty, dose_text, times_per_day, days, instructions, handover, released_by, released_by_name, override_reason) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            'iiisisiississ',
            [$invoiceId, (int) $it['id'], (int) $inv['patient_id'], $it['description'], (int) $it['qty'], $dose, $per, $days, $note, $handover, $by, $me['full_name'], $inv['status'] === 'paid' ? '' : $reason]
        );
        $orderId = (int) $db->insert_id;
        if (!$n || $orderId <= 0) { $ok = false; break; }
        foreach (meds_generate_doses($now, $per, $days, $firstNow) as $due) {
            if (auth_exec($db, 'INSERT INTO med_doses (order_id, patient_id, due_at) VALUES (?,?,?)', 'iis', [$orderId, (int) $inv['patient_id'], $due]) === null) { $ok = false; break 2; }
            $totalDoses++;
        }
    }
    if (!$ok) { $db->rollback(); return [false, 'Could not save the release. Please try again.', '']; }
    $db->commit();

    if ($inv['status'] !== 'paid') {
        $ar = $db->query("SELECT id FROM users WHERE role = 'admin' AND active = 1");
        if ($ar) {
            while ($a = $ar->fetch_assoc()) {
                msg_send($db, $by, (int) $a['id'], '⚠ Medicines released before payment — ' . $inv['patient_name'],
                    $me['full_name'] . ' released medicines on invoice #' . $invoiceId . " before the payment was confirmed.\nReason: " . $reason, $invoiceId, null, true);
            }
            $ar->free();
        }
    }
    return [true, 'Released ' . count($plans) . ' medicine(s) for ' . $inv['patient_name'] . ' with ' . $totalDoses . ' reminder(s). ' . MEDS_HANDOVER[$handover] . '.', $handover];
}

/** Marks doses that are more than 2 hours late as missed and tells the staff member who released them. */
function meds_tick(mysqli $db): void {
    $cut = meds_now()->modify('-120 minutes')->format('Y-m-d H:i:s');
    $rows = [];
    $stmt = $db->prepare("SELECT d.id, d.due_at, o.medication, o.released_by, p.name AS patient_name FROM med_doses d JOIN med_orders o ON o.id = d.order_id JOIN patients p ON p.id = d.patient_id WHERE d.status = 'pending' AND d.due_at < ? ORDER BY d.id LIMIT 50");
    if (!$stmt) return;
    $stmt->bind_param('s', $cut);
    if ($stmt->execute()) {
        $r = $stmt->get_result();
        while ($r && ($row = $r->fetch_assoc())) $rows[] = $row;
    }
    $stmt->close();
    foreach ($rows as $row) {
        $n = auth_exec($db, "UPDATE med_doses SET status = 'missed' WHERE id = ? AND status = 'pending'", 'i', [(int) $row['id']]);
        if ($n && $row['released_by'] !== null) {
            msg_send($db, null, (int) $row['released_by'], 'Missed dose — ' . $row['patient_name'],
                $row['patient_name'] . ' did not take ' . $row['medication'] . ' due at ' . substr((string) $row['due_at'], 11, 5) . '. Please check on the patient.', null, null, true);
        }
    }
}
