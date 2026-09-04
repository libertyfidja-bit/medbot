<?php require_once 'connect.php'; require_once 'layout.php';
$db = getDB();
$message = ''; $msgType = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? '';
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $age  = (int)($_POST['age'] ?? 0);
        $room = trim($_POST['room_number'] ?? '');
        $notes = trim($_POST['condition_notes'] ?? '');
        if ($name) {
            $stmt = $db->prepare("INSERT INTO patients (name,age,room_number,condition_notes) VALUES (?,?,?,?)");
            $stmt->bind_param('siss', $name, $age, $room, $notes);
            $stmt->execute();
            $message = "✅ Patient '$name' added!";
        } else { $message = '❌ Name required.'; $msgType = 'error'; }
    }
    if ($action === 'delete') {
        $id = (int)($_POST['del_id'] ?? 0);
        if ($id) { $db->query("DELETE FROM patients WHERE id=$id"); $message = '✅ Patient removed.'; }
    }
}
$patients = [];
$res = $db->query("SELECT p.*,COUNT(pr.id) as rx_count FROM patients p LEFT JOIN prescriptions pr ON p.id=pr.patient_id AND pr.status='active' GROUP BY p.id ORDER BY p.name");
while ($r = $res->fetch_assoc()) $patients[] = $r;
renderHead('Patients', 'patients');
renderTopbar('Patients', count($patients) . ' registered patients');
?>
<div class="page-content">
<?php if ($message): ?><div class="alert alert-<?= $msgType ?>"><span class="alert-icon"><?= $msgType==='success'?'✅':'❌' ?></span><?= htmlspecialchars($message) ?></div><?php endif; ?>
<div class="grid-2">
<div class="card">
    <div class="card-header"><div class="card-title">➕ Add New Patient</div></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="_action" value="add">
            <div class="form-grid">
                <div class="form-group full"><label class="form-label">Full Name *</label><input type="text" name="name" placeholder="e.g. John Smith" required></div>
                <div class="form-group"><label class="form-label">Age</label><input type="number" name="age" min="1" max="120" placeholder="65"></div>
                <div class="form-group"><label class="form-label">Room Number</label><input type="text" name="room_number" placeholder="e.g. 101A"></div>
                <div class="form-group full"><label class="form-label">Medical Notes</label><textarea name="condition_notes" placeholder="Conditions, allergies, notes..."></textarea></div>
            </div>
            <div style="display:flex;justify-content:flex-end;margin-top:16px"><button type="submit" class="btn btn-primary" id="btn-submit" disabled>➕ Add Patient</button></div>
        </form>
    </div>
</div>
<script>
// Real-time button validation
document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('form');
    const submitBtn = document.getElementById('btn-submit');
    const nameInput = form.querySelector('input[name="name"]');
    
    function validate() {
        if (nameInput.value.trim().length > 0) {
            submitBtn.disabled = false;
        } else {
            submitBtn.disabled = true;
        }
    }
    
    form.addEventListener('input', validate);
    validate();
});
</script>
<div class="card">
    <div class="card-header"><div class="card-title">👥 Patient List</div><span class="badge badge-info"><?= count($patients) ?></span></div>
    <?php if (empty($patients)): ?>
    <div style="padding:40px;text-align:center;color:var(--text-3)"><div style="font-size:40px;margin-bottom:12px">👥</div>No patients yet. Add your first patient.</div>
    <?php else: ?>
    <div class="table-wrap"><table class="dt">
        <thead><tr><th>Name</th><th>Age</th><th>Room</th><th>Active Rx</th><th>Notes</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($patients as $p): ?>
        <tr>
            <td><strong><?= htmlspecialchars($p['name']) ?></strong></td>
            <td><?= $p['age'] ?: '—' ?></td>
            <td><span class="badge badge-info"><?= htmlspecialchars($p['room_number'] ?: '—') ?></span></td>
            <td><span class="badge badge-safe"><?= $p['rx_count'] ?> Rx</span></td>
            <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11.5px;color:var(--text-3)"><?= htmlspecialchars(mb_strimwidth($p['condition_notes']??'',0,40,'…')) ?></td>
            <td>
                <a href="/medbot/prescription.php?patient_id=<?= $p['id'] ?>" class="btn btn-teal btn-xs">+ Rx</a>
                <form method="POST" style="display:inline" onsubmit="return confirm('Delete this patient and all their data?')">
                    <input type="hidden" name="_action" value="delete">
                    <input type="hidden" name="del_id" value="<?= $p['id'] ?>">
                    <button type="submit" class="btn btn-danger btn-xs">🗑️</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>
</div>
</div>
<?php renderFoot(); ?>
