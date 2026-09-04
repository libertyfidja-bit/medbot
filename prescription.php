<?php
require_once 'connect.php';
require_once 'layout.php';
$db = getDB();
$prefillPatient = (int)($_GET['patient_id'] ?? 0);
$message = ''; $msgType = 'success';

// Handle form submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'add_rx') {
    $patientId  = (int)($_POST['patient_id'] ?? 0);
    $drugName   = trim($_POST['drug_name'] ?? '');
    $rxcui      = trim($_POST['rxcui'] ?? '');
    $dosage     = (float)($_POST['dosage_mg'] ?? 0);
    $frequency  = (int)($_POST['frequency_per_day'] ?? 1);
    $startDate  = $_POST['start_date'] ?? date('Y-m-d');
    $endDate    = $_POST['end_date'] ?: null;
    $instructions = trim($_POST['instructions'] ?? '');

    if ($patientId && $drugName && $dosage) {
        // Upsert medication
        $colors = ['#6c63ff','#00d4aa','#ffa94d','#ff6b9d','#4ecdc4','#45b7d1'];
        $color  = $colors[array_rand($colors)];
        $db->query("INSERT INTO medications (rxcui,name,generic_name,color) VALUES ('".
            $db->real_escape_string($rxcui)."','".
            $db->real_escape_string($drugName)."','".
            $db->real_escape_string($drugName)."','$color')
            ON DUPLICATE KEY UPDATE name=VALUES(name)");
        $medId = $db->insert_id ?: $db->query(
            "SELECT id FROM medications WHERE name='". $db->real_escape_string($drugName)."' LIMIT 1"
        )->fetch_row()[0];

        // Insert prescription
        $stmt = $db->prepare("INSERT INTO prescriptions (patient_id,medication_id,dosage_mg,frequency_per_day,start_date,end_date,instructions) VALUES (?,?,?,?,?,?,?)");
        $stmt->bind_param('iiidsss', $patientId, $medId, $dosage, $frequency, $startDate, $endDate, $instructions);
        $stmt->execute();
        $prescriptionId = $db->insert_id;
        $stmt->close();

        // Generate 7-day schedule
        $hoursGap = $frequency > 0 ? (int)(24 / $frequency) : 24;
        for ($day = 0; $day < 7; $day++) {
            $date = date('Y-m-d', strtotime("$startDate +$day days"));
            if ($endDate && $date > $endDate) break;
            for ($i = 0; $i < $frequency; $i++) {
                $hour = (8 + $hoursGap * $i) % 24;
                $time = sprintf('%02d:00:00', $hour);
                $stmt = $db->prepare("INSERT INTO dose_schedule (prescription_id,scheduled_date,scheduled_time) VALUES (?,?,?)");
                $stmt->bind_param('iss', $prescriptionId, $date, $time);
                $stmt->execute(); $stmt->close();
            }
        }

        // Robot log
        $patRow = $db->query("SELECT name,room_number FROM patients WHERE id=$patientId")->fetch_assoc();
        $patName = $patRow['name'] ?? 'Unknown';
        $room    = $patRow['room_number'] ?? 'N/A';
        $stmt = $db->prepare("INSERT INTO robot_log (action_type,medication_name,patient_name,from_location,to_location) VALUES ('dispense',?,?,'pharmacy',?)");
        $stmt->bind_param('sss', $drugName, $patName, $room);
        $stmt->execute(); $stmt->close();

        $message = "✅ Prescription for '$drugName' added! Schedule generated for 7 days.";
        $prefillPatient = $patientId;
    } else {
        $message = '❌ Please fill in all required fields.';
        $msgType = 'error';
    }
}

$patients = [];
$res = $db->query("SELECT id, name, room_number FROM patients ORDER BY name");
while ($r = $res->fetch_assoc()) $patients[] = $r;

// Active prescriptions
$rxList = [];
$res = $db->query("SELECT pr.*,m.name as med_name,m.color,pt.name as patient_name,pt.room_number FROM prescriptions pr JOIN medications m ON pr.medication_id=m.id JOIN patients pt ON pr.patient_id=pt.id WHERE pr.status='active' ORDER BY pr.created_at DESC LIMIT 20");
while ($r = $res->fetch_assoc()) $rxList[] = $r;

renderHead('Prescriptions', 'prescription');
renderTopbar('Prescriptions', 'Add and manage patient prescriptions');
?>
<div class="page-content">
<?php if ($message): ?>
<div class="alert alert-<?= $msgType ?>"><span class="alert-icon"><?= $msgType==='success'?'✅':'❌' ?></span><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="grid-2">
<!-- Add Prescription Form -->
<div class="card">
    <div class="card-header"><div class="card-title">💊 New Prescription</div></div>
    <div class="card-body">
        <form method="POST" id="rx-form">
            <input type="hidden" name="_action" value="add_rx">
            <input type="hidden" name="rxcui" id="rxcui-hidden">
            <div class="form-grid">
                <!-- Patient -->
                <div class="form-group full">
                    <label class="form-label">Patient *</label>
                    <select name="patient_id" required>
                        <option value="">— Select patient —</option>
                        <?php foreach ($patients as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $prefillPatient === $p['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['name']) ?> — Room <?= htmlspecialchars($p['room_number'] ?: 'N/A') ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($patients)): ?>
                    <div class="input-hint">⚠️ No patients yet. <a href="/medbot/patients.php" style="color:var(--accent)">Add a patient first →</a></div>
                    <?php endif; ?>
                </div>
                <!-- Drug name with autocomplete -->
                <div class="form-group full">
                    <label class="form-label">Drug Name * <span style="font-weight:400;font-size:10px;color:var(--text-3)">(auto-complete from RxNorm)</span></label>
                    <div class="autocomplete-wrap">
                        <input type="text" name="drug_name" id="drug-name-input" placeholder="Start typing... e.g. Metformin" autocomplete="off" required>
                        <div class="autocomplete-list" id="autocomplete-list"></div>
                    </div>
                </div>
                <!-- Dosage -->
                <div class="form-group">
                    <label class="form-label">Dosage (mg) *</label>
                    <input type="number" name="dosage_mg" min="0.1" step="0.1" placeholder="e.g. 500" required>
                </div>
                <!-- Frequency -->
                <div class="form-group">
                    <label class="form-label">Times per Day *</label>
                    <select name="frequency_per_day" required>
                        <option value="1">Once daily (8:00 AM)</option>
                        <option value="2">Twice daily (8 AM, 8 PM)</option>
                        <option value="3">Three times daily</option>
                        <option value="4">Four times daily</option>
                    </select>
                </div>
                <!-- Dates -->
                <div class="form-group">
                    <label class="form-label">Start Date *</label>
                    <input type="date" name="start_date" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">End Date (optional)</label>
                    <input type="date" name="end_date">
                </div>
                <!-- Instructions -->
                <div class="form-group full">
                    <label class="form-label">Instructions</label>
                    <textarea name="instructions" placeholder="e.g. Take with food, avoid alcohol..."></textarea>
                </div>
            </div>

            <!-- Interaction checker -->
            <div style="margin:16px 0;padding:14px;background:var(--bg-3);border-radius:var(--radius-sm);border:1px solid var(--border)">
                <div style="font-size:12px;font-weight:600;color:var(--text-2);margin-bottom:10px;text-transform:uppercase;letter-spacing:.5px">🔬 Drug Interaction Checker</div>
                <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
                    <div style="flex:1;min-width:140px">
                        <label class="form-label">Drug 1 (auto-filled)</label>
                        <input type="text" id="check-drug1" placeholder="Drug name" readonly style="cursor:default">
                    </div>
                    <div style="flex:1;min-width:140px">
                        <label class="form-label">Drug 2 (check against)</label>
                        <input type="text" id="check-drug2" placeholder="e.g. Warfarin">
                    </div>
                    <button type="button" class="btn btn-warning btn-sm" onclick="checkInteraction()">🔬 Check</button>
                </div>
                <div id="interaction-result"></div>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px">
                <button type="reset" class="btn btn-secondary">Clear</button>
                <button type="submit" class="btn btn-primary" id="btn-submit" disabled>💊 Add Prescription</button>
            </div>
        </form>
    </div>
</div>

<!-- Active Prescriptions -->
<div class="card">
    <div class="card-header">
        <div class="card-title">📋 Active Prescriptions</div>
        <span class="badge badge-info"><?= count($rxList) ?></span>
    </div>
    <?php if (empty($rxList)): ?>
    <div style="padding:40px;text-align:center;color:var(--text-3)">
        <div style="font-size:40px;margin-bottom:12px">💊</div>
        No active prescriptions yet.
    </div>
    <?php else: ?>
    <?php foreach ($rxList as $rx): ?>
    <div class="dose-item">
        <div class="dose-pill" style="background:<?= htmlspecialchars($rx['color']) ?>22;font-size:20px">💊</div>
        <div class="dose-info">
            <div class="dose-name"><?= htmlspecialchars($rx['med_name']) ?></div>
            <div class="dose-detail">
                <?= $rx['dosage_mg'] ?>mg × <?= $rx['frequency_per_day'] ?>×/day &bull;
                <?= htmlspecialchars($rx['patient_name']) ?> &bull; Room <?= htmlspecialchars($rx['room_number'] ?: 'N/A') ?>
            </div>
            <div class="dose-detail" style="color:var(--text-3);font-size:11px;margin-top:2px">
                Since <?= date('M j, Y', strtotime($rx['start_date'])) ?>
                <?php if ($rx['end_date']): ?> → <?= date('M j, Y', strtotime($rx['end_date'])) ?><?php endif; ?>
            </div>
        </div>
        <span class="badge badge-safe">Active</span>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>
</div>
</div>

<script>
// RxNorm drug name autocomplete
let debounceTimer;
const drugInput = document.getElementById('drug-name-input');
const acList = document.getElementById('autocomplete-list');
const rxcuiHidden = document.getElementById('rxcui-hidden');
const checkDrug1 = document.getElementById('check-drug1');

drugInput.addEventListener('input', () => {
    clearTimeout(debounceTimer);
    const q = drugInput.value.trim();
    checkDrug1.value = q;
    if (q.length < 2) { acList.classList.remove('show'); return; }
    debounceTimer = setTimeout(() => fetchDrugSuggestions(q), 300);
});

async function fetchDrugSuggestions(q) {
    try {
        const res = await fetch(`https://rxnav.nlm.nih.gov/REST/spellingsuggestions.json?name=${encodeURIComponent(q)}`);
        const data = await res.json();
        const suggestions = data.suggestionGroup?.suggestionList?.suggestion || [];
        // Also try direct search
        const res2 = await fetch(`https://rxnav.nlm.nih.gov/REST/drugs.json?name=${encodeURIComponent(q)}`);
        const data2 = await res2.json();
        const concepts = data2.drugGroup?.conceptGroup || [];
        const names = new Set(suggestions.slice(0,5));
        concepts.forEach(g => (g.conceptProperties || []).slice(0,3).forEach(c => names.add(c.name)));
        showSuggestions([...names].slice(0,8));
    } catch(e) { acList.classList.remove('show'); }
}

function showSuggestions(names) {
    if (!names.length) { acList.classList.remove('show'); return; }
    acList.innerHTML = names.map(n =>
        `<div class="autocomplete-item" onclick="selectDrug('${n.replace(/'/g,"\\'")}')">💊 ${n}</div>`
    ).join('');
    acList.classList.add('show');
}

function selectDrug(name) {
    drugInput.value = name;
    checkDrug1.value = name;
    acList.classList.remove('show');
    // Fetch RxCUI
    fetch(`https://rxnav.nlm.nih.gov/REST/rxcui.json?name=${encodeURIComponent(name)}&search=1`)
        .then(r => r.json())
        .then(d => { rxcuiHidden.value = d.idGroup?.rxnormId?.[0] || ''; });
}

document.addEventListener('click', e => { if (!e.target.closest('.autocomplete-wrap')) acList.classList.remove('show'); });

// Drug interaction checker
async function checkInteraction() {
    const d1 = document.getElementById('check-drug1').value.trim();
    const d2 = document.getElementById('check-drug2').value.trim();
    const resultDiv = document.getElementById('interaction-result');
    if (!d1) { showToast('Enter a drug name first', 'warning'); return; }
    resultDiv.innerHTML = '<div style="padding:10px;text-align:center;color:var(--text-3);font-size:13px">🔍 Checking interactions...</div>';
    try {
        const params = new URLSearchParams({ drug1: d1, drug2: d2 });
        const res = await fetch(`/medbot/api/check_interaction.php?${params}`);
        const data = await res.json();
        if (data.error) { resultDiv.innerHTML = `<div class="interaction-result warning"><div class="interaction-label">⚠️ Could not check</div><div class="interaction-details">${data.message}</div></div>`; return; }
        if (data.safe && !data.interactions?.length) {
            resultDiv.innerHTML = `<div class="interaction-result safe"><div class="interaction-label" style="color:var(--teal)">✅ No Known Interactions</div><div class="interaction-details">${d2 ? `No interactions detected between <strong>${d1}</strong> and <strong>${d2}</strong>.` : `No specific interaction data found for <strong>${d1}</strong>.`}</div></div>`;
        } else {
            const sev = data.overall_severity;
            const icon = sev==='danger'?'🚫':sev==='warning'?'⚠️':'ℹ️';
            const desc = data.interactions.map(i => `<li>${i.description}</li>`).join('');
            resultDiv.innerHTML = `<div class="interaction-result ${sev}"><div class="interaction-label">${icon} ${sev.toUpperCase()} Interaction</div><div class="interaction-details"><ul style="padding-left:16px;margin:6px 0">${desc}</ul></div></div>`;
        }
    } catch(e) {
        resultDiv.innerHTML = '<div class="interaction-result warning"><div class="interaction-label">⚠️ API Unavailable</div><div class="interaction-details">Could not reach RxNorm. Check your internet connection.</div></div>';
    }
}

// Real-time form validation
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('rx-form');
    const submitBtn = document.getElementById('btn-submit');
    const requiredInputs = form.querySelectorAll('input[required], select[required]');
    
    function validate() {
        let valid = true;
        requiredInputs.forEach(input => {
            if (!input.value.trim()) valid = false;
        });
        submitBtn.disabled = !valid;
    }
    
    form.addEventListener('input', validate);
    form.addEventListener('change', validate);
    // Observe the hidden RXCUI or autocomplete selections
    const observer = new MutationObserver(validate);
    observer.observe(document.getElementById('rxcui-hidden'), { attributes: true, childList: true, subtree: true });
    
    validate(); // Initial check
});
</script>
<?php renderFoot(); ?>
