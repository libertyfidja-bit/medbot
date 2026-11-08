<?php
require_once 'connect.php';
require_once 'layout.php';

$me   = auth_user();
$role = $me['role'];
$db   = getDB();

function home_count(mysqli $db, string $sql): int {
    $r = $db->query($sql);
    if (!$r) return 0;
    $row = $r->fetch_row();
    $r->free();
    return (int) ($row[0] ?? 0);
}

$urgent    = home_count($db, "SELECT COUNT(*) FROM symptom_reports WHERE status <> 'resolved' AND urgency = 'urgent'");
$newRep    = home_count($db, "SELECT COUNT(*) FROM symptom_reports WHERE status = 'new'");
$robotWait = home_count($db, "SELECT COUNT(*) FROM robot_missions WHERE status = 'awaiting_confirmation'");
$robotMove = home_count($db, "SELECT COUNT(*) FROM robot_missions WHERE status = 'moving'");
$alerts    = home_count($db, 'SELECT COUNT(*) FROM interaction_alerts WHERE resolved = 0');
$pending   = home_count($db, "SELECT COUNT(*) FROM dose_schedule WHERE scheduled_date = CURDATE() AND status = 'pending'");
$missed    = home_count($db, "SELECT COUNT(*) FROM dose_schedule WHERE scheduled_date = CURDATE() AND status = 'missed'");
$unread    = home_count($db, 'SELECT COUNT(*) FROM messages WHERE to_user = ' . (int) $me['id'] . ' AND read_at IS NULL');
$payReview = home_count($db, "SELECT COUNT(*) FROM payments WHERE status = 'pending' OR (payer_check = 'mismatch' AND confirmed_at > (NOW() - INTERVAL 7 DAY))");

$top = [];
$res = $db->query("SELECT id, patient_name, room_number, transcript, urgency, status, TIMESTAMPDIFF(MINUTE, created_at, NOW()) AS age_min
                   FROM symptom_reports WHERE status <> 'resolved'
                   ORDER BY CASE urgency WHEN 'urgent' THEN 0 WHEN 'attention' THEN 1 ELSE 2 END, CASE status WHEN 'new' THEN 0 ELSE 1 END, id DESC LIMIT 5");
if ($res) { while ($r = $res->fetch_assoc()) $top[] = $r; $res->free(); }

function home_ago(int $m): string {
    if ($m < 1) return 'just now';
    if ($m < 60) return $m . ' min ago';
    if ($m < 1440) return floor($m / 60) . ' h ago';
    return floor($m / 1440) . ' d ago';
}

$canConfirm = in_array($role, ['doctor', 'admin'], true);

renderHead('Home', 'home');
renderTopbar('Welcome, ' . $me['full_name'], ucfirst($role) . ' workspace');
?>
<div class="page-content">

<?php if ($robotWait > 0): ?>
    <div class="alert alert-warning" role="status">
        <span class="alert-icon">🤖</span>
        <div>
            <strong>The robot has arrived and is waiting for a doctor’s confirmation before it releases anything.</strong><br>
            <?php if ($canConfirm): ?>
                <a href="pathfinding.php" class="btn btn-teal btn-sm" style="margin-top:8px">Review and confirm</a>
            <?php else: ?>
                A doctor must confirm the release on the Navigation Map page.
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

    <div class="stats-grid">
        <div class="stat-card"><div class="stat-icon">🚨</div><div class="stat-value danger"><?= $urgent ?></div><div class="stat-label">Urgent symptom reports</div></div>
        <div class="stat-card"><div class="stat-icon">🩺</div><div class="stat-value"><?= $newRep ?></div><div class="stat-label">New reports</div></div>
        <?php if ($role !== 'nurse'): ?>
        <div class="stat-card"><div class="stat-icon">⚠️</div><div class="stat-value danger"><?= $alerts ?></div><div class="stat-label">Drug interaction alerts</div></div>
        <?php endif; ?>
        <?php if ($role !== 'doctor'): ?>
        <div class="stat-card"><div class="stat-icon">⏰</div><div class="stat-value teal"><?= $pending ?></div><div class="stat-label">Doses still to give today</div></div>
        <?php endif; ?>
        <div class="stat-card"><div class="stat-icon">❗</div><div class="stat-value danger"><?= $missed ?></div><div class="stat-label">Missed doses today</div></div>
        <div class="stat-card"><div class="stat-icon">✉️</div><div class="stat-value"><?= $unread ?></div><div class="stat-label">Unread messages</div></div>
        <?php if ($role === 'admin'): ?>
        <div class="stat-card"><div class="stat-icon">💳</div><div class="stat-value danger"><?= $payReview ?></div><div class="stat-label">Payments to review</div></div>
        <?php endif; ?>
        <div class="stat-card"><div class="stat-icon">🤖</div><div class="stat-value teal"><?= $robotMove > 0 ? 'Moving' : ($robotWait > 0 ? 'Waiting' : 'Ready') ?></div><div class="stat-label">Robot</div></div>
    </div>

    <div class="grid-2">
        <div class="card">
            <div class="card-header">
                <div class="card-title">🩺 Needs attention</div>
                <a class="btn btn-secondary btn-sm" href="symptom-reports.php">Open all reports</a>
            </div>
            <?php if (!$top): ?>
                <div style="padding:24px;text-align:center;color:var(--text-3)">🎉 No open symptom reports.</div>
            <?php endif; ?>
            <?php foreach ($top as $r): ?>
                <div style="padding:12px 16px;border-bottom:1px solid var(--border)">
                    <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap">
                        <strong style="color:var(--text-1)"><?= auth_h($r['patient_name']) ?><?= $r['room_number'] ? ' · Room ' . auth_h($r['room_number']) : '' ?></strong>
                        <span>
                            <span class="badge <?= $r['urgency'] === 'urgent' ? 'badge-danger' : ($r['urgency'] === 'attention' ? 'badge-warning' : 'badge-safe') ?>"><?= auth_h($r['urgency']) ?></span>
                            <span style="font-size:12px;color:var(--text-3)"><?= auth_h(home_ago((int) $r['age_min'])) ?></span>
                        </span>
                    </div>
                    <div style="font-size:13px;color:var(--text-2);margin-top:4px"><?= auth_h(mb_substr($r['transcript'], 0, 140)) ?><?= mb_strlen($r['transcript']) > 140 ? '…' : '' ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="card">
            <div class="card-header"><div class="card-title">⚡ Quick actions</div></div>
            <div class="card-body" style="display:flex;flex-direction:column;gap:10px">
                <a class="btn btn-primary" href="symptom-reports.php">🩺 Symptom reports</a>
                <a class="btn btn-secondary" href="pathfinding.php">🗺️ Robot: navigation map<?= $canConfirm ? ' and confirmation' : ' and dispatch' ?></a>
                <a class="btn btn-secondary" href="schedule.php">📅 Dose schedule</a>
                <a class="btn btn-secondary" href="patients.php">👥 Patients</a>
                <?php if ($role !== 'nurse'): ?><a class="btn btn-secondary" href="prescription.php">💊 Prescriptions</a><?php endif; ?>
                <a class="btn btn-secondary" href="alerts.php">⚠️ Interaction alerts</a>
                <a class="btn btn-secondary" href="register_patient.php">➕ Register patient</a>
                <a class="btn btn-secondary" href="billing.php">🧾 Billing</a>
                <a class="btn btn-secondary" href="messages.php">✉️ Messages<?= $unread > 0 ? ' (' . $unread . ' new)' : '' ?></a>
                <?php if ($role === 'admin'): ?>
                <a class="btn btn-secondary" href="payments.php">💳 Payments</a>
                <a class="btn btn-secondary" href="insurers.php">🏥 Insurers</a>
                <a class="btn btn-secondary" href="users.php">🔑 Accounts</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php renderFoot(); ?>
