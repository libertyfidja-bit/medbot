<?php
/**
 * MedBot — robot missions API            (place this file at: api/missions.php)
 *
 *   GET  ?action=active   -> current mission (if any), last robot position, recent history
 *   POST {action:"create",  task_type, note, from_key, to_key, from_label, to_label,
 *                           path:[{c,r},...], step_ms, requested_by}
 *   POST {action:"confirm", id, decision:"release"|"reject", confirmed_by}
 *   POST {action:"cancel",  id}
 *
 * Mission life cycle:
 *   moving -> awaiting_confirmation -> released | rejected      (deliveries)
 *   moving -> completed                                         (return trips)
 *   moving -> cancelled                                         (stopped by a user)
 *
 * The robot's position is not saved while it moves. Every screen works it out from
 * (server time - started_ms) / step_ms, so all browsers see the same movement.
 * The robot only "arrives" here; it never releases anything until a doctor confirms.
 *
 * The robot_missions table is created automatically the first time this API runs.
 */
require_once __DIR__ . '/../connect.php';
require_once __DIR__ . '/../auth.php';
mysqli_report(MYSQLI_REPORT_OFF);
header('Cache-Control: no-store');

const GRID_COLS = 40;
const GRID_ROWS = 28;
const TASKS = [
    'medicine'          => 'Medicine delivery',
    'first_aid'         => 'First-aid box',
    'surgical_supplies' => 'Surgical / operation materials',
    'message'           => 'Message / document',
    'other'             => 'Other item or service',
    'return'            => 'Return to base',
];

function fail(string $msg, int $code = 500): never {
    jsonResponse(['error' => true, 'message' => $msg], $code);
}

function nowMs(): int {
    return (int) round(microtime(true) * 1000);
}

function clean($s, int $max): string {
    return mb_substr(trim(strip_tags((string) $s)), 0, $max);
}

function run(mysqli $db, string $sql, string $types = '', array $params = []): mysqli_stmt {
    $stmt = $db->prepare($sql);
    if (!$stmt) fail('Database error: ' . $db->error);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) fail('Database error: ' . $stmt->error);
    return $stmt;
}

function one(mysqli $db, string $sql, string $types = '', array $params = []): ?array {
    $stmt = run($db, $sql, $types, $params);
    $res  = $stmt->get_result();
    $row  = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function ensureTable(mysqli $db): void {
    $probe = $db->query('SELECT 1 FROM robot_missions LIMIT 1');
    if ($probe) { $probe->free(); return; }
    $ok = $db->query("CREATE TABLE IF NOT EXISTS robot_missions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        task_type ENUM('medicine','first_aid','surgical_supplies','message','other','return') NOT NULL DEFAULT 'medicine',
        payload_note VARCHAR(255),
        from_key VARCHAR(50) NOT NULL,
        to_key VARCHAR(50) NOT NULL,
        from_label VARCHAR(120),
        to_label VARCHAR(120),
        path JSON NOT NULL,
        step_ms INT NOT NULL DEFAULT 350,
        status ENUM('moving','awaiting_confirmation','released','rejected','cancelled','completed') NOT NULL DEFAULT 'moving',
        requested_by VARCHAR(100),
        confirmed_by VARCHAR(100),
        started_ms BIGINT NOT NULL,
        stop_index INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        arrived_at TIMESTAMP NULL,
        decided_at TIMESTAMP NULL
    )");
    if (!$ok) fail('Could not create the robot_missions table: ' . $db->error);
}

function setRobotState(mysqli $db, string $action, string $location): void {
    $stmt = $db->prepare('UPDATE robot_state SET current_action = ?, current_location = ? WHERE id = 1');
    if ($stmt) {
        $stmt->bind_param('ss', $action, $location);
        $stmt->execute();
        $stmt->close();
    }
}

function logRobot(mysqli $db, string $type, string $what, string $who, string $from, string $to, string $status, array $meta): void {
    $stmt = $db->prepare('INSERT INTO robot_log (action_type, medication_name, patient_name, from_location, to_location, status, metadata) VALUES (?,?,?,?,?,?,?)');
    if ($stmt) {
        $json = json_encode($meta);
        $stmt->bind_param('sssssss', $type, $what, $who, $from, $to, $status, $json);
        $stmt->execute();
        $stmt->close();
    }
}

/** Moves finished "moving" missions on to their next state (called on every request). */
function tick(mysqli $db): void {
    $res = $db->query("SELECT * FROM robot_missions WHERE status = 'moving'");
    if (!$res) return;
    $rows = [];
    while ($m = $res->fetch_assoc()) $rows[] = $m;
    $res->free();

    foreach ($rows as $m) {
        $path    = json_decode($m['path'], true) ?: [];
        $steps   = max(0, count($path) - 1);
        $elapsed = nowMs() - (int) $m['started_ms'];
        if ($elapsed < $steps * (int) $m['step_ms']) continue;

        $isReturn = $m['task_type'] === 'return';
        $new      = $isReturn ? 'completed' : 'awaiting_confirmation';
        $stmt = run($db, "UPDATE robot_missions SET status = ?, arrived_at = NOW() WHERE id = ? AND status = 'moving'", 'si', [$new, (int) $m['id']]);
        $changed = $stmt->affected_rows > 0;
        $stmt->close();
        if (!$changed) continue;   // another request already handled it

        $what = TASKS[$m['task_type']] ?? 'Delivery';
        $meta = ['mission_id' => (int) $m['id'], 'task' => $m['task_type'], 'note' => $m['payload_note']];
        if ($isReturn) {
            setRobotState($db, 'idle', $m['to_key']);
            logRobot($db, 'home', $what, (string) $m['to_label'], (string) $m['from_label'], (string) $m['to_label'], 'completed', $meta);
        } else {
            setRobotState($db, 'waiting for doctor confirmation at ' . $m['to_label'], $m['to_key']);
            logRobot($db, 'alert', $what . ' - arrived, awaiting doctor confirmation', (string) $m['to_label'], (string) $m['from_label'], (string) $m['to_label'], 'in_progress', $meta);
        }
    }
}

function present(array $m, bool $withPath = true): array {
    $out = [
        'id'           => (int) $m['id'],
        'task_type'    => $m['task_type'],
        'note'         => $m['payload_note'],
        'from_key'     => $m['from_key'],
        'to_key'       => $m['to_key'],
        'from_label'   => $m['from_label'],
        'to_label'     => $m['to_label'],
        'status'       => $m['status'],
        'requested_by' => $m['requested_by'],
        'confirmed_by' => $m['confirmed_by'],
        'created_at'   => $m['created_at'],
        'decided_at'   => $m['decided_at'],
    ];
    if ($withPath) {
        $out['path']       = json_decode($m['path'], true) ?: [];
        $out['step_ms']    = (int) $m['step_ms'];
        $out['elapsed_ms'] = max(0, nowMs() - (int) $m['started_ms']);
        $out['stop_index'] = $m['stop_index'] === null ? null : (int) $m['stop_index'];
    }
    return $out;
}

function lastPosition(mysqli $db): array {
    $m = one($db, "SELECT * FROM robot_missions WHERE status NOT IN ('moving','awaiting_confirmation') ORDER BY id DESC LIMIT 1");
    if (!$m) return [null, null];
    $path = json_decode($m['path'], true) ?: [];
    if (!$path) return [null, null];
    if ($m['status'] === 'cancelled' && $m['stop_index'] !== null) {
        $i = max(0, min((int) $m['stop_index'], count($path) - 1));
        return [$path[$i], $i === 0 ? $m['from_key'] : null];
    }
    return [end($path), $m['to_key']];
}

function validatePath($p): array {
    if (!is_array($p) || count($p) < 2 || count($p) > 600) fail('Invalid path.', 400);
    $out = [];
    $prev = null;
    foreach ($p as $pt) {
        if (!is_array($pt) || !isset($pt['c'], $pt['r']) || !is_int($pt['c']) || !is_int($pt['r'])) fail('Invalid path point.', 400);
        if ($pt['c'] < 0 || $pt['c'] >= GRID_COLS || $pt['r'] < 0 || $pt['r'] >= GRID_ROWS) fail('Path leaves the map.', 400);
        if ($prev && abs($pt['c'] - $prev['c']) + abs($pt['r'] - $prev['r']) !== 1) fail('Path must move one cell at a time.', 400);
        $prev = ['c' => $pt['c'], 'r' => $pt['r']];
        $out[] = $prev;
    }
    return $out;
}

try {
    $db = getDB();
    mysqli_report(MYSQLI_REPORT_OFF);
    ensureTable($db);

    $me = auth_user();
    if (!$me) fail('Please sign in again.', 401);
    if (!in_array($me['role'], AUTH_STAFF, true)) fail('Staff only.', 403);
    $who = clean($me['full_name'] . ' (' . $me['role'] . ')', 100);

    tick($db);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $in     = [];
    if ($method === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true);
        if (!is_array($in)) $in = $_POST;
    }
    $action = $in['action'] ?? ($_GET['action'] ?? 'active');

    switch ($action) {

        case 'active': {
            $active = one($db, "SELECT * FROM robot_missions WHERE status IN ('moving','awaiting_confirmation') ORDER BY id DESC LIMIT 1");
            [$pos, $key] = lastPosition($db);
            $history = [];
            $res = $db->query('SELECT id, task_type, payload_note, from_key, to_key, from_label, to_label, status, requested_by, confirmed_by, created_at, decided_at FROM robot_missions ORDER BY id DESC LIMIT 8');
            if ($res) {
                while ($h = $res->fetch_assoc()) $history[] = present($h, false);
                $res->free();
            }
            jsonResponse([
                'error'         => false,
                'server_ms'     => nowMs(),
                'mission'       => $active ? present($active) : null,
                'last_position' => $pos,
                'last_key'      => $key,
                'history'       => $history,
            ]);
        }

        case 'create': {
            if ($method !== 'POST') fail('POST required.', 405);
            $busy = one($db, "SELECT id FROM robot_missions WHERE status IN ('moving','awaiting_confirmation') LIMIT 1");
            if ($busy) fail('The robot is busy with mission #' . $busy['id'] . '. Finish or cancel it first.', 409);

            $task = (string) ($in['task_type'] ?? 'medicine');
            if (!isset(TASKS[$task])) fail('Unknown service type.', 400);
            $path    = validatePath($in['path'] ?? null);
            $step    = max(100, min(1500, (int) ($in['step_ms'] ?? 350)));
            $fromKey = clean($in['from_key'] ?? '', 50);
            $toKey   = clean($in['to_key'] ?? '', 50);
            if ($fromKey === '' || $toKey === '') fail('Origin and destination are required.', 400);
            $fromLabel = clean($in['from_label'] ?? $fromKey, 120);
            $toLabel   = clean($in['to_label'] ?? $toKey, 120);
            $note      = clean($in['note'] ?? '', 255);
            $by        = $who;

            $stmt = run(
                $db,
                'INSERT INTO robot_missions (task_type, payload_note, from_key, to_key, from_label, to_label, path, step_ms, requested_by, started_ms) VALUES (?,?,?,?,?,?,?,?,?,?)',
                'sssssssisi',
                [$task, $note, $fromKey, $toKey, $fromLabel, $toLabel, json_encode($path), $step, $by, nowMs()]
            );
            $id = (int) $stmt->insert_id;
            $stmt->close();

            $what = TASKS[$task];
            setRobotState($db, ($task === 'return' ? 'returning to ' : 'delivering ' . strtolower($what) . ' to ') . $toLabel, 'in transit');
            logRobot($db, 'navigate', $what, $toLabel, $fromLabel, $toLabel, 'in_progress',
                ['mission_id' => $id, 'task' => $task, 'note' => $note, 'requested_by' => $by]);

            $m = one($db, 'SELECT * FROM robot_missions WHERE id = ?', 'i', [$id]);
            jsonResponse(['error' => false, 'server_ms' => nowMs(), 'mission' => present($m)], 201);
        }

        case 'confirm': {
            if ($method !== 'POST') fail('POST required.', 405);
            $id       = (int) ($in['id'] ?? 0);
            $decision = (string) ($in['decision'] ?? '');
            $by       = $who;
            if (!in_array($decision, ['release', 'reject'], true)) fail('Decision must be release or reject.', 400);
            if (!in_array($me['role'], ['doctor', 'admin'], true)) fail('Only a doctor can confirm or refuse the release.', 403);

            $m = one($db, 'SELECT * FROM robot_missions WHERE id = ?', 'i', [$id]);
            if (!$m) fail('Mission not found.', 404);
            if ($m['status'] !== 'awaiting_confirmation') fail('This mission is not waiting for confirmation (status: ' . $m['status'] . ').', 409);

            $new  = $decision === 'release' ? 'released' : 'rejected';
            $stmt = run($db, "UPDATE robot_missions SET status = ?, confirmed_by = ?, decided_at = NOW() WHERE id = ? AND status = 'awaiting_confirmation'", 'ssi', [$new, $by, $id]);
            $changed = $stmt->affected_rows > 0;
            $stmt->close();
            if (!$changed) fail('Someone else already decided this mission.', 409);

            $what = TASKS[$m['task_type']] ?? 'Delivery';
            $meta = ['mission_id' => $id, 'task' => $m['task_type'], 'note' => $m['payload_note'], 'confirmed_by' => $by];
            if ($decision === 'release') {
                $type = $m['task_type'] === 'medicine' ? 'dispense' : 'place';
                logRobot($db, $type, $what . ' - released by ' . $by, (string) $m['to_label'], (string) $m['from_label'], (string) $m['to_label'], 'completed', $meta);
            } else {
                logRobot($db, 'alert', $what . ' - release refused by ' . $by, (string) $m['to_label'], (string) $m['from_label'], (string) $m['to_label'], 'failed', $meta);
            }
            setRobotState($db, 'idle', $m['to_key']);

            $m = one($db, 'SELECT * FROM robot_missions WHERE id = ?', 'i', [$id]);
            jsonResponse(['error' => false, 'mission' => present($m, false)]);
        }

        case 'cancel': {
            if ($method !== 'POST') fail('POST required.', 405);
            $id = (int) ($in['id'] ?? 0);
            $m  = one($db, 'SELECT * FROM robot_missions WHERE id = ?', 'i', [$id]);
            if (!$m) fail('Mission not found.', 404);
            if ($m['status'] !== 'moving') fail('Only a moving robot can be stopped (status: ' . $m['status'] . ').', 409);

            $path = json_decode($m['path'], true) ?: [];
            $idx  = (int) min(max(0, count($path) - 1), floor((nowMs() - (int) $m['started_ms']) / max(1, (int) $m['step_ms'])));
            $stmt = run($db, "UPDATE robot_missions SET status = 'cancelled', stop_index = ?, decided_at = NOW() WHERE id = ? AND status = 'moving'", 'ii', [$idx, $id]);
            $changed = $stmt->affected_rows > 0;
            $stmt->close();
            if (!$changed) fail('The robot has already arrived.', 409);

            setRobotState($db, 'idle (stopped on the way)', 'in transit');
            logRobot($db, 'alert', (TASKS[$m['task_type']] ?? 'Delivery') . ' - mission cancelled', (string) $m['to_label'], (string) $m['from_label'], (string) $m['to_label'], 'failed',
                ['mission_id' => $id, 'task' => $m['task_type']]);
            jsonResponse(['error' => false]);
        }

        default:
            fail('Unknown action.', 400);
    }
} catch (Throwable $e) {
    error_log('missions.php: ' . $e->getMessage());
    fail('Server error: ' . $e->getMessage());
}
