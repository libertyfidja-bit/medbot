<?php
/**
 * MedBot — the signed-in PATIENT's own data            (place this file at: api/me.php)
 *
 *   GET  ?action=overview   -> name, today's doses, own symptom reports and staff replies
 *   GET  ?action=schedule   -> today's doses only (used by the voice assistant)
 *   POST {action:"taken", dose_id}  -> "I took it"
 *
 * Only users with the patient role can open this file (see auth_allowed in auth.php),
 * and every query is tied to the patient record linked to the signed-in account.
 */
require_once __DIR__ . '/../connect.php';
require_once __DIR__ . '/../auth.php';
mysqli_report(MYSQLI_REPORT_OFF);
header('Cache-Control: no-store');

function fail(string $msg, int $code = 400): never {
    jsonResponse(['error' => true, 'message' => $msg], $code);
}

function today_doses(mysqli $db, int $patientId): array {
    $out = [];
    $stmt = $db->prepare(
        'SELECT ds.id, ds.scheduled_time, ds.status, ds.taken_at, m.name AS medication_name, pr.dosage_mg, pr.instructions, pt.name AS patient_name
         FROM dose_schedule ds
         JOIN prescriptions pr ON ds.prescription_id = pr.id
         JOIN medications m ON pr.medication_id = m.id
         JOIN patients pt ON pr.patient_id = pt.id
         WHERE pr.patient_id = ? AND ds.scheduled_date = CURDATE()
         ORDER BY ds.scheduled_time'
    );
    if (!$stmt) return $out;
    $stmt->bind_param('i', $patientId);
    if ($stmt->execute()) {
        $res = $stmt->get_result();
        while ($res && ($r = $res->fetch_assoc())) {
            $out[] = [
                'id'              => (int) $r['id'],
                'scheduled_time'  => $r['scheduled_time'],
                'status'          => $r['status'],
                'medication_name' => $r['medication_name'],
                'dosage_mg'       => $r['dosage_mg'],
                'instructions'    => $r['instructions'],
                'patient_name'    => $r['patient_name'],
            ];
        }
    }
    $stmt->close();
    return $out;
}

try {
    $me = auth_user();
    if (!$me || $me['role'] !== 'patient') fail('Patients only.', 403);
    $pid = (int) ($me['patient_id'] ?? 0);
    if ($pid <= 0) fail('Your account is not linked to a patient record yet. Please ask a nurse.', 409);

    $db = getDB();
    mysqli_report(MYSQLI_REPORT_OFF);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $in = [];
    if ($method === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true);
        if (!is_array($in)) $in = $_POST;
    }
    $action = $in['action'] ?? ($_GET['action'] ?? 'overview');

    switch ($action) {

        case 'schedule':
            jsonResponse(['error' => false, 'schedule' => today_doses($db, $pid)]);

        case 'overview': {
            $p = auth_row($db, 'SELECT name, room_number FROM patients WHERE id = ?', 'i', [$pid]);
            $reports = [];
            $stmt = $db->prepare('SELECT id, transcript, status, staff_reply, handled_by, created_at, TIMESTAMPDIFF(MINUTE, created_at, NOW()) AS age_min FROM symptom_reports WHERE patient_id = ? ORDER BY id DESC LIMIT 20');
            if ($stmt) {
                $stmt->bind_param('i', $pid);
                if ($stmt->execute()) {
                    $res = $stmt->get_result();
                    while ($res && ($r = $res->fetch_assoc())) {
                        $reports[] = [
                            'id'          => (int) $r['id'],
                            'transcript'  => $r['transcript'],
                            'status'      => $r['status'],
                            'staff_reply' => $r['staff_reply'],
                            'handled_by'  => $r['handled_by'],
                            'age_min'     => (int) $r['age_min'],
                        ];
                    }
                }
                $stmt->close();
            }
            jsonResponse([
                'error'    => false,
                'name'     => $me['full_name'],
                'room'     => $p ? $p['room_number'] : null,
                'schedule' => today_doses($db, $pid),
                'reports'  => $reports,
            ]);
        }

        case 'taken': {
            if ($method !== 'POST') fail('POST required.', 405);
            $doseId = (int) ($in['dose_id'] ?? 0);
            // The dose must belong to THIS patient and be for today
            $dose = auth_row(
                $db,
                'SELECT ds.id, ds.status FROM dose_schedule ds JOIN prescriptions pr ON ds.prescription_id = pr.id
                 WHERE ds.id = ? AND pr.patient_id = ? AND ds.scheduled_date = CURDATE()',
                'ii',
                [$doseId, $pid]
            );
            if (!$dose) fail('That dose was not found.', 404);
            if (!in_array($dose['status'], ['pending', 'dispensed'], true)) fail('That dose is already recorded as ' . $dose['status'] . '.', 409);
            $n = auth_exec($db, "UPDATE dose_schedule SET status = 'taken', taken_at = NOW() WHERE id = ? AND status IN ('pending','dispensed')", 'i', [$doseId]);
            if (!$n) fail('Could not save. Please try again.', 500);
            jsonResponse(['error' => false]);
        }

        default:
            fail('Unknown action.');
    }
} catch (Throwable $e) {
    error_log('me.php: ' . $e->getMessage());
    fail('Server error.', 500);
}
