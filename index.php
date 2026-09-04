<?php require_once 'connect.php'; require_once 'layout.php';
$db = getDB();
$patientCount    = $db->query("SELECT COUNT(*) FROM patients")->fetch_row()[0];
$activePrescriptions = $db->query("SELECT COUNT(*) FROM prescriptions WHERE status='active'")->fetch_row()[0];
$todayDoses      = $db->query("SELECT COUNT(*) FROM dose_schedule WHERE scheduled_date=CURDATE()")->fetch_row()[0];
$missedDoses     = $db->query("SELECT COUNT(*) FROM dose_schedule WHERE scheduled_date=CURDATE() AND status='missed'")->fetch_row()[0];
$alertCount      = $db->query("SELECT COUNT(*) FROM interaction_alerts WHERE resolved=0")->fetch_row()[0];
$takenToday      = $db->query("SELECT COUNT(*) FROM dose_schedule WHERE scheduled_date=CURDATE() AND status IN('taken','dispensed')")->fetch_row()[0];
$recentLogs = [];
$res = $db->query("SELECT * FROM robot_log ORDER BY created_at DESC LIMIT 8");
while ($r = $res->fetch_assoc()) $recentLogs[] = $r;
$alerts = [];
$res = $db->query("SELECT ia.*,p.name as patient_name,p.room_number FROM interaction_alerts ia JOIN patients p ON ia.patient_id=p.id WHERE ia.resolved=0 ORDER BY ia.flagged_at DESC LIMIT 5");
while ($r = $res->fetch_assoc()) $alerts[] = $r;
$todaySchedule = [];
$res = $db->query("SELECT ds.*,m.name as med_name,m.color,pr.dosage_mg,pt.name as patient_name,pt.room_number FROM dose_schedule ds JOIN prescriptions pr ON ds.prescription_id=pr.id JOIN medications m ON pr.medication_id=m.id JOIN patients pt ON pr.patient_id=pt.id WHERE ds.scheduled_date=CURDATE() ORDER BY ds.scheduled_time ASC LIMIT 15");
while ($r = $res->fetch_assoc()) $todaySchedule[] = $r;
renderHead('Dashboard', 'dashboard');
renderTopbar('Dashboard', date('l, F j, Y'));
?>
<div class="page-content">
<div class="robot-status" id="robot-status-bar">
    <div class="robot-dot" id="robot-dot"></div>
    <span>🤖 MedBot: </span><strong id="robot-status-text">Loading...</strong>
    <span id="robot-location" style="margin-left:auto;font-size:12px"></span>
    <a href="/medbot/robot.php" class="btn btn-secondary btn-sm" style="margin-left:12px">🦾 3D View</a>
    <a href="/medbot/pathfinding.php" class="btn btn-secondary btn-sm">🗺️ Map</a>
</div>
<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon">👥</div><div class="stat-value"><?= $patientCount ?></div><div class="stat-label">Patients</div></div>
    <div class="stat-card"><div class="stat-icon">💊</div><div class="stat-value"><?= $activePrescriptions ?></div><div class="stat-label">Active Rx</div></div>
    <div class="stat-card"><div class="stat-icon">✅</div><div class="stat-value teal"><?= $takenToday ?><span style="font-size:16px;color:var(--text-3)"> / <?= $todayDoses ?></span></div><div class="stat-label">Doses Today</div></div>
    <div class="stat-card"><div class="stat-icon">❌</div><div class="stat-value danger"><?= $missedDoses ?></div><div class="stat-label">Missed</div></div>
    <div class="stat-card"><div class="stat-icon">⚠️</div><div class="stat-value danger"><?= $alertCount ?></div><div class="stat-label">Alerts</div></div>
</div>
<div class="grid-2">
<div class="card">
    <div class="card-header"><div class="card-title">📅 Today's Schedule</div><a href="/medbot/schedule.php" class="btn btn-secondary btn-sm">View All</a></div>
    <?php if (empty($todaySchedule)): ?>
    <div style="padding:40px;text-align:center;color:var(--text-3)"><div style="font-size:40px;margin-bottom:12px">💊</div>No doses scheduled today.<br><a href="/medbot/prescription.php" class="btn btn-primary btn-sm" style="margin-top:12px">+ Add Prescription</a></div>
    <?php else: ?>
    <?php foreach ($todaySchedule as $dose):
        $emoji = match($dose['status']) { 'taken','dispensed'=>'✅','missed'=>'❌','skipped'=>'⏭️',default=>'🕐' };
        $color = match($dose['status']) { 'taken','dispensed'=>'var(--teal)','missed'=>'var(--danger)',default=>'var(--text-3)' };
    ?>
    <div class="dose-item">
        <div class="dose-pill" style="background:<?= htmlspecialchars($dose['color']) ?>22"><?= $emoji ?></div>
        <div class="dose-info">
            <div class="dose-name"><?= htmlspecialchars($dose['med_name']) ?></div>
            <div class="dose-detail"><?= $dose['dosage_mg'] ?>mg &bull; <?= htmlspecialchars($dose['patient_name']) ?> &bull; <?= htmlspecialchars($dose['room_number']) ?></div>
        </div>
        <div style="text-align:right"><div class="dose-time"><?= substr($dose['scheduled_time'],0,5) ?></div><div style="font-size:11px;color:<?= $color ?>;font-weight:600"><?= strtoupper($dose['status']) ?></div></div>
        <?php if ($dose['status']==='pending'): ?>
        <button class="btn btn-teal btn-xs" onclick="dispense(<?= $dose['id'] ?>,'<?= addslashes($dose['med_name']) ?>','<?= addslashes($dose['patient_name']) ?>','<?= htmlspecialchars($dose['room_number']) ?>')">🤖 Dispense</button>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>
<div>
    <div class="card" style="margin-bottom:18px">
        <div class="card-header"><div class="card-title">📈 Weekly Adherence</div></div>
        <div class="adherence-ring-wrap">
            <svg width="130" height="130" viewBox="0 0 130 130">
                <circle cx="65" cy="65" r="52" fill="none" stroke="var(--bg-3)" stroke-width="12"/>
                <circle cx="65" cy="65" r="52" fill="none" stroke="url(#grad)" stroke-width="12"
                    stroke-dasharray="327" stroke-dashoffset="327" stroke-linecap="round"
                    transform="rotate(-90 65 65)" id="adherence-circle"/>
                <defs><linearGradient id="grad" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0%" stop-color="var(--teal)"/>
                    <stop offset="100%" stop-color="var(--accent)"/>
                </linearGradient></defs>
            </svg>
            <div class="adherence-pct" id="adherence-pct">--%</div>
            <div class="adherence-label">7-day adherence</div>
        </div>
    </div>
    <?php if (!empty($alerts)): ?>
    <div class="card">
        <div class="card-header"><div class="card-title">⚠️ Alerts</div><a href="/medbot/alerts.php" class="btn btn-secondary btn-sm">All</a></div>
        <?php foreach ($alerts as $a): $cls=$a['severity']==='danger'?'error':($a['severity']==='warning'?'warning':'info'); ?>
        <div class="alert alert-<?= $cls ?>" style="margin:10px;border-radius:8px">
            <span class="alert-icon"><?= $a['severity']==='danger'?'🚫':'⚠️' ?></span>
            <div style="flex:1"><strong><?= htmlspecialchars($a['patient_name']) ?></strong> — <?= htmlspecialchars($a['drug_a']) ?><?= $a['drug_b']?' + '.htmlspecialchars($a['drug_b']):'' ?><div style="font-size:11.5px;margin-top:2px;opacity:.8"><?= htmlspecialchars(mb_strimwidth($a['description'],0,80,'…')) ?></div></div>
            <button class="btn btn-xs" style="background:none;border:1px solid currentColor;border-radius:6px;cursor:pointer;font-size:11px;padding:3px 7px" onclick="resolveAlert(<?= $a['id'] ?>)">✓</button>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
</div>
<div class="card">
    <div class="card-header"><div class="card-title">🤖 Robot Activity Log</div></div>
    <div class="activity-log" id="activity-log">
    <?php if (empty($recentLogs)): ?>
    <div style="padding:24px;text-align:center;color:var(--text-3)">No robot activity yet. Add patients and prescriptions to get started!</div>
    <?php else: foreach ($recentLogs as $log):
        $icon = match($log['action_type']) { 'dispense'=>'💊','pick'=>'🦾','place'=>'📦','navigate'=>'🗺️','alert'=>'⚠️',default=>'🤖' }; ?>
    <div class="log-item"><span class="log-icon"><?= $icon ?></span><span class="log-text"><strong><?= ucfirst($log['action_type']) ?></strong><?= $log['medication_name']?' — '.htmlspecialchars($log['medication_name']):'' ?><?= $log['patient_name']?' for '.htmlspecialchars($log['patient_name']):'' ?><?= $log['to_location']?' → '.htmlspecialchars($log['to_location']):'' ?></span><span class="log-time"><?= date('H:i',strtotime($log['created_at'])) ?></span></div>
    <?php endforeach; endif; ?>
    </div>
</div>
</div>
<script>
async function dispense(doseId, med, patient, room) {
    showToast(`🤖 MedBot dispensing ${med}...`, 'info');
    const dot = document.getElementById('robot-dot');
    if (dot) dot.classList.add('busy');
    const fd = new FormData();
    fd.append('action','dispense'); fd.append('dose_id',doseId);
    fd.append('medication_name',med); fd.append('patient_name',patient); fd.append('room',room);
    const res = await fetch('/medbot/api/robot_action.php',{method:'POST',body:fd});
    const data = await res.json();
    if (data.success) { showToast(`✅ ${med} dispensed for ${patient}!`,'success'); setTimeout(()=>location.reload(),1500); }
    else showToast('❌ Dispense failed','error');
    if (dot) dot.classList.remove('busy');
}
async function resolveAlert(id) {
    const fd=new FormData(); fd.append('action','resolve_alert'); fd.append('alert_id',id);
    await fetch('/medbot/api/robot_action.php',{method:'POST',body:fd});
    showToast('Alert resolved','success'); setTimeout(()=>location.reload(),700);
}
</script>
<?php renderFoot(); ?>
