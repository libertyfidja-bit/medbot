<?php require_once 'connect.php'; require_once 'layout.php';
$db = getDB();
$patients = [];
$res = $db->query("SELECT id,name,room_number FROM patients ORDER BY name");
if ($res) { while ($r = $res->fetch_assoc()) $patients[] = $r; }
renderHead('Navigation Map', 'pathfinding');
renderTopbar('Hospital Navigation Map', 'A* pathfinding — doctor-confirmed robot deliveries');
?>
<style>
    .mb-note { font-size:12.5px; line-height:1.6; color:var(--text-2); padding:10px 12px; border:1px solid var(--border); border-radius:8px; }
    .mb-note-warn { border-color:#ffa94d; background:rgba(255,169,77,.10); color:var(--text-1); margin-bottom:10px; }
    .mb-note-danger { border-color:#ff4d6d; background:rgba(255,77,109,.10); color:var(--text-1); }
    .mb-mission-title { font-weight:600; color:var(--text-1); font-size:13.5px; }
    .mb-route { font-size:13px; color:var(--text-1); margin:4px 0; }
    .mb-meta { font-size:12px; color:var(--text-3); margin-top:3px; }
    .mb-big { font-size:16px; font-weight:600; color:var(--text-1); }
    .mb-bar { height:8px; border-radius:4px; background:rgba(255,255,255,.08); overflow:hidden; margin-top:10px; }
    .mb-bar > div { height:100%; width:0; background:#00d4aa; transition:width .25s linear; }
    .mb-hist { display:flex; justify-content:space-between; align-items:flex-start; gap:8px; font-size:12.5px; color:var(--text-2); padding:6px 0; border-bottom:1px solid var(--border); }
    .mb-hist:last-child { border-bottom:0; }
    .mb-input { width:100%; padding:8px 10px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text-1); font:inherit; box-sizing:border-box; }
    .mb-overlay { position:fixed; inset:0; z-index:1000; display:flex; align-items:center; justify-content:center; padding:16px; background:rgba(5,7,12,.72); }
    .mb-overlay[hidden] { display:none; }
    .mb-dialog { width:100%; max-width:440px; background:#1a1d26; border:1px solid var(--border); border-radius:12px; padding:20px; color:var(--text-2); font-size:13.5px; line-height:1.6; box-shadow:0 20px 60px rgba(0,0,0,.5); }
    .mb-dialog h3 { margin:0 0 10px; color:var(--text-1); font-size:16px; }
    .mb-dialog p { margin:6px 0; }
    .mb-actions { display:flex; flex-direction:column; gap:8px; margin-top:14px; }
    #cm-error { color:#ff4d6d; font-size:12.5px; min-height:16px; margin-top:6px; }
    @media (max-width: 900px) { .mb-layout { grid-template-columns:1fr !important; height:auto !important; } .mb-map { min-height:420px; } }
</style>
<div class="page-content" style="padding:16px">
<div class="mb-layout" style="display:grid;grid-template-columns:1fr 300px;gap:16px;height:calc(100vh - 115px)">

<!-- Map Canvas -->
<div class="card mb-map" style="margin-bottom:0;overflow:hidden;position:relative;min-height:420px">
    <div class="card-header" style="position:absolute;top:0;left:0;right:0;z-index:10;background:rgba(13,17,23,.85);backdrop-filter:blur(8px)">
        <div class="card-title">🗺️ Hospital Floor B2 — Medical Wing</div>
        <div style="display:flex;gap:8px;align-items:center">
            <span class="badge badge-info" id="path-status">Ready</span>
            <span class="badge badge-gray" id="path-distance" style="display:none"></span>
        </div>
    </div>
    <canvas id="map-canvas" aria-label="Hospital floor map showing the robot and its route"></canvas>
    <div style="position:absolute;bottom:12px;left:12px;display:flex;gap:8px;flex-wrap:wrap;pointer-events:none">
        <div style="background:rgba(13,17,23,.8);border:1px solid var(--border);border-radius:8px;padding:8px 12px;font-size:11.5px;display:flex;gap:12px;flex-wrap:wrap">
            <span><span style="display:inline-block;width:12px;height:12px;background:#1e3a5f;border-radius:2px;margin-right:4px"></span>Room</span>
            <span><span style="display:inline-block;width:12px;height:12px;background:#6c63ff;border-radius:2px;margin-right:4px"></span>Pharmacy</span>
            <span><span style="display:inline-block;width:12px;height:12px;background:#00d4aa;border-radius:6px;margin-right:4px"></span>Robot</span>
            <span><span style="display:inline-block;width:12px;height:12px;background:#ffa94d;border-radius:6px;margin-right:4px"></span>Waiting for doctor</span>
            <span><span style="display:inline-block;width:12px;height:12px;background:#ff4d6d;border-radius:2px;margin-right:4px"></span>Obstacle</span>
        </div>
    </div>
</div>

<!-- Control Panel -->
<div style="display:flex;flex-direction:column;gap:14px;overflow-y:auto">

    <div class="card" style="margin-bottom:0">
        <div class="card-header" style="padding:12px 16px"><div class="card-title">🚀 Dispatch Robot</div></div>
        <div class="card-body" style="padding:12px 16px">
            <div class="form-group" style="margin-bottom:10px">
                <label class="form-label" for="from-room">From</label>
                <select id="from-room" style="padding:8px 10px"></select>
            </div>
            <div class="form-group" style="margin-bottom:10px">
                <label class="form-label" for="to-room">To</label>
                <select id="to-room" style="padding:8px 10px"></select>
            </div>
            <div class="form-group" style="margin-bottom:10px">
                <label class="form-label" for="task-type">Service</label>
                <select id="task-type" style="padding:8px 10px"></select>
            </div>
            <div class="form-group" style="margin-bottom:10px">
                <label class="form-label" for="task-note">Note for the receiver (optional)</label>
                <input id="task-note" class="mb-input" type="text" maxlength="255" placeholder="e.g. Metformin 500 mg, bed 3">
            </div>
            <div class="form-group" style="margin-bottom:10px">
                <label class="form-label" for="speed">Robot speed</label>
                <select id="speed" style="padding:8px 10px">
                    <option value="600">Slow</option>
                    <option value="350" selected>Normal</option>
                    <option value="150">Fast</option>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:14px">
                <label class="form-label" for="requested-by">Requested by</label>
                <input id="requested-by" class="mb-input" type="text" maxlength="100" placeholder="Doctor or nurse name" autocomplete="name">
            </div>
            <div style="display:flex;flex-direction:column;gap:8px">
                <button class="btn btn-teal" id="dispatch-btn" onclick="dispatchRobot()" style="width:100%">🚀 Dispatch robot</button>
                <button class="btn btn-secondary" onclick="findPath()" style="width:100%">🗺️ Preview route</button>
                <button class="btn btn-secondary" onclick="resetMap()" style="width:100%">↺ Clear preview &amp; obstacles</button>
            </div>
        </div>
    </div>

    <div class="card" style="margin-bottom:0">
        <div class="card-header" style="padding:12px 16px"><div class="card-title">🤖 Current mission</div></div>
        <div class="card-body" style="padding:12px 16px" id="mission-card">
            <div class="mb-note">Loading…</div>
        </div>
    </div>

    <div class="card" style="margin-bottom:0">
        <div class="card-header" style="padding:12px 16px"><div class="card-title">📊 Route stats</div></div>
        <div class="card-body" style="padding:12px 16px">
            <div style="display:flex;flex-direction:column;gap:8px;font-size:13px">
                <div style="display:flex;justify-content:space-between"><span style="color:var(--text-3)">Algorithm</span><span class="badge badge-info">A* Search</span></div>
                <div style="display:flex;justify-content:space-between"><span style="color:var(--text-3)">Distance</span><span id="stat-dist" style="color:var(--text-1);font-family:var(--mono)">—</span></div>
                <div style="display:flex;justify-content:space-between"><span style="color:var(--text-3)">Steps</span><span id="stat-steps" style="color:var(--text-1);font-family:var(--mono)">—</span></div>
                <div style="display:flex;justify-content:space-between"><span style="color:var(--text-3)">Est. time</span><span id="stat-time" style="color:var(--teal);font-weight:600">—</span></div>
                <div style="display:flex;justify-content:space-between"><span style="color:var(--text-3)">Obstacles</span><span id="stat-obs" style="color:var(--text-1);font-family:var(--mono)">0</span></div>
            </div>
        </div>
    </div>

    <div class="card" style="margin-bottom:0">
        <div class="card-header" style="padding:12px 16px"><div class="card-title">🕘 Recent missions</div></div>
        <div class="card-body" style="padding:8px 16px" id="mission-history"></div>
    </div>

    <div class="card" style="margin-bottom:0">
        <div class="card-header" style="padding:12px 16px"><div class="card-title">💡 How it works</div></div>
        <div class="card-body" style="padding:12px 16px;font-size:12.5px;color:var(--text-2);line-height:1.7">
            <p>1. Choose where the robot starts, where it goes and what it carries, then dispatch it.</p>
            <p>2. The robot drives the shortest route (A*) and every screen shows the same movement.</p>
            <p>3. On arrival it <strong style="color:var(--text-1)">stops and waits</strong>. A doctor must confirm before it releases anything.</p>
            <p>Click the map to add or remove obstacles for new routes.</p>
        </div>
    </div>
</div>
</div>
</div>

<!-- Doctor confirmation dialog -->
<div id="confirm-modal" class="mb-overlay" hidden>
    <div class="mb-dialog" role="dialog" aria-modal="true" aria-labelledby="cm-title">
        <h3 id="cm-title">🤖 Robot arrived — doctor confirmation needed</h3>
        <div id="cm-body"></div>
        <label class="form-label" for="cm-doctor" style="margin-top:8px;display:block">Doctor name (saved in the log)</label>
        <input id="cm-doctor" class="mb-input" type="text" maxlength="100" placeholder="Dr. …" autocomplete="name">
        <div id="cm-error" role="alert"></div>
        <div class="mb-actions">
            <button class="btn btn-teal" id="cm-release" type="button">✅ Confirm &amp; release</button>
            <button class="btn btn-secondary" id="cm-reject" type="button">✋ Hold — do not release</button>
            <button class="btn btn-secondary" id="cm-later" type="button">Decide later</button>
        </div>
    </div>
</div>

<script>window.MEDBOT_PATIENTS = <?= json_encode($patients, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="/medbot/assets/pathfinding.js"></script>
<?php renderFoot(); ?>
