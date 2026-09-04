<?php
require_once '../connect.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$db = getDB();
$patientId = (int)($_GET['patient_id'] ?? 0);
$where = $patientId ? "WHERE ia.patient_id=$patientId AND ia.resolved=0" : "WHERE ia.resolved=0";
$sql = "
SELECT ia.*, p.name as patient_name, p.room_number
FROM interaction_alerts ia
JOIN patients p ON ia.patient_id=p.id
$where
ORDER BY ia.flagged_at DESC LIMIT 20
";
$res = $db->query($sql);
$rows = [];
while ($r = $res->fetch_assoc()) $rows[] = $r;
jsonResponse(['alerts'=>$rows,'count'=>count($rows)]);
