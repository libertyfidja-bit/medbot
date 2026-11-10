<?php
require_once 'connect.php';
require_once 'layout.php';
require_once __DIR__ . '/billing_lib.php';

$me = auth_user();
auth_start_session();
$db = getDB();
auth_ensure_tables($db);
bill_boot($db);
meds_boot($db);
meds_tick($db);
$myId   = (int) $me['id'];
$isAdmin = $me['role'] === 'admin';
$staff  = bill_staff($db);
$staffById = [];
foreach ($staff as $s) $staffById[(int) $s['id']] = $s;

/** The form a doctor or nurse fills in to release medicines and start the patient's dose reminders. */
function bill_release_form(array $v, array $pending, string $csrf, bool $override): string {
    $h = '<form method="post" action="billing.php" style="margin-top:8px;display:grid;gap:8px;min-width:270px">'
       . '<input type="hidden" name="csrf" value="' . auth_h($csrf) . '"><input type="hidden" name="action" value="release"><input type="hidden" name="invoice_id" value="' . (int) $v['id'] . '">';
    if ($override) {
        $h .= '<input type="hidden" name="override" value="1"><input name="reason" type="text" maxlength="255" placeholder="Reason for releasing BEFORE payment" required>';
    }
    foreach ($pending as $it) {
        $id = (int) $it['id'];
        $h .= '<div style="border:1px solid var(--border);border-radius:8px;padding:8px;display:grid;gap:6px"><strong>' . auth_h($it['description']) . ' × ' . (int) $it['qty'] . '</strong>'
            . '<input name="dose_text[' . $id . ']" type="text" maxlength="150" placeholder="Dose, e.g. 1 tablet" required>'
            . '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px"><select name="times[' . $id . ']" aria-label="Times per day"><option value="1">1× a day</option><option value="2">2× a day</option><option value="3" selected>3× a day</option><option value="4">4× a day</option></select>'
            . '<input name="days[' . $id . ']" type="number" min="1" max="60" value="5" aria-label="Days"></div>'
            . '<input name="note[' . $id . ']" type="text" maxlength="255" placeholder="Instructions, e.g. after meals"></div>';
    }
    $h .= '<select name="handover" aria-label="How the patient receives the medicines">';
    foreach (MEDS_HANDOVER as $k => $label) $h .= '<option value="' . auth_h($k) . '">' . auth_h($label) . '</option>';
    $h .= '</select><label style="display:flex;gap:6px;align-items:center;font-size:12.5px"><input type="checkbox" name="first_now" value="1" checked style="width:auto"> The patient takes the first dose now</label>'
        . '<button class="btn btn-teal btn-sm" type="submit">💊 Give order &amp; release</button></form>';
    return $h;
}

function bill_money($v): ?int {
    $v = trim((string) $v);
    if ($v === '' || !ctype_digit($v)) return $v === '' ? 0 : null;
    $n = (int) $v;
    return $n > 100000000 ? null : $n;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $flash = ['type' => 'error', 'msg' => 'This page expired. Please try again.'];

    if (auth_csrf_ok()) {
        $act = (string) ($_POST['action'] ?? '');

        if ($act === 'create') {
            $pid = (int) ($_POST['patient_id'] ?? 0);
            $pt  = auth_row($db, 'SELECT id, name FROM patients WHERE id = ?', 'i', [$pid]);
            $items = [];
            $problem = '';

            $consult = bill_money($_POST['consult_fee'] ?? '');
            if ($consult === null) $problem = 'The consultation fee must be a whole number of francs.';
            elseif ($consult > 0) {
                $by = (int) ($_POST['consult_by'] ?? $myId);
                $items[] = ['consultation', 'Consultation', 1, $consult, $consult, isset($staffById[$by]) ? $by : null];
            }

            $descs = (array) ($_POST['med_desc'] ?? []);
            $qtys  = (array) ($_POST['med_qty'] ?? []);
            $prices = (array) ($_POST['med_price'] ?? []);
            $bys   = (array) ($_POST['med_by'] ?? []);
            $count = min(count($descs), 30);
            for ($i = 0; $i < $count && $problem === ''; $i++) {
                $d = mb_substr(trim(strip_tags((string) $descs[$i])), 0, 200);
                $q = ctype_digit(trim((string) ($qtys[$i] ?? ''))) ? (int) $qtys[$i] : 0;
                $u = bill_money($prices[$i] ?? '');
                if ($d === '' && ($u === null || $u === 0)) continue;           // empty row
                if ($d === '') { $problem = 'Every medicine line needs a name.'; break; }
                if ($q < 1 || $q > 999) { $problem = 'The quantity of “' . $d . '” must be between 1 and 999.'; break; }
                if ($u === null || $u < 1) { $problem = 'Enter the price of “' . $d . '”.'; break; }
                $by = (int) ($bys[$i] ?? 0);
                $items[] = ['medication', $d, $q, $u, $q * $u, isset($staffById[$by]) ? $by : null];
            }

            $otherDesc = mb_substr(trim(strip_tags((string) ($_POST['other_desc'] ?? ''))), 0, 200);
            $otherAmt  = bill_money($_POST['other_amount'] ?? '');
            if ($problem === '' && $otherAmt === null) $problem = 'The other fee must be a whole number of francs.';
            if ($problem === '' && $otherAmt > 0) {
                $items[] = ['other', $otherDesc !== '' ? $otherDesc : 'Other service', 1, $otherAmt, $otherAmt, $myId > 0 ? $myId : null];
            }

            if (!$pt) {
                $flash = ['type' => 'error', 'msg' => 'Choose the patient.'];
            } elseif ($problem !== '') {
                $flash = ['type' => 'error', 'msg' => $problem];
            } elseif (!$items) {
                $flash = ['type' => 'error', 'msg' => 'Add at least one charge (consultation or medicine).'];
            } else {
                $total = 0;
                foreach ($items as $it) $total += $it[4];
                $cov = bill_coverage($db, $pid);
                [$insAmt, $patAmt] = bill_split($total, $cov['pct']);
                $note = mb_substr(trim(strip_tags((string) ($_POST['note'] ?? ''))), 0, 255);

                $db->begin_transaction();
                $ok = auth_exec(
                    $db,
                    'INSERT INTO invoices (patient_id, created_by, insurer_id, insurer_name, coverage_pct, total_amount, insurance_amount, patient_amount, note) VALUES (?,?,?,?,?,?,?,?,?)',
                    'iiisdiiis',
                    [$pid, $myId > 0 ? $myId : null, $cov['insurer_id'], $cov['name'], $cov['pct'], $total, $insAmt, $patAmt, $note]
                );
                $invId = (int) $db->insert_id;
                $allOk = $ok !== null && $invId > 0;
                foreach ($items as $it) {
                    if (!$allOk) break;
                    $byName = $it[5] !== null ? $staffById[$it[5]]['full_name'] : null;
                    $r = auth_exec(
                        $db,
                        'INSERT INTO invoice_items (invoice_id, kind, description, qty, unit_price, amount, provided_by, provided_by_name) VALUES (?,?,?,?,?,?,?,?)',
                        'issiiiis',
                        [$invId, $it[0], $it[1], $it[2], $it[3], $it[4], $it[5], $byName]
                    );
                    if ($r === null) $allOk = false;
                }
                if ($allOk && $patAmt === 0) {
                    auth_exec($db, "UPDATE invoices SET status = 'paid', paid_at = NOW() WHERE id = ?", 'i', [$invId]);
                }
                if ($allOk) {
                    $db->commit();
                    $flash = ['type' => 'ok', 'msg' => 'Invoice #' . $invId . ' created for ' . $pt['name'] . ': total ' . bill_rwf($total) . ', insurance pays ' . bill_rwf($insAmt) . ' (' . rtrim(rtrim(number_format($cov['pct'], 2, '.', ''), '0'), '.') . '%), the patient pays ' . bill_rwf($patAmt) . '.' . ($patAmt === 0 ? ' Fully covered by insurance: you can release the medicines now.' : ' The patient can now pay from their portal.')];
                } else {
                    $db->rollback();
                    $flash = ['type' => 'error', 'msg' => 'Could not save the invoice. Please try again.'];
                }
            }
        } elseif ($act === 'release') {
            $invId    = (int) ($_POST['invoice_id'] ?? 0);
            $override = !empty($_POST['override']) && in_array($me['role'], ['doctor', 'admin'], true);
            [$okR, $msgR, $handoverR] = meds_release($db, $invId, $me, $_POST, $override, (string) ($_POST['reason'] ?? ''));
            $flash = ['type' => $okR ? 'ok' : 'error', 'msg' => $msgR];
            if ($okR && $handoverR === 'robot') $flash['robot'] = true;
        } elseif ($act === 'cancel') {
            $id  = (int) ($_POST['id'] ?? 0);
            $inv = auth_row($db, 'SELECT id, created_by, status FROM invoices WHERE id = ?', 'i', [$id]);
            if (!$inv) {
                $flash = ['type' => 'error', 'msg' => 'Invoice not found.'];
            } elseif ($inv['status'] !== 'unpaid') {
                $flash = ['type' => 'error', 'msg' => 'Only an unpaid invoice can be cancelled.'];
            } elseif (!$isAdmin && (int) $inv['created_by'] !== $myId) {
                $flash = ['type' => 'error', 'msg' => 'Only the person who made the invoice, or an administrator, can cancel it.'];
            } else {
                auth_exec($db, "UPDATE invoices SET status = 'cancelled' WHERE id = ? AND status = 'unpaid'", 'i', [$id]);
                $flash = ['type' => 'ok', 'msg' => 'Invoice #' . $id . ' cancelled.'];
            }
        }
    }
    $_SESSION['flash'] = $flash;
    header('Location: billing.php');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$patients = [];
$res = $db->query('SELECT p.id, p.name, p.room_number, i.name AS insurer_name, pp.coverage_override, i.coverage_pct
                   FROM patients p LEFT JOIN patient_profile pp ON pp.patient_id = p.id LEFT JOIN insurers i ON i.id = pp.insurer_id ORDER BY p.name');
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $r['pct'] = $r['insurer_name'] ? ($r['coverage_override'] !== null ? (float) $r['coverage_override'] : (float) $r['coverage_pct']) : 0.0;
        $patients[] = $r;
    }
    $res->free();
}

$invoices = [];
$res = $db->query('SELECT v.id, v.total_amount, v.insurance_amount, v.patient_amount, v.insurer_name, v.coverage_pct, v.status, v.created_by, v.created_at, p.name AS patient_name, u.full_name AS creator
                   FROM invoices v JOIN patients p ON p.id = v.patient_id LEFT JOIN users u ON u.id = v.created_by ORDER BY v.id DESC LIMIT 30');
if ($res) { while ($r = $res->fetch_assoc()) $invoices[] = $r; $res->free(); }

$medItems = [];
if ($invoices) {
    $ids = implode(',', array_map(fn($x) => (int) $x['id'], $invoices));
    $res = $db->query("SELECT ii.id, ii.invoice_id, ii.description, ii.qty, mo.released_by_name, mo.dose_text FROM invoice_items ii LEFT JOIN med_orders mo ON mo.item_id = ii.id WHERE ii.kind = 'medication' AND ii.invoice_id IN (" . $ids . ') ORDER BY ii.id');
    if ($res) { while ($r = $res->fetch_assoc()) $medItems[(int) $r['invoice_id']][] = $r; $res->free(); }
}

$jsPatients = [];
foreach ($patients as $p) $jsPatients[(string) $p['id']] = ['ins' => $p['insurer_name'] ?: 'No insurance', 'pct' => $p['pct']];
$staffOptions = '<option value="">— nobody —</option>';
foreach ($staff as $s) $staffOptions .= '<option value="' . (int) $s['id'] . '"' . ((int) $s['id'] === $myId ? ' selected' : '') . '>' . auth_h($s['full_name']) . ' (' . auth_h($s['role']) . ')</option>';

$csrf = auth_csrf();
$statusBadge = ['unpaid' => 'badge-warning', 'pending' => 'badge-info', 'paid' => 'badge-safe', 'cancelled' => 'badge-gray'];

renderHead('Billing', 'billing');
renderTopbar('Billing', 'Consultation and medicine charges, with the insurance share');
?>
<div class="page-content">

<?php if ($flash): ?>
    <div class="alert <?= $flash['type'] === 'ok' ? 'alert-success' : 'alert-error' ?>" role="status"><span class="alert-icon"><?= $flash['type'] === 'ok' ? '✅' : '⚠️' ?></span><div><?= auth_h($flash['msg']) ?><?php if (!empty($flash['robot'])): ?><div style="margin-top:8px"><a class="btn btn-teal btn-sm" href="pathfinding.php">🤖 Send the robot now</a></div><?php endif; ?></div></div>
<?php endif; ?>

    <div class="card">
        <div class="card-header"><div class="card-title">🧾 New charge</div></div>
        <div class="card-body">
            <form method="post" action="billing.php" id="bill-form" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= auth_h($csrf) ?>">
                <input type="hidden" name="action" value="create">

                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label" for="b-patient">Patient</label>
                        <select id="b-patient" name="patient_id" required>
                            <option value="">— Choose a patient —</option>
                            <?php foreach ($patients as $p): ?>
                                <option value="<?= (int) $p['id'] ?>"><?= auth_h($p['name']) ?><?= $p['room_number'] ? ' — Room ' . auth_h($p['room_number']) : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="input-hint" id="b-ins">Insurance: —</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="b-note">Note (optional)</label>
                        <input id="b-note" name="note" type="text" maxlength="255">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="b-consult">Consultation fee (RWF)</label>
                        <input id="b-consult" name="consult_fee" type="number" min="0" step="1" class="bill-amt">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="b-consult-by">Consultation done by</label>
                        <select id="b-consult-by" name="consult_by"><?= $staffOptions ?></select>
                    </div>
                </div>

                <div style="margin:16px 0 6px;font-weight:600;color:var(--text-1)">💊 Medicines given</div>
                <div id="med-rows"></div>
                <button type="button" class="btn btn-secondary btn-sm" id="add-med" style="margin-top:8px">+ Add medicine</button>

                <div class="form-grid" style="margin-top:14px">
                    <div class="form-group"><label class="form-label" for="b-od">Other service (optional)</label><input id="b-od" name="other_desc" type="text" maxlength="200" placeholder="e.g. Lab test"></div>
                    <div class="form-group"><label class="form-label" for="b-oa">Amount (RWF)</label><input id="b-oa" name="other_amount" type="number" min="0" step="1" class="bill-amt"></div>
                </div>

                <div id="bill-summary" style="margin-top:16px;padding:14px;border:1px solid var(--border-accent);border-radius:var(--radius-sm);background:rgba(124,109,255,.08);color:var(--text-1);line-height:1.8">
                    Total: <strong id="s-total">0 RWF</strong><br>
                    <span id="s-ins-label">Insurance pays</span>: <strong id="s-ins">0 RWF</strong><br>
                    <span style="font-size:16px">The patient pays: <strong id="s-pat" style="color:var(--teal)">0 RWF</strong></span>
                </div>
                <div style="margin-top:14px"><button class="btn btn-primary" type="submit">Create invoice</button></div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><div class="card-title">📋 Recent invoices</div></div>
        <div class="table-wrap">
        <table class="dt">
            <thead><tr><th>#</th><th>Patient</th><th>Total</th><th>Insurance</th><th>Patient pays</th><th>By</th><th>Status</th><th>Medicines</th><th></th></tr></thead>
            <tbody>
            <?php if (!$invoices): ?><tr><td colspan="9" style="text-align:center;color:var(--text-3);padding:24px">No invoices yet.</td></tr><?php endif; ?>
            <?php foreach ($invoices as $v): ?>
                <tr>
                    <td>#<?= (int) $v['id'] ?></td>
                    <td><?= auth_h($v['patient_name']) ?><div style="font-size:11.5px;color:var(--text-3)"><?= auth_h($v['created_at']) ?></div></td>
                    <td><?= auth_h(bill_rwf((int) $v['total_amount'])) ?></td>
                    <td><?= auth_h(bill_rwf((int) $v['insurance_amount'])) ?><div style="font-size:11.5px;color:var(--text-3)"><?= auth_h($v['insurer_name']) ?> · <?= auth_h(rtrim(rtrim(number_format((float) $v['coverage_pct'], 2, '.', ''), '0'), '.')) ?>%</div></td>
                    <td><strong style="color:var(--text-1)"><?= auth_h(bill_rwf((int) $v['patient_amount'])) ?></strong></td>
                    <td><?= auth_h($v['creator'] ?? '—') ?></td>
                    <td><span class="badge <?= $statusBadge[$v['status']] ?? 'badge-gray' ?>"><?= auth_h($v['status']) ?></span></td>
                    <td style="min-width:240px">
                        <?php
                        $mi = $medItems[(int) $v['id']] ?? [];
                        $left = array_values(array_filter($mi, fn($x) => $x['dose_text'] === null));
                        if (!$mi): ?><span style="color:var(--text-3)">—</span><?php endif;
                        foreach ($mi as $x): if ($x['dose_text'] !== null): ?>
                            <div style="font-size:12.5px;color:var(--teal)">✅ <?= auth_h($x['description']) ?> — released by <?= auth_h($x['released_by_name'] ?? '') ?></div>
                        <?php endif; endforeach;
                        if ($left && $v['status'] === 'paid'): ?>
                            <details><summary style="cursor:pointer;color:var(--accent);font-size:13px;font-weight:600">💊 Release medicines</summary><?= bill_release_form($v, $left, $csrf, false) ?></details>
                        <?php elseif ($left && in_array($v['status'], ['unpaid', 'pending'], true)): ?>
                            <div style="font-size:12.5px;color:var(--warning)">⏳ Waiting for payment</div>
                            <?php if (in_array($me['role'], ['doctor', 'admin'], true)): ?>
                                <details><summary style="cursor:pointer;color:var(--danger);font-size:12.5px">Emergency: release before payment</summary><?= bill_release_form($v, $left, $csrf, true) ?></details>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($v['status'] === 'unpaid' && ($isAdmin || (int) $v['created_by'] === $myId)): ?>
                            <form method="post" action="billing.php" onsubmit="return confirm('Cancel this invoice?')"><input type="hidden" name="csrf" value="<?= auth_h($csrf) ?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int) $v['id'] ?>"><button class="btn btn-danger btn-xs" type="submit">Cancel</button></form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<script>
(function () {
    var PATIENTS = <?= json_encode($jsPatients, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var STAFF_OPTIONS = <?= json_encode($staffOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var $ = function (id) { return document.getElementById(id); };
    var rows = $('med-rows');

    function fmt(n) { return Math.round(n).toLocaleString('en-US') + ' RWF'; }

    function addRow() {
        var d = document.createElement('div');
        d.className = 'form-grid';
        d.style.cssText = 'grid-template-columns:2fr 70px 110px 1.4fr auto;gap:8px;align-items:end;margin-bottom:8px';
        d.innerHTML = '<input name="med_desc[]" type="text" maxlength="200" placeholder="Medicine name">' +
            '<input name="med_qty[]" type="number" min="1" max="999" value="1" class="bill-qty" aria-label="Quantity">' +
            '<input name="med_price[]" type="number" min="0" step="1" placeholder="Unit price" class="bill-price" aria-label="Unit price">' +
            '<select name="med_by[]" aria-label="Given by">' + STAFF_OPTIONS + '</select>' +
            '<button type="button" class="btn btn-secondary btn-xs rm" aria-label="Remove">✕</button>';
        rows.appendChild(d);
    }

    function recalc() {
        var total = parseInt($('b-consult').value || '0', 10) || 0;
        total += parseInt($('b-oa').value || '0', 10) || 0;
        rows.querySelectorAll('.form-grid').forEach(function (r) {
            var q = parseInt(r.querySelector('.bill-qty').value || '0', 10) || 0;
            var p = parseInt(r.querySelector('.bill-price').value || '0', 10) || 0;
            total += q * p;
        });
        var p = PATIENTS[$('b-patient').value];
        var pct = p ? p.pct : 0;
        var ins = Math.min(total, Math.max(0, Math.round(total * pct / 100)));
        $('s-total').textContent = fmt(total);
        $('s-ins-label').textContent = 'Insurance pays' + (p ? ' (' + p.ins + ', ' + pct + '%)' : '');
        $('s-ins').textContent = fmt(ins);
        $('s-pat').textContent = fmt(total - ins);
        $('b-ins').textContent = p ? 'Insurance: ' + p.ins + ' — pays ' + pct + '%' : 'Insurance: —';
    }

    $('add-med').addEventListener('click', function () { addRow(); recalc(); });
    rows.addEventListener('click', function (e) { if (e.target.classList.contains('rm')) { e.target.closest('.form-grid').remove(); recalc(); } });
    $('bill-form').addEventListener('input', recalc);
    $('b-patient').addEventListener('change', recalc);
    addRow();
    recalc();
})();
</script>
<?php renderFoot(); ?>
