<?php
/**
 * MedBot — symptom reports API              (place this file at: api/symptoms.php)
 *
 *   GET  ?action=patients                       -> names for the "who is speaking?" list
 *   POST {action:"create", patient_id, transcript, lang, source, pain_level, duration}
 *   GET  ?action=list&view=open|resolved        -> reports for nurses and doctors
 *   POST {action:"update", id, decision:"acknowledge"|"resolve", handled_by, reply}
 *
 * Every report gets an automatic urgency HINT (urgent / attention / routine) from warning
 * words and the pain level. It is NOT a diagnosis; a nurse or doctor always reads the report.
 * The symptom_reports table is created automatically the first time this API runs.
 */
require_once __DIR__ . '/../connect.php';
mysqli_report(MYSQLI_REPORT_OFF);
header('Cache-Control: no-store');

// Warning words (English, French, a little Swahili, and DRAFT Kinyarwanda terms). Matching is deliberately cautious:
// "no chest pain" is still flagged, because showing a nurse too much is safer than too little.
// The Kinyarwanda words are a starting list: please have a Kinyarwanda-speaking nurse or
// doctor check and extend them. Add more words by adding alternatives inside a pattern.
const URGENT_PATTERNS = [
    'chest pain'         => '/chest (pain|tight|pressure|hurts?)|pain in (my |the )?chest|douleur (thoracique|(à|dans) la poitrine)|maumivu ya kifua|gituza/iu',
    'trouble breathing'  => '/(can.?t|cannot|hard to|trouble|difficulty|struggl\w+( to)?) breath|short(ness)? of breath|breathless|gasping|du mal à respirer|difficil\w* (de|à) respirer|difficulté (de|à) respirer|essouffl|ne peux pas respirer|siwezi kupumua|guhumeka|guhema|mwuka/iu',
    'bleeding'           => '/bleed|blood (loss|in my|in the)|vomit(ing)? blood|saign|h[ée]morragie|kutokwa na damu|amaraso/iu',
    'fainting'           => '/faint|pass(ed|ing)? out|unconscious|collaps|unresponsive|[ée]vanou|inconscient|perdu connaissance|nimezimia|guta ubwenge|taye ubwenge|buze ubwenge/iu',
    'seizure'            => '/seizure|convuls|gicuri/iu',
    'stroke signs'       => '/slurred speech|face (is )?droop|sudden weakness|numb(ness)? (on )?one side|paralys|paralysie|gagara|gagaye/iu',
    'allergic reaction'  => '/swelling (of|in) (my |the )?(face|throat|tongue|lips)|throat (is )?closing|allergic reaction|anaphyla/iu',
    'self-harm'          => '/suicid|kill myself|want to die|end my life|envie de mourir|me tuer|kwiyahura|shaka gupfa/iu',
    'severe pain'        => '/severe pain|unbearable|worst pain|extreme pain|forte douleur|douleur (insupportable|atroce)|kubabara cyane|ububabare bukabije|ndababara cyane/iu',
];
const ATTENTION_PATTERNS = [
    'fever'      => '/fever|high temperature|fi[èe]vre|homa|umuriro/iu',
    'vomiting'   => '/vomit|throwing up|vomi|kutapika|kuruka|ndaruka|aruka/iu',
    'dizziness'  => '/dizz|vertigo|vertige|lightheaded|isereri|zunguz|zungera/iu',
    'headache'   => '/headache|mal de t[êe]te|maumivu ya kichwa|mutwe/iu',
    'diarrhoea'  => '/diarrh|impiswi|cibwamo/iu',
    'cough'      => '/cough|toux|kikohozi|inkorora|korora/iu',
    'rash'       => '/rash|itch|[ée]ruption|d[ée]mangeaison|kwishimagura|ishimagura|ibishishi/iu',
    'nausea'     => '/nause|iseseme/iu',
    'weakness'   => '/weak|exhaust|faible|fatigu|intege nke|naniwe|ananiwe/iu',
    'pain'       => '/pain|hurt|ache|douleur|mal (à|au|aux)|maumivu|babara|babaza|ububabare/iu',
];
const DURATIONS = ['just_now', 'today', 'few_days', 'week_plus'];

function fail(string $msg, int $code = 500): never {
    jsonResponse(['error' => true, 'message' => $msg], $code);
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
    $probe = $db->query('SELECT 1 FROM symptom_reports LIMIT 1');
    if ($probe) { $probe->free(); return; }
    $ok = $db->query("CREATE TABLE IF NOT EXISTS symptom_reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        patient_id INT NULL,
        patient_name VARCHAR(150),
        room_number VARCHAR(20),
        transcript TEXT NOT NULL,
        lang VARCHAR(10),
        source ENUM('voice','typed','emergency') NOT NULL DEFAULT 'typed',
        pain_level TINYINT NULL,
        duration VARCHAR(20) NULL,
        urgency ENUM('routine','attention','urgent') NOT NULL DEFAULT 'routine',
        flags VARCHAR(255) NULL,
        status ENUM('new','acknowledged','resolved') NOT NULL DEFAULT 'new',
        handled_by VARCHAR(100) NULL,
        staff_reply TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        handled_at TIMESTAMP NULL
    )");
    if (!$ok) fail('Could not create the symptom_reports table: ' . $db->error);
}

/** Returns [urgency, flags text]. A hint for staff, never a diagnosis. */
function assess(string $text, ?int $pain, string $source): array {
    $urgent = [];
    $attention = [];
    foreach (URGENT_PATTERNS as $label => $re) {
        if (@preg_match($re, $text) === 1) $urgent[] = $label;
    }
    foreach (ATTENTION_PATTERNS as $label => $re) {
        if (@preg_match($re, $text) === 1) $attention[] = $label;
    }
    if ($source === 'emergency') array_unshift($urgent, 'emergency button');
    if ($pain !== null && $pain >= 8) $urgent[] = 'pain ' . $pain . '/10';
    elseif ($pain !== null && $pain >= 5) $attention[] = 'pain ' . $pain . '/10';

    if ($urgent)    return ['urgent', implode(', ', array_unique(array_merge($urgent, $attention)))];
    if ($attention) return ['attention', implode(', ', array_unique($attention))];
    return ['routine', ''];
}

function present(array $r): array {
    return [
        'id'           => (int) $r['id'],
        'patient_id'   => $r['patient_id'] === null ? null : (int) $r['patient_id'],
        'patient_name' => $r['patient_name'],
        'room_number'  => $r['room_number'],
        'transcript'   => $r['transcript'],
        'lang'         => $r['lang'],
        'source'       => $r['source'],
        'pain_level'   => $r['pain_level'] === null ? null : (int) $r['pain_level'],
        'duration'     => $r['duration'],
        'urgency'      => $r['urgency'],
        'flags'        => $r['flags'] === null || $r['flags'] === '' ? [] : explode(', ', $r['flags']),
        'status'       => $r['status'],
        'handled_by'   => $r['handled_by'],
        'staff_reply'  => $r['staff_reply'],
        'created_at'   => $r['created_at'],
        'age_min'      => isset($r['age_min']) ? (int) $r['age_min'] : null,
    ];
}

try {
    $db = getDB();
    mysqli_report(MYSQLI_REPORT_OFF);
    ensureTable($db);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $in = [];
    if ($method === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true);
        if (!is_array($in)) $in = $_POST;
    }
    $action = $in['action'] ?? ($_GET['action'] ?? 'list');

    switch ($action) {

        case 'patients': {
            $out = [];
            $res = $db->query('SELECT id, name, room_number FROM patients ORDER BY name');
            if ($res) {
                while ($p = $res->fetch_assoc()) $out[] = ['id' => (int) $p['id'], 'name' => $p['name'], 'room_number' => $p['room_number']];
                $res->free();
            }
            jsonResponse(['error' => false, 'patients' => $out]);
        }

        case 'create': {
            if ($method !== 'POST') fail('POST required.', 405);
            $source = in_array($in['source'] ?? '', ['voice', 'typed', 'emergency'], true) ? $in['source'] : 'typed';
            $text   = clean($in['transcript'] ?? '', 2000);
            if ($source !== 'emergency' && mb_strlen($text) < 3) fail('Please say or type at least a few words.', 400);
            if ($text === '') $text = 'EMERGENCY: patient pressed the call-nurse button.';

            $lang = preg_match('/^[a-z]{2,3}(-[A-Za-z]{2,4})?$/', (string) ($in['lang'] ?? '')) ? (string) $in['lang'] : null;

            $pain = null;
            if (isset($in['pain_level']) && $in['pain_level'] !== '' && $in['pain_level'] !== null) {
                $pain = max(0, min(10, (int) $in['pain_level']));
            }
            $dur = in_array($in['duration'] ?? '', DURATIONS, true) ? $in['duration'] : null;

            $pid = (int) ($in['patient_id'] ?? 0);
            $pname = 'Unknown (no name chosen)';
            $room  = null;
            if ($pid > 0) {
                $p = one($db, 'SELECT id, name, room_number FROM patients WHERE id = ?', 'i', [$pid]);
                if (!$p) fail('Unknown patient.', 400);
                $pname = $p['name'];
                $room  = $p['room_number'];
                $recent = one($db, 'SELECT COUNT(*) AS n FROM symptom_reports WHERE patient_id = ? AND created_at > (NOW() - INTERVAL 60 SECOND)', 'i', [$pid]);
                if ($recent && (int) $recent['n'] >= 5) fail('Too many reports in a short time. Please wait a minute.', 429);
            } elseif ($source !== 'emergency') {
                fail('Please choose who is speaking.', 400);
            }

            [$urgency, $flags] = assess($text, $pain, $source);

            $stmt = run(
                $db,
                'INSERT INTO symptom_reports (patient_id, patient_name, room_number, transcript, lang, source, pain_level, duration, urgency, flags) VALUES (?,?,?,?,?,?,?,?,?,?)',
                'isssssisss',
                [$pid > 0 ? $pid : null, $pname, $room, $text, $lang, $source, $pain, $dur, $urgency, $flags]
            );
            $id = (int) $stmt->insert_id;
            $stmt->close();

            $r = one($db, 'SELECT * FROM symptom_reports WHERE id = ?', 'i', [$id]);
            jsonResponse(['error' => false, 'report' => present($r)], 201);
        }

        case 'list': {
            $view  = (($_GET['view'] ?? 'open') === 'resolved') ? 'resolved' : 'open';
            $where = $view === 'resolved' ? "status = 'resolved'" : "status <> 'resolved'";
            $order = $view === 'resolved'
                ? 'handled_at DESC, id DESC'
                : "CASE urgency WHEN 'urgent' THEN 0 WHEN 'attention' THEN 1 ELSE 2 END, CASE status WHEN 'new' THEN 0 ELSE 1 END, id DESC";
            $reports = [];
            $res = $db->query("SELECT *, TIMESTAMPDIFF(MINUTE, created_at, NOW()) AS age_min FROM symptom_reports WHERE $where ORDER BY $order LIMIT 50");
            if ($res) {
                while ($r = $res->fetch_assoc()) $reports[] = present($r);
                $res->free();
            }
            $counts = one($db, "SELECT COALESCE(SUM(status <> 'resolved' AND urgency = 'urgent'), 0) AS urgent_open, COALESCE(SUM(status = 'new'), 0) AS new_count, COALESCE(SUM(status <> 'resolved'), 0) AS open_count FROM symptom_reports") ?: [];
            jsonResponse([
                'error'   => false,
                'view'    => $view,
                'reports' => $reports,
                'counts'  => [
                    'urgent_open' => (int) ($counts['urgent_open'] ?? 0),
                    'new'         => (int) ($counts['new_count'] ?? 0),
                    'open'        => (int) ($counts['open_count'] ?? 0),
                ],
            ]);
        }

        case 'update': {
            if ($method !== 'POST') fail('POST required.', 405);
            $id       = (int) ($in['id'] ?? 0);
            $decision = (string) ($in['decision'] ?? '');
            $by       = clean($in['handled_by'] ?? '', 100);
            $reply    = clean($in['reply'] ?? '', 1000);
            if (!in_array($decision, ['acknowledge', 'resolve'], true)) fail('Decision must be acknowledge or resolve.', 400);
            if ($by === '') fail('Enter your name first.', 400);

            $r = one($db, 'SELECT * FROM symptom_reports WHERE id = ?', 'i', [$id]);
            if (!$r) fail('Report not found.', 404);

            if ($decision === 'acknowledge') {
                if ($r['status'] !== 'new') fail('This report was already acknowledged by ' . ($r['handled_by'] ?: 'someone') . '.', 409);
                $stmt = run($db, "UPDATE symptom_reports SET status = 'acknowledged', handled_by = ?, handled_at = NOW(), staff_reply = COALESCE(NULLIF(?, ''), staff_reply) WHERE id = ? AND status = 'new'", 'ssi', [$by, $reply, $id]);
            } else {
                if ($r['status'] === 'resolved') fail('This report is already resolved.', 409);
                $stmt = run($db, "UPDATE symptom_reports SET status = 'resolved', handled_by = ?, handled_at = NOW(), staff_reply = COALESCE(NULLIF(?, ''), staff_reply) WHERE id = ? AND status <> 'resolved'", 'ssi', [$by, $reply, $id]);
            }
            $changed = $stmt->affected_rows > 0;
            $stmt->close();
            if (!$changed) fail('Someone else already updated this report.', 409);

            $r = one($db, 'SELECT * FROM symptom_reports WHERE id = ?', 'i', [$id]);
            jsonResponse(['error' => false, 'report' => present($r)]);
        }

        default:
            fail('Unknown action.', 400);
    }
} catch (Throwable $e) {
    error_log('symptoms.php: ' . $e->getMessage());
    fail('Server error: ' . $e->getMessage());
}
