<?php require_once 'connect.php'; require_once 'layout.php';
$db = getDB();
$showResolved = isset($_GET['show_resolved']);
$where = $showResolved ? '' : 'WHERE ia.resolved=0';
$alerts = [];
$res = $db->query("SELECT ia.*,p.name as patient_name,p.room_number FROM interaction_alerts ia JOIN patients p ON ia.patient_id=p.id $where ORDER BY ia.flagged_at DESC");
while ($r = $res->fetch_assoc()) $alerts[] = $r;
renderHead('Alerts', 'alerts');
renderTopbar('Drug Interaction Alerts', count($alerts) . ' alerts');
?>
<div class="page-content">
<div style="display:flex;justify-content:flex-end;margin-bottom:16px">
    <a href="?<?= $showResolved ? '' : 'show_resolved' ?>" class="btn btn-secondary btn-sm">
        <?= $showResolved ? '🔴 Show Active Only' : '✅ Show Resolved' ?>
    </a>
</div>
<div class="card">
    <div class="card-header"><div class="card-title">⚠️ Interaction Alerts</div><span class="badge badge-danger"><?= count($alerts) ?></span></div>
    <?php if (empty($alerts)): ?>
    <div style="padding:50px;text-align:center;color:var(--text-3)"><div style="font-size:40px;margin-bottom:12px">✅</div>No <?= $showResolved?'':'active ' ?>alerts found.</div>
    <?php else: foreach ($alerts as $a):
        $cls = $a['severity']==='danger'?'error':($a['severity']==='warning'?'warning':'info');
        $icon = $a['severity']==='danger'?'🚫':($a['severity']==='warning'?'⚠️':'ℹ️');
    ?>
    <div class="alert alert-<?= $cls ?>" style="margin:12px;border-radius:10px;align-items:flex-start">
        <span class="alert-icon" style="font-size:20px"><?= $icon ?></span>
        <div style="flex:1">
            <div style="font-weight:700;font-size:14px;margin-bottom:4px">
                <?= htmlspecialchars($a['patient_name']) ?>
                <span class="badge badge-<?= $a['severity']==='danger'?'danger':($a['severity']==='warning'?'warning':'info') ?>" style="margin-left:8px"><?= strtoupper($a['severity']) ?></span>
                <span class="badge badge-gray" style="margin-left:4px">Room <?= htmlspecialchars($a['room_number']) ?></span>
            </div>
            <div style="font-size:13px;margin-bottom:4px">
                <strong><?= htmlspecialchars($a['drug_a']) ?></strong>
                <?php if ($a['drug_b']): ?> + <strong><?= htmlspecialchars($a['drug_b']) ?></strong><?php endif; ?>
            </div>
            <div style="font-size:12.5px;opacity:.85;line-height:1.5"><?= htmlspecialchars($a['description']) ?></div>
            <div style="font-size:11px;margin-top:6px;opacity:.6"><?= date('M j, Y H:i', strtotime($a['flagged_at'])) ?></div>
        </div>
        <?php if (!$a['resolved']): ?>
        <button class="btn btn-secondary btn-sm" onclick="resolveAndReload(<?= $a['id'] ?>)">✓ Resolve</button>
        <?php else: ?>
        <span class="badge badge-safe">✅ Resolved</span>
        <?php endif; ?>
    </div>
    <?php endforeach; endif; ?>
</div>
</div>
<script>
async function resolveAndReload(id) {
    const fd=new FormData(); fd.append('action','resolve_alert'); fd.append('alert_id',id);
    await fetch('/medbot/api/robot_action.php',{method:'POST',body:fd});
    showToast('Alert resolved','success');
    setTimeout(()=>location.reload(),600);
}
</script>
<?php renderFoot(); ?>
