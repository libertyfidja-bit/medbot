<?php require_once 'connect.php'; require_once 'layout.php';
$db = getDB();
$todayDoses = [];
$res = $db->query("SELECT ds.*,m.name as med_name,m.color,pr.dosage_mg,pt.name as patient_name,pt.room_number FROM dose_schedule ds JOIN prescriptions pr ON ds.prescription_id=pr.id JOIN medications m ON pr.medication_id=m.id JOIN patients pt ON pr.patient_id=pt.id WHERE ds.scheduled_date=CURDATE() AND ds.status='pending' ORDER BY ds.scheduled_time ASC LIMIT 10");
while ($r = $res->fetch_assoc()) $todayDoses[] = $r;
renderHead('Robot View', 'robot');
renderTopbar('3D Robot View', 'Live simulated medication dispensing robot');
?>
<div class="page-content" style="padding:16px">
<div style="display:grid;grid-template-columns:1fr 320px;gap:16px;height:calc(100vh - 120px)">

<!-- 3D Viewport -->
<div class="card" style="margin-bottom:0;overflow:hidden;position:relative">
    <div id="robot-canvas-container" style="width:100%;height:100%;background:#050810"></div>
    <div style="position:absolute;top:12px;left:12px;display:flex;gap:8px;flex-wrap:wrap">
        <span class="badge badge-info" id="arm-status">🦾 IDLE</span>
        <span class="badge badge-gray" id="arm-pos">Position: Home</span>
    </div>
    <div style="position:absolute;bottom:12px;left:12px;right:12px;display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn btn-secondary btn-sm" onclick="robot3D.goHome()">🏠 Home</button>
        <button class="btn btn-secondary btn-sm" onclick="robot3D.toggleAutoRotate()">🔄 Auto-Rotate</button>
        <button class="btn btn-primary btn-sm" onclick="robot3D.demoSequence()">▶ Demo Sequence</button>
    </div>
</div>

<!-- Control Panel -->
<div style="display:flex;flex-direction:column;gap:14px;overflow-y:auto">
    <!-- Robot State -->
    <div class="card" style="margin-bottom:0">
        <div class="card-header" style="padding:12px 16px"><div class="card-title">🤖 Robot State</div></div>
        <div class="card-body" style="padding:12px 16px">
            <div style="display:flex;flex-direction:column;gap:8px;font-size:13px">
                <div style="display:flex;justify-content:space-between"><span style="color:var(--text-3)">Status</span><span class="badge badge-safe" id="state-status">Idle</span></div>
                <div style="display:flex;justify-content:space-between"><span style="color:var(--text-3)">Location</span><span id="state-location" style="color:var(--text-1);font-weight:500">Pharmacy</span></div>
                <div style="display:flex;justify-content:space-between"><span style="color:var(--text-3)">Joint 1</span><span id="joint-1" style="font-family:var(--mono);font-size:12px;color:var(--accent)">0.0°</span></div>
                <div style="display:flex;justify-content:space-between"><span style="color:var(--text-3)">Joint 2</span><span id="joint-2" style="font-family:var(--mono);font-size:12px;color:var(--accent)">0.0°</span></div>
                <div style="display:flex;justify-content:space-between"><span style="color:var(--text-3)">Gripper</span><span id="gripper-state" style="font-family:var(--mono);font-size:12px;color:var(--teal)">Open</span></div>
            </div>
        </div>
    </div>

    <!-- Manual Controls -->
    <div class="card" style="margin-bottom:0">
        <div class="card-header" style="padding:12px 16px"><div class="card-title">🕹️ Manual Control</div></div>
        <div class="card-body" style="padding:12px">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px">
                <button class="btn btn-secondary btn-sm" onclick="robot3D.rotateBase(-0.3)">↺ Base L</button>
                <button class="btn btn-secondary btn-sm" onclick="robot3D.rotateBase(0.3)">Base R ↻</button>
                <button class="btn btn-secondary btn-sm" onclick="robot3D.moveShoulder(-0.2)">↑ Shoulder</button>
                <button class="btn btn-secondary btn-sm" onclick="robot3D.moveShoulder(0.2)">Shoulder ↓</button>
                <button class="btn btn-secondary btn-sm" onclick="robot3D.moveElbow(-0.2)">↑ Elbow</button>
                <button class="btn btn-secondary btn-sm" onclick="robot3D.moveElbow(0.2)">Elbow ↓</button>
                <button class="btn btn-teal btn-sm" onclick="robot3D.toggleGripper()">✊ Toggle Grip</button>
                <button class="btn btn-secondary btn-sm" onclick="robot3D.goHome()">🏠 Home</button>
            </div>
        </div>
    </div>

    <!-- Pending Doses -->
    <div class="card" style="margin-bottom:0;flex:1">
        <div class="card-header" style="padding:12px 16px"><div class="card-title">💊 Pending Doses</div></div>
        <?php if (empty($todayDoses)): ?>
        <div style="padding:20px;text-align:center;color:var(--text-3);font-size:12.5px">No pending doses today</div>
        <?php else: ?>
        <?php foreach ($todayDoses as $i => $dose): ?>
        <div style="padding:10px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px">
            <div style="width:28px;height:28px;border-radius:8px;background:<?= htmlspecialchars($dose['color']) ?>33;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0">💊</div>
            <div style="flex:1;min-width:0">
                <div style="font-size:13px;font-weight:600;color:var(--text-1)"><?= htmlspecialchars($dose['med_name']) ?></div>
                <div style="font-size:11px;color:var(--text-3)"><?= $dose['dosage_mg'] ?>mg · <?= htmlspecialchars($dose['patient_name']) ?></div>
            </div>
            <button class="btn btn-teal btn-xs" onclick="robotDispense(<?= $dose['id'] ?>,'<?= htmlspecialchars(addslashes($dose['med_name'])) ?>','<?= htmlspecialchars(addslashes($dose['patient_name'])) ?>',<?= $i ?>)">
                🦾
            </button>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
</div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r134/three.min.js"></script>
<script src="/medbot/assets/robot.js"></script>
<script>
async function robotDispense(doseId, med, patient, bottleIndex) {
    document.getElementById('arm-status').textContent = '🦾 DISPENSING';
    document.getElementById('arm-status').className = 'badge badge-warning';
    document.getElementById('state-status').textContent = 'Dispensing';
    await robot3D.dispenseMedication(bottleIndex, med);
    const fd = new FormData();
    fd.append('action','dispense'); fd.append('dose_id', doseId);
    fd.append('medication_name', med); fd.append('patient_name', patient); fd.append('room','');
    await fetch('/medbot/api/robot_action.php', {method:'POST', body:fd});
    showToast(`✅ ${med} dispensed for ${patient}!`, 'success');
    document.getElementById('arm-status').textContent = '🦾 IDLE';
    document.getElementById('arm-status').className = 'badge badge-info';
    document.getElementById('state-status').textContent = 'Idle';
}
</script>
<?php renderFoot(); ?>
