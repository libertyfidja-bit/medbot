<?php
require_once 'connect.php';
require_once 'layout.php';
require_once __DIR__ . '/billing_lib.php';

$me = auth_user();
auth_start_session();
$db = getDB();
auth_ensure_tables($db);
bill_boot($db);

function ins_pct($v): ?float {
    $v = trim((string) $v);
    if ($v === '' || !is_numeric($v) || (float) $v < 0 || (float) $v > 100) return null;
    return (float) $v;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $flash = ['type' => 'error', 'msg' => 'This page expired. Please try again.'];

    if (auth_csrf_ok()) {
        $act = (string) ($_POST['action'] ?? '');

        if ($act === 'save') {
            $id  = (int) ($_POST['id'] ?? 0);
            $pct = ins_pct($_POST['coverage_pct'] ?? '');
            $active = !empty($_POST['active']) ? 1 : 0;
            $row = auth_row($db, 'SELECT id, kind FROM insurers WHERE id = ?', 'i', [$id]);
            if (!$row) {
                $flash = ['type' => 'error', 'msg' => 'Insurer not found.'];
            } elseif ($pct === null) {
                $flash = ['type' => 'error', 'msg' => 'The percentage must be a number from 0 to 100.'];
            } else {
                if ($row['kind'] === 'none') { $pct = 0.0; $active = 1; }       // “No insurance” always stays on, at 0%
                auth_exec($db, 'UPDATE insurers SET coverage_pct = ?, active = ? WHERE id = ?', 'dii', [$pct, $active, $id]);
                $flash = ['type' => 'ok', 'msg' => 'Saved.'];
            }
        } elseif ($act === 'add') {
            $name = mb_substr(trim(strip_tags((string) ($_POST['name'] ?? ''))), 0, 120);
            $kind = in_array($_POST['kind'] ?? '', ['public', 'private'], true) ? $_POST['kind'] : 'private';
            $pct  = ins_pct($_POST['coverage_pct'] ?? '');
            if (mb_strlen($name) < 2) {
                $flash = ['type' => 'error', 'msg' => 'Enter the insurer’s name.'];
            } elseif ($pct === null) {
                $flash = ['type' => 'error', 'msg' => 'The percentage must be a number from 0 to 100.'];
            } elseif (auth_row($db, 'SELECT id FROM insurers WHERE name = ?', 's', [$name])) {
                $flash = ['type' => 'error', 'msg' => 'That insurer is already in the list.'];
            } else {
                auth_exec($db, 'INSERT INTO insurers (name, kind, coverage_pct, sort_order) VALUES (?,?,?,50)', 'ssd', [$name, $kind, $pct]);
                $flash = ['type' => 'ok', 'msg' => $name . ' added.'];
            }
        }
    }
    $_SESSION['flash'] = $flash;
    header('Location: insurers.php');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$list = [];
$res = $db->query('SELECT i.id, i.name, i.kind, i.coverage_pct, i.active, (SELECT COUNT(*) FROM patient_profile pp WHERE pp.insurer_id = i.id) AS patients
                   FROM insurers i ORDER BY i.sort_order, i.name');
if ($res) { while ($r = $res->fetch_assoc()) $list[] = $r; $res->free(); }
$csrf = auth_csrf();

renderHead('Insurers', 'insurers');
renderTopbar('Insurers', 'Which insurances are accepted and how much each one pays');
?>
<div class="page-content">

    <div class="alert alert-warning" role="status"><span class="alert-icon">ℹ️</span><div>
        These percentages are <strong>starting values</strong>. Published sources differ, and rates change with the scheme, the type of facility and the member’s category or policy. Please confirm them with each insurer (for example RSSB for Mutuelle de Santé and RAMA) and correct them here. A patient’s own percentage can also be set when registering them.
    </div></div>

<?php if ($flash): ?>
    <div class="alert <?= $flash['type'] === 'ok' ? 'alert-success' : 'alert-error' ?>" role="status"><span class="alert-icon"><?= $flash['type'] === 'ok' ? '✅' : '⚠️' ?></span><div><?= auth_h($flash['msg']) ?></div></div>
<?php endif; ?>

    <div class="card">
        <div class="card-header"><div class="card-title">🏥 Insurance list</div></div>
        <div class="table-wrap">
        <table class="dt">
            <thead><tr><th>Insurer</th><th>Type</th><th>Insurer pays (%)</th><th>Patients</th><th>Offered</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($list as $i): $fid = 'f' . (int) $i['id']; ?>
                <tr>
                    <td><?= auth_h($i['name']) ?>
                        <form id="<?= $fid ?>" method="post" action="insurers.php"><input type="hidden" name="csrf" value="<?= auth_h($csrf) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) $i['id'] ?>"></form></td>
                    <td><span class="badge badge-info"><?= auth_h($i['kind']) ?></span></td>
                    <td style="max-width:120px"><input form="<?= $fid ?>" name="coverage_pct" type="number" min="0" max="100" step="0.01" value="<?= auth_h(rtrim(rtrim(number_format((float) $i['coverage_pct'], 2, '.', ''), '0'), '.')) ?>"<?= $i['kind'] === 'none' ? ' readonly' : '' ?>></td>
                    <td><?= (int) $i['patients'] ?></td>
                    <td><label style="display:flex;gap:6px;align-items:center"><input form="<?= $fid ?>" type="checkbox" name="active" value="1" style="width:auto"<?= (int) $i['active'] === 1 ? ' checked' : '' ?><?= $i['kind'] === 'none' ? ' disabled' : '' ?>> yes</label></td>
                    <td><button form="<?= $fid ?>" class="btn btn-primary btn-xs" type="submit">Save</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><div class="card-title">➕ Add an insurer</div></div>
        <div class="card-body">
            <form method="post" action="insurers.php" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= auth_h($csrf) ?>"><input type="hidden" name="action" value="add">
                <div class="form-grid">
                    <div class="form-group"><label class="form-label" for="i-name">Name</label><input id="i-name" name="name" type="text" maxlength="120" required></div>
                    <div class="form-group"><label class="form-label" for="i-kind">Type</label><select id="i-kind" name="kind"><option value="private">Private</option><option value="public">Public</option></select></div>
                    <div class="form-group"><label class="form-label" for="i-pct">Insurer pays (%)</label><input id="i-pct" name="coverage_pct" type="number" min="0" max="100" step="0.01" required></div>
                </div>
                <div style="margin-top:12px"><button class="btn btn-primary" type="submit">Add insurer</button></div>
            </form>
        </div>
    </div>
</div>
<?php renderFoot(); ?>
