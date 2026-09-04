<?php
require_once '../connect.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$action = $_POST['action'] ?? $_GET['action'] ?? 'status';
$db = getDB();

if ($action === 'status') {
    $state = $db->query("SELECT * FROM robot_state WHERE id=1")->fetch_assoc();
    $recentLogs = [];
    $res = $db->query("SELECT * FROM robot_log ORDER BY created_at DESC LIMIT 10");
    while ($r = $res->fetch_assoc()) $recentLogs[] = $r;
    jsonResponse(['state'=>$state,'recent_log'=>$recentLogs]);
}

if ($action === 'dispense') {
    $doseId = (int)($_POST['dose_id'] ?? 0);
    $medName = $_POST['medication_name'] ?? 'Unknown';
    $patName = $_POST['patient_name'] ?? 'Unknown';
    $room    = $_POST['room'] ?? 'pharmacy';

    // Update robot state
    $db->query("UPDATE robot_state SET current_action='Dispensing: $medName', current_location='arm_station', last_updated=NOW() WHERE id=1");

    // Log it
    $stmt = $db->prepare("INSERT INTO robot_log (action_type,medication_name,patient_name,from_location,to_location,status) VALUES ('dispense',?,?,'pharmacy',?,'completed')");
    $stmt->bind_param('sss', $medName, $patName, $room);
    $stmt->execute();
    $logId = $db->insert_id;
    $stmt->close();

    // Update dose
    if ($doseId) {
        $db->query("UPDATE dose_schedule SET status='dispensed', dispensed_by_robot=1, taken_at=NOW() WHERE id=$doseId");
    }

    // Return to idle after short delay simulation
    $db->query("UPDATE robot_state SET current_action='idle', current_location='pharmacy' WHERE id=1");

    jsonResponse(['success'=>true,'log_id'=>$logId,'message'=>"Dispensed $medName for $patName"]);
}

if ($action === 'navigate') {
    $to = $_POST['to'] ?? 'pharmacy';
    $db->query("UPDATE robot_state SET current_action='Navigating to: $to', current_location='en_route' WHERE id=1");
    sleep(0); // real delay would be here
    $db->query("UPDATE robot_state SET current_action='idle', current_location='" . $db->real_escape_string($to) . "' WHERE id=1");
    jsonResponse(['success'=>true,'arrived_at'=>$to]);
}

if ($action === 'resolve_alert') {
    $alertId = (int)($_POST['alert_id'] ?? 0);
    $db->query("UPDATE interaction_alerts SET resolved=1 WHERE id=$alertId");
    jsonResponse(['success'=>true]);
}

jsonResponse(['error'=>true,'message'=>'Unknown action']);
