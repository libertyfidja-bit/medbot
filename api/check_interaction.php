<?php
require_once '../connect.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$drug1 = trim($_GET['drug1'] ?? $_POST['drug1'] ?? '');
$drug2 = trim($_GET['drug2'] ?? $_POST['drug2'] ?? '');
$patientId = (int)($_GET['patient_id'] ?? 0);

if (!$drug1) {
    jsonResponse(['error' => true, 'message' => 'drug1 is required']);
}

// Step 1: Get RxCUI for each drug from RxNorm
function getRxCUI(string $drugName): ?string {
    $url = 'https://rxnav.nlm.nih.gov/REST/rxcui.json?name=' . urlencode($drugName) . '&allsrc=0&srclist=&search=1';
    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $res = @file_get_contents($url, false, $ctx);
    if (!$res) return null;
    $data = json_decode($res, true);
    return $data['idGroup']['rxnormId'][0] ?? null;
}

// Step 2: Check interactions via RxNorm interaction API
function checkRxNormInteraction(string $rxcui1, string $rxcui2): array {
    $url = "https://rxnav.nlm.nih.gov/REST/interaction/list.json?rxcuis={$rxcui1}+{$rxcui2}";
    $ctx = stream_context_create(['http' => ['timeout' => 6]]);
    $res = @file_get_contents($url, false, $ctx);
    if (!$res) return [];
    $data = json_decode($res, true);
    $interactions = [];
    $groups = $data['fullInteractionTypeGroup'] ?? [];
    foreach ($groups as $group) {
        foreach ($group['fullInteractionType'] ?? [] as $type) {
            foreach ($type['interactionPair'] ?? [] as $pair) {
                $interactions[] = [
                    'severity' => strtolower($pair['severity'] ?? 'unknown'),
                    'description' => $pair['description'] ?? 'Potential interaction detected'
                ];
            }
        }
    }
    return $interactions;
}

// Step 3: OpenFDA label search for single drug warnings
function getOpenFDAWarnings(string $drugName): array {
    $url = 'https://api.fda.gov/drug/label.json?search=openfda.brand_name:"' . urlencode($drugName) . '"&limit=1';
    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $res = @file_get_contents($url, false, $ctx);
    if (!$res) return [];
    $data = json_decode($res, true);
    $warnings = $data['results'][0]['warnings'] ?? [];
    $boxed = $data['results'][0]['boxed_warning'] ?? [];
    return array_merge((array)$warnings, (array)$boxed);
}

$result = [
    'drug1' => $drug1,
    'drug2' => $drug2,
    'rxcui1' => null,
    'rxcui2' => null,
    'interactions' => [],
    'warnings_drug1' => [],
    'overall_severity' => 'safe',
    'safe' => true
];

$rxcui1 = getRxCUI($drug1);
$result['rxcui1'] = $rxcui1;

if ($drug2) {
    $rxcui2 = getRxCUI($drug2);
    $result['rxcui2'] = $rxcui2;
    if ($rxcui1 && $rxcui2) {
        $interactions = checkRxNormInteraction($rxcui1, $rxcui2);
        $result['interactions'] = $interactions;
        if (!empty($interactions)) {
            $result['safe'] = false;
            $severities = array_column($interactions, 'severity');
            if (in_array('high', $severities)) $result['overall_severity'] = 'danger';
            elseif (in_array('moderate', $severities) || in_array('medium', $severities)) $result['overall_severity'] = 'warning';
            else $result['overall_severity'] = 'info';
        }
    }
}

$fdaWarnings = getOpenFDAWarnings($drug1);
$result['warnings_drug1'] = array_slice($fdaWarnings, 0, 2);

// Save to DB if we have patient
if ($patientId && !$result['safe']) {
    $db = getDB();
    $sev = $result['overall_severity'];
    $desc = $result['interactions'][0]['description'] ?? 'Interaction detected';
    $stmt = $db->prepare("INSERT INTO interaction_alerts (patient_id, severity, drug_a, drug_b, description) VALUES (?,?,?,?,?)");
    $stmt->bind_param('issss', $patientId, $sev, $drug1, $drug2, $desc);
    $stmt->execute();
    $stmt->close();
}

echo json_encode($result);
