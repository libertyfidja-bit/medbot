<?php
require_once '../connect.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error'=>true,'message'=>'POST required']);

$patientId   = (int)($_POST['patient_id'] ?? 0);
$drugName    = trim($_POST['drug_name'] ?? '');
$dosage      = (float)($_POST['dosage_mg'] ?? 0);
$frequency   = (int)($_POST['frequency_per_day'] ?? 1);
$startDate   = $_POST['start_date'] ?? date('Y-m-d');
$endDate     = $_POST['end_date'] ?? null;
$instructions = trim($_POST['instructions'] ?? '');
$rxcui       = trim($_POST['rxcui'] ?? '');

if (!$patientId || !$drugName || !$dosage) {
    jsonResponse(['error'=>true,'message'=>'patient_id, drug_name and dosage_mg are required']);
}

$db = getDB();

// Upsert medication
$colors = ['#6c63ff','#00d4aa','#ffa94d','#ff6b9d','#4ecdc4','#45b7d1','#96ceb4'];
$color = $colors[array_rand($colors)];
$stmt = $db->prepare("INSERT INTO medications (rxcui, name, generic_name, color) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name)");
$stmt->bind_param('ssss', $rxcui, $drugName, $drugName, $color);
$stmt->execute();
$medId = $db->insert_id ?: $db->query("SELECT id FROM medications WHERE name='" . $db->real_escape_string($drugName) . "' LIMIT 1")->fetch_row()[0];
$stmt->close();

// Create prescription
$stmt = $db->prepare("INSERT INTO prescriptions (patient_id,medication_id,dosage_mg,frequency_per_day,start_date,end_date,instructions) VALUES (?,?,?,?,?,?,?)");
$stmt->bind_param('iiidsss', $patientId, $medId, $dosage, $frequency, $startDate, $endDate, $instructions);
$stmt->execute();
$prescriptionId = $db->insert_id;
$stmt->close();

// Generate dose schedule for next 7 days
$hoursPerDay = 24 / $frequency;
$baseHour = 8; // first dose at 8am
for ($day = 0; $day < 7; $day++) {
    $date = date('Y-m-d', strtotime($startDate . " +$day days"));
    if ($endDate && $date > $endDate) break;
    for ($dose = 0; $dose < $frequency; $dose++) {
        $hour = ($baseHour + ($hoursPerDay * $dose)) % 24;
        $time = sprintf('%02d:00:00', (int)$hour);
        $stmt = $db->prepare("INSERT INTO dose_schedule (prescription_id, scheduled_date, scheduled_time) VALUES (?,?,?)");
        $stmt->bind_param('iss', $prescriptionId, $date, $time);
        $stmt->execute();
        $stmt->close();
    }
}

// Log robot action
$stmt = $db->prepare("INSERT INTO robot_log (action_type, medication_name, patient_name, from_location, to_location) VALUES ('dispense',?,?,?,?)");
$patRow = $db->query("SELECT name, room_number FROM patients WHERE id=$patientId")->fetch_assoc();
$patName = $patRow['name'] ?? 'Unknown';
$room = $patRow['room_number'] ?? 'Unknown';
$from = 'pharmacy';
$stmt->bind_param('ssss', $drugName, $patName, $from, $room);
$stmt->execute();
$stmt->close();

jsonResponse(['success'=>true,'prescription_id'=>$prescriptionId,'medication_id'=>$medId,'message'=>"Prescription added and schedule generated for $drugName"]);
