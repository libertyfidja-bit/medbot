<?php
require_once '../connect.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();

if ($method === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id) {
        $row = $db->query("SELECT * FROM patients WHERE id=$id")->fetch_assoc();
        jsonResponse($row ?: ['error'=>true,'message'=>'Not found']);
    }
    $rows = [];
    $res = $db->query("SELECT p.*, COUNT(pr.id) as prescription_count FROM patients p LEFT JOIN prescriptions pr ON p.id=pr.patient_id AND pr.status='active' GROUP BY p.id ORDER BY p.name");
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    jsonResponse(['patients'=>$rows,'count'=>count($rows)]);
}

if ($method === 'POST') {
    $name  = trim($_POST['name'] ?? '');
    $age   = (int)($_POST['age'] ?? 0);
    $room  = trim($_POST['room_number'] ?? '');
    $notes = trim($_POST['condition_notes'] ?? '');
    if (!$name) jsonResponse(['error'=>true,'message'=>'name required'],400);
    $stmt = $db->prepare("INSERT INTO patients (name,age,room_number,condition_notes) VALUES (?,?,?,?)");
    $stmt->bind_param('siss', $name, $age, $room, $notes);
    $stmt->execute();
    jsonResponse(['success'=>true,'id'=>$db->insert_id,'message'=>"Patient $name added"]);
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonResponse(['error'=>true,'message'=>'id required'],400);
    $db->query("DELETE FROM patients WHERE id=$id");
    jsonResponse(['success'=>true]);
}

jsonResponse(['error'=>true,'message'=>'Method not allowed'],405);
