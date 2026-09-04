<?php require_once 'connect.php'; require_once 'layout.php';
$db = getDB();
$date = $_GET['date'] ?? date('Y-m-d');
$patientId = (int)($_GET['patient_id'] ?? 0);
$whereP = $patientId ? "AND pt.id=$patientId" : '';
$sql = "SELECT ds.*,m.name as med_name,m.color,pr.dosage_mg,pr.frequency_per_day,pt.name as patient_name,pt.room_number,pt.id as patient_id FROM dose_schedule ds JOIN prescriptions pr ON ds.prescription_id=pr.id JOIN medications m ON pr.medication_id=m.id JOIN patients pt ON pr.patient_id=pt.id WHERE ds.scheduled_date='".$db->real_escape_string($date)."' $whereP ORDER BY ds.scheduled_time ASC";
$doses = []; $res = $db->query($sql);
while ($r = $res->fetch_assoc()) $doses[] = $r;
renderHead('Schedule', 'schedule');
renderTopbar('Dose Schedule', date('l, F j, Y', strtotime($date)));
?>
<div class="page-content">
<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap">
    <a href="?date=<?= date('Y-m-d', strtotime($date . ' -1 day')) ?>" class="btn btn-secondary">← Prev</a>
    <input type="date" value="<?= $date ?>" onchange="location.href='?date='+this.value" style="padding:8px 12px;max-width:180px">
    <a href="?date=<?= date('Y-m-d', strtotime($date . ' +1 day')) ?>" class="btn btn-secondary">Next →</a>
    <?php if ($date !== date('Y-m-d')): ?><a href="?date=<?= date('Y-m-d') ?>" class="btn btn-primary btn-sm">Today</a><?php endif; ?>
    <span class="badge badge-info" style="margin-left:auto"><?= count($doses) ?> doses</span>
</div>
<div class="card">
    <div class="card-header"><div class="card-title">💊 Medication Schedule</div></div>
    <?php if (empty($doses)): ?>
    <div style="padding:50px;text-align:center;color:var(--text-3)"><div style="font-size:40px;margin-bottom:12px">📅</div>No doses scheduled for this date.</div>
    <?php else: foreach ($doses as $dose):
        $status = $dose['status'];
        $badge = match($status) { 'taken','dispensed'=>['safe','✅ Done'],'missed'=>['danger','❌ Missed'],'skipped'=>['gray','⏭️ Skipped'],default=>['info','🕐 Pending'] };
    ?>
    <div class="dose-item">
        <div class="dose-pill" style="background:<?= htmlspecialchars($dose['color']) ?>22;font-size:22px">💊</div>
        <div class="dose-info">
            <div class="dose-name"><?= htmlspecialchars($dose['med_name']) ?></div>
            <div class="dose-detail"><?= $dose['dosage_mg'] ?>mg &bull; <?= htmlspecialchars($dose['patient_name']) ?> &bull; Room <?= htmlspecialchars($dose['room_number']) ?></div>
        </div>
        <div style="margin-right:10px;text-align:right"><div class="dose-time"><?= substr($dose['scheduled_time'],0,5) ?></div></div>
        <span class="badge badge-<?= $badge[0] ?>"><?= $badge[1] ?></span>
        <?php if ($status === 'pending'): ?>
        <button class="btn btn-teal btn-sm" onclick="markTaken(<?= $dose['id'] ?>,this)">✅ Mark Taken</button>
        <?php endif; ?>
    </div>
    <?php endforeach; endif; ?>
</div>
</div>
<script>
async function markTaken(doseId, btn) {
    btn.disabled=true; btn.textContent='...';
    const fd=new FormData(); fd.append('action','dispense'); fd.append('dose_id',doseId);
    fd.append('medication_name',''); fd.append('patient_name',''); fd.append('room','');
    await fetch('/medbot/api/robot_action.php',{method:'POST',body:fd});
    showToast('✅ Dose marked as taken!','success');
    setTimeout(()=>location.reload(),700);
}
</script>
<?php renderFoot(); ?>
