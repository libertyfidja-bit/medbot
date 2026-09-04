<?php
require_once '../connect.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$patientId = (int)($_GET['patient_id'] ?? 0);
$days = (int)($_GET['days'] ?? 7);
$db = getDB();

$where = $patientId ? "AND pr.patient_id = $patientId" : '';
$from = date('Y-m-d', strtotime("-{$days} days"));

$sql = "
SELECT
  COUNT(*) as total,
  SUM(CASE WHEN ds.status IN ('taken','dispensed') THEN 1 ELSE 0 END) as taken,
  SUM(CASE WHEN ds.status = 'missed' THEN 1 ELSE 0 END) as missed,
  SUM(CASE WHEN ds.status = 'pending' THEN 1 ELSE 0 END) as pending,
  ds.scheduled_date as date
FROM dose_schedule ds
JOIN prescriptions pr ON ds.prescription_id = pr.id
WHERE ds.scheduled_date >= '$from'
  AND ds.scheduled_date <= CURDATE()
  $where
GROUP BY ds.scheduled_date
ORDER BY ds.scheduled_date ASC
";

$result = $db->query($sql);
$byDay = [];
$totalAll = 0; $takenAll = 0;
while ($row = $result->fetch_assoc()) {
    $byDay[] = $row;
    $totalAll += $row['total'];
    $takenAll += $row['taken'];
}

$pct = $totalAll > 0 ? round(($takenAll / $totalAll) * 100) : 0;

jsonResponse(['adherence_pct'=>$pct,'total'=>$totalAll,'taken'=>$takenAll,'by_day'=>$byDay]);
