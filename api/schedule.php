<?php
require_once '../connect.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$patientId = (int)($_GET['patient_id'] ?? 0);
$date = $_GET['date'] ?? date('Y-m-d');

$db = getDB();

$where = $patientId ? "AND p.patient_id = $patientId" : '';

$sql = "
SELECT
  ds.id, ds.scheduled_date, ds.scheduled_time, ds.status, ds.taken_at, ds.dispensed_by_robot,
  pr.dosage_mg, pr.frequency_per_day, pr.instructions,
  m.name as medication_name, m.color, m.form,
  pt.name as patient_name, pt.room_number, pt.id as patient_id
FROM dose_schedule ds
JOIN prescriptions pr ON ds.prescription_id = pr.id
JOIN medications m ON pr.medication_id = m.id
JOIN patients pt ON pr.patient_id = pt.id
WHERE ds.scheduled_date = '$date'
  AND pr.status = 'active'
  $where
ORDER BY ds.scheduled_time ASC, pt.name ASC
";

$result = $db->query($sql);
$rows = [];
while ($row = $result->fetch_assoc()) $rows[] = $row;

// Auto-mark missed doses
$now = time();
foreach ($rows as &$row) {
    if ($row['status'] === 'pending') {
        $dt = strtotime($row['scheduled_date'] . ' ' . $row['scheduled_time']);
        if ($now > $dt + 3600) { // 1 hour grace period
            $id = $row['id'];
            $db->query("UPDATE dose_schedule SET status='missed' WHERE id=$id");
            $row['status'] = 'missed';
        }
    }
}

jsonResponse(['date'=>$date,'count'=>count($rows),'schedule'=>$rows]);
