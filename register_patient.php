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

/** Reads insurer, member number and coverage override from the form. Returns [ok, values or error]. */
function reg_read_insurance(mysqli $db): array {
    $insurerId = (int) ($_POST['insurer_id'] ?? 0);
    if (!auth_row($db, 'SELECT id FROM insurers WHERE id = ? AND active = 1', 'i', [$insurerId])) return [false, 'Choose the insurance (or “No insurance”).'];
    $member = mb_substr(trim(strip_tags((string) ($_POST['member_no'] ?? ''))), 0, 60);
    $ovr = trim((string) ($_POST['coverage_override'] ?? ''));
    $override = null;
    if ($ovr !== '') {
        if (!is_numeric($ovr) || (float) $ovr < 0 || (float) $ovr > 100) return [false, 'Coverage must be a number from 0 to 100.'];
        $override = (float) $ovr;
    }
    return [true, ['insurer_id' => $insurerId, 'member_no' => $member, 'override' => $override]];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $flash = ['type' => 'error', 'msg' => 'This page expired. Please try again.'];

    if (auth_csrf_ok()) {
        $act = (string) ($_POST['action'] ?? '');

        if ($act === 'register') {
            $name  = mb_substr(trim(strip_tags((string) ($_POST['name'] ?? ''))), 0, 150);
            $ageIn = trim((string) ($_POST['age'] ?? ''));
            $room  = mb_substr(trim(strip_tags((string) ($_POST['room'] ?? ''))), 0, 20);
            $notes = mb_substr(trim(strip_tags((string) ($_POST['notes'] ?? ''))), 0, 1000);
            $phone = bill_phone($_POST['phone'] ?? '');
            [$okIns, $ins] = reg_read_insurance($db);

            if (mb_strlen($name) < 2) {
                $flash = ['type' => 'error', 'msg' => 'Enter the patient’s full name.'];
            } elseif ($ageIn !== '' && (!ctype_digit($ageIn) || (int) $ageIn > 130)) {
                $flash = ['type' => 'error', 'msg' => 'Age must be a number between 0 and 130.'];
            } elseif ($phone === '') {
                $flash = ['type' => 'error', 'msg' => 'Enter the patient’s mobile number (07XXXXXXXX). It is used to check who pays.'];
            } elseif (!$okIns) {
                $flash = ['type' => 'error', 'msg' => $ins];
            } else {
                $age = $ageIn === '' ? null : (int) $ageIn;
                auth_exec($db, 'INSERT INTO patients (name, age, room_number, condition_notes) VALUES (?,?,?,?)', 'siss', [$name, $age, $room, $notes]);
                $pid = (int) $db->insert_id;
                if ($pid <= 0) {
                    $flash = ['type' => 'error', 'msg' => 'Could not save the patient. Please try again.'];
                } else {
                    auth_exec($db, 'INSERT INTO patient_profile (patient_id, phone, insurer_id, member_no, coverage_override) VALUES (?,?,?,?,?)', 'isisd', [$pid, $phone, $ins['insurer_id'], $ins['member_no'], $ins['override']]);
                    $uid = auth_create_patient_account($db, $pid, $name, $myId > 0 ? $myId : null);
                    if ($uid) {
                        $token = auth_new_access_link($db, $uid, $myId > 0 ? $myId : null);
                        $flash = ['type' => 'ok', 'msg' => $name . ' is registered.', 'link' => auth_access_url($token), 'who' => $name];
                    } else {
                        $flash = ['type' => 'ok', 'msg' => $name . ' is registered, but the sign-in account could not be created. Use “Give access” below.'];
                    }
                }
            }
        } elseif ($act === 'save_insurance') {
            $pid   = (int) ($_POST['patient_id'] ?? 0);
            $phone = bill_phone($_POST['phone'] ?? '');
            [$okIns, $ins] = reg_read_insurance($db);
            if (!auth_row($db, 'SELECT id FROM patients WHERE id = ?', 'i', [$pid])) {
                $flash = ['type' => 'error', 'msg' => 'Patient not found.'];
            } elseif ($phone === '') {
                $flash = ['type' => 'error', 'msg' => 'Enter a valid mobile number (07XXXXXXXX).'];
            } elseif (!$okIns) {
                $flash = ['type' => 'error', 'msg' => $ins];
            } else {
                auth_exec(
                    $db,
                    'INSERT INTO patient_profile (patient_id, phone, insurer_id, member_no, coverage_override) VALUES (?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE phone = VALUES(phone), insurer_id = VALUES(insurer_id), member_no = VALUES(member_no), coverage_override = VALUES(coverage_override)',
                    'isisd',
                    [$pid, $phone, $ins['insurer_id'], $ins['member_no'], $ins['override']]
                );
                $flash = ['type' => 'ok', 'msg' => 'Insurance and phone saved.'];
            }
        } elseif ($act === 'give_access') {
            $pid = (int) ($_POST['patient_id'] ?? 0);
            $pt  = auth_row($db, 'SELECT id, name FROM patients WHERE id = ?', 'i', [$pid]);
            if (!$pt) {
                $flash = ['type' => 'error', 'msg' => 'Patient not found.'];
            } elseif (auth_row($db, 'SELECT id FROM users WHERE patient_id = ?', 'i', [$pid])) {
                $flash = ['type' => 'error', 'msg' => 'This patient already has access. Only an administrator can make a new link.'];
            } else {
                $uid = auth_create_patient_account($db, $pid, $pt['name'], $myId > 0 ? $myId : null);
                if ($uid) {
                    $token = auth_new_access_link($db, $uid, $myId > 0 ? $myId : null);
                    $flash = ['type' => 'ok', 'msg' => 'Access created for ' . $pt['name'] . '.', 'link' => auth_access_url($token), 'who' => $pt['name']];
                } else {
                    $flash = ['type' => 'error', 'msg' => 'Could not create the account. Please try again.'];
                }
            }
        }
    }
    $_SESSION['flash'] = $flash;
    header('Location: register_patient.php');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$insurers = [];
$res = $db->query('SELECT id, name, kind, coverage_pct FROM insurers WHERE active = 1 ORDER BY sort_order, name');
if ($res) { while ($r = $res->fetch_assoc()) $insurers[] = $r; $res->free(); }

$patients = [];
$res = $db->query('SELECT p.id, p.name, p.room_number, pp.phone, pp.insurer_id, pp.member_no, pp.coverage_override, i.name AS insurer_name, i.coverage_pct,
                          (SELECT COUNT(*) FROM users u WHERE u.patient_id = p.id) AS has_account
                   FROM patients p LEFT JOIN patient_profile pp ON pp.patient_id = p.id LEFT JOIN insurers i ON i.id = pp.insurer_id
                   ORDER BY p.id DESC LIMIT 100');
if ($res) { while ($r = $res->fetch_assoc()) $patients[] = $r; $res->free(); }

$csrf = auth_csrf();

function reg_insurer_options(array $insurers, ?int $selected): string {
    $html = '<option value="">— Choose insurance —</option>';
    foreach ($insurers as $i) {
        $sel = ($selected !== null && (int) $i['id'] === $selected) ? ' selected' : '';
        $html .= '<option value="' . (int) $i['id'] . '" data-pct="' . auth_h($i['coverage_pct']) . '"' . $sel . '>' . auth_h($i['name']) . ' (' . auth_h(rtrim(rtrim(number_format((float) $i['coverage_pct'], 2, '.', ''), '0'), '.')) . '%)</option>';
    }
    return $html;
}

renderHead('Register patient', 'register');
renderTopbar('Register patient', 'Registration, insurance and patient access');
?>
<div class="page-content">

<?php if ($flash): ?>
    <div class="alert <?= $flash['type'] === 'ok' ? 'alert-success' : 'alert-error' ?>" role="status">
        <span class="alert-icon"><?= $flash['type'] === 'ok' ? '✅' : '⚠️' ?></span>
        <div style="flex:1;min-width:0">
            <?= auth_h($flash['msg']) ?>
            <?php if (!empty($flash['link'])): ?>
                <div style="margin-top:10px;display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start">
                    <div id="qr" style="background:#fff;padding:8px;border-radius:8px;width:176px;height:176px"></div>
                    <div style="flex:1;min-width:220px">
                        <strong>Give <?= auth_h($flash['who']) ?> this access.</strong> Scan the QR code with the patient’s phone, or send the link. It works <u>once</u>, then that phone is remembered for 30 days. It expires in 72 hours. No password is needed.
                        <input id="access-link" type="text" readonly value="<?= auth_h($flash['link']) ?>" style="margin-top:8px;font-family:var(--mono);font-size:12px" onclick="this.select()">
                        <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">
                            <button type="button" class="btn btn-secondary btn-sm" id="copy-link">📋 Copy link</button>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="window.print()">🖨️ Print</button>
                        </div>
                        <div style="font-size:12px;margin-top:8px">This link is shown only now. If it is lost, an administrator can create a new one.</div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

    <div class="card">
        <div class="card-header"><div class="card-title">➕ Register a new patient</div></div>
        <div class="card-body">
            <form method="post" action="register_patient.php" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= auth_h($csrf) ?>">
                <input type="hidden" name="action" value="register">
                <div class="form-grid">
                    <div class="form-group"><label class="form-label" for="r-name">Full name</label><input id="r-name" name="name" type="text" maxlength="150" required></div>
                    <div class="form-group"><label class="form-label" for="r-phone">Mobile number</label><input id="r-phone" name="phone" type="tel" inputmode="tel" placeholder="07XXXXXXXX" maxlength="16" required><div class="input-hint">Used to check that the person who pays is this patient.</div></div>
                    <div class="form-group"><label class="form-label" for="r-age">Age</label><input id="r-age" name="age" type="number" min="0" max="130"></div>
                    <div class="form-group"><label class="form-label" for="r-room">Room</label><input id="r-room" name="room" type="text" maxlength="20"></div>
                    <div class="form-group"><label class="form-label" for="r-ins">Insurance</label><select id="r-ins" name="insurer_id" required><?= reg_insurer_options($insurers, null) ?></select></div>
                    <div class="form-group"><label class="form-label" for="r-member">Insurance member number</label><input id="r-member" name="member_no" type="text" maxlength="60"></div>
                    <div class="form-group"><label class="form-label" for="r-ovr">Insurer pays (%) — only if different</label><input id="r-ovr" name="coverage_override" type="number" min="0" max="100" step="0.01" placeholder="leave empty to use the default"><div class="input-hint" id="r-hint">Default share is shown after you choose the insurance. Check the member’s card or policy.</div></div>
                    <div class="form-group full"><label class="form-label" for="r-notes">Condition notes (optional)</label><textarea id="r-notes" name="notes" rows="2" maxlength="1000"></textarea></div>
                </div>
                <div style="margin-top:14px"><button class="btn btn-primary" type="submit">Register and create access</button></div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><div class="card-title">👥 Patients, insurance and access</div></div>
        <div class="table-wrap">
        <table class="dt">
            <thead><tr><th>Patient</th><th>Phone</th><th>Insurance</th><th>Access</th><th></th></tr></thead>
            <tbody>
            <?php if (!$patients): ?><tr><td colspan="5" style="text-align:center;color:var(--text-3);padding:24px">No patients yet.</td></tr><?php endif; ?>
            <?php foreach ($patients as $p):
                $pct = $p['coverage_override'] !== null ? (float) $p['coverage_override'] : (float) ($p['coverage_pct'] ?? 0); ?>
                <tr>
                    <td><?= auth_h($p['name']) ?><?= $p['room_number'] ? '<div style="font-size:11.5px;color:var(--text-3)">Room ' . auth_h($p['room_number']) . '</div>' : '' ?></td>
                    <td style="font-family:var(--mono)"><?= $p['phone'] ? auth_h(bill_mask_phone((string) $p['phone'])) : '<span style="color:var(--warning)">missing</span>' ?></td>
                    <td><?= $p['insurer_name'] ? auth_h($p['insurer_name']) . '<div style="font-size:11.5px;color:var(--text-3)">insurer pays ' . auth_h(rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.')) . '%</div>' : '<span style="color:var(--warning)">not set</span>' ?></td>
                    <td><?php if ((int) $p['has_account'] > 0): ?><span class="badge badge-safe">Has access</span><?php else: ?>
                        <form method="post" action="register_patient.php"><input type="hidden" name="csrf" value="<?= auth_h($csrf) ?>"><input type="hidden" name="action" value="give_access"><input type="hidden" name="patient_id" value="<?= (int) $p['id'] ?>"><button class="btn btn-teal btn-xs" type="submit">Give access</button></form>
                    <?php endif; ?></td>
                    <td>
                        <details>
                            <summary style="cursor:pointer;color:var(--accent);font-size:12.5px">Edit insurance / phone</summary>
                            <form method="post" action="register_patient.php" style="margin-top:8px;display:grid;gap:8px;min-width:240px">
                                <input type="hidden" name="csrf" value="<?= auth_h($csrf) ?>"><input type="hidden" name="action" value="save_insurance"><input type="hidden" name="patient_id" value="<?= (int) $p['id'] ?>">
                                <input name="phone" type="tel" placeholder="07XXXXXXXX" value="<?= auth_h($p['phone']) ?>" required>
                                <select name="insurer_id" required><?= reg_insurer_options($insurers, $p['insurer_id'] !== null ? (int) $p['insurer_id'] : null) ?></select>
                                <input name="member_no" type="text" maxlength="60" placeholder="Member number" value="<?= auth_h($p['member_no']) ?>">
                                <input name="coverage_override" type="number" min="0" max="100" step="0.01" placeholder="Insurer pays % (optional)" value="<?= $p['coverage_override'] !== null ? auth_h($p['coverage_override']) : '' ?>">
                                <button class="btn btn-primary btn-xs" type="submit">Save</button>
                            </form>
                        </details>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<?php if ($flash && !empty($flash['link'])): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
(function () {
    var link = document.getElementById('access-link').value;
    try { new QRCode(document.getElementById('qr'), { text: link, width: 160, height: 160, correctLevel: QRCode.CorrectLevel.M }); }
    catch (e) { document.getElementById('qr').textContent = 'QR unavailable — use the link.'; }
    document.getElementById('copy-link').addEventListener('click', function () {
        var i = document.getElementById('access-link'); i.select();
        try { navigator.clipboard.writeText(i.value); } catch (e) { document.execCommand('copy'); }
        this.textContent = '✅ Copied';
    });
})();
</script>
<?php endif; ?>
<script>
(function () {
    var sel = document.getElementById('r-ins'), hint = document.getElementById('r-hint');
    if (!sel) return;
    sel.addEventListener('change', function () {
        var o = sel.options[sel.selectedIndex];
        hint.textContent = o && o.dataset.pct ? 'Default: the insurer pays ' + parseFloat(o.dataset.pct) + '% and the patient pays the rest. Change it only if the member’s policy says otherwise.' : 'Choose the insurance to see the default share.';
    });
})();
</script>
<?php renderFoot(); ?>
