<?php require_once 'connect.php'; require_once 'layout.php';
$db = getDB();
$patients = [];
$res = $db->query("SELECT id,name,room_number FROM patients ORDER BY name");
while ($r = $res->fetch_assoc()) $patients[] = $r;
renderHead('Navigation Map', 'pathfinding');
renderTopbar('Hospital Navigation Map', 'A* pathfinding — robot delivery simulation');
?>
<div class="page-content" style="padding:16px">
<div style="display:grid;grid-template-columns:1fr 280px;gap:16px;height:calc(100vh - 115px)">

<!-- Map Canvas -->
<div class="card" style="margin-bottom:0;overflow:hidden;position:relative">
    <div class="card-header" style="position:absolute;top:0;left:0;right:0;z-index:10;background:rgba(13,17,23,.85);backdrop-filter:blur(8px)">
        <div class="card-title">🗺️ Hospital Floor B2 — Medical Wing</div>
        <div style="display:flex;gap:8px;align-items:center">
            <span class="badge badge-info" id="path-status">Ready</span>
            <span class="badge badge-gray" id="path-distance" style="display:none"></span>
        </div>
    </div>
    <canvas id="map-canvas" style="width:100%;height:100%;display:block;cursor:crosshair"></canvas>
    <div style="position:absolute;bottom:12px;left:12px;display:flex;gap:8px;flex-wrap:wrap">
        <div style="background:rgba(13,17,23,.8);border:1px solid var(--border);border-radius:8px;padding:8px 12px;font-size:11.5px;display:flex;gap:12px;flex-wrap:wrap">
            <span><span style="display:inline-block;width:12px;height:12px;background:#1e3a5f;border-radius:2px;margin-right:4px"></span>Room</span>
            <span><span style="display:inline-block;width:12px;height:12px;background:#6c63ff;border-radius:2px;margin-right:4px"></span>Pharmacy</span>
            <span><span style="display:inline-block;width:12px;height:12px;background:#00d4aa;border-radius:6px;margin-right:4px"></span>Robot</span>
            <span><span style="display:inline-block;width:12px;height:12px;background:rgba(108,99,255,.3);border-radius:2px;margin-right:4px"></span>Path</span>
            <span><span style="display:inline-block;width:12px;height:12px;background:#ff4d6d;border-radius:2px;margin-right:4px"></span>Obstacle</span>
        </div>
    </div>
</div>

<!-- Control Panel -->
<div style="display:flex;flex-direction:column;gap:14px;overflow-y:auto">
    <div class="card" style="margin-bottom:0">
        <div class="card-header" style="padding:12px 16px"><div class="card-title">🚀 Dispatch Robot</div></div>
        <div class="card-body" style="padding:12px 16px">
            <div class="form-group" style="margin-bottom:12px">
                <label class="form-label">From</label>
                <select id="from-room" style="padding:8px 10px">
                    <option value="pharmacy">🏥 Pharmacy (Origin)</option>
                    <option value="nurses">👩‍⚕️ Nurses Station</option>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:14px">
                <label class="form-label">To — Patient Room</label>
                <select id="to-room" style="padding:8px 10px">
                    <option value="">— Select destination —</option>
                    <?php foreach ($patients as $p): ?>
                    <option value="room_<?= $p['id'] ?>" data-room="<?= htmlspecialchars($p['room_number']) ?>">
                        <?= htmlspecialchars($p['name']) ?> — Room <?= htmlspecialchars($p['room_number'] ?: 'N/A') ?>
                    </option>
                    <?php endforeach; ?>
                    <option value="room_101">Room 101</option>
                    <option value="room_102">Room 102</option>
                    <option value="room_103">Room 103</option>
                    <option value="room_104">Room 104</option>
                    <option value="room_105">Room 105</option>
                    <option value="nurses">Nurses Station</option>
                    <option value="icu">ICU</option>
                </select>
            </div>
            <div style="display:flex;flex-direction:column;gap:8px">
                <button class="btn btn-primary" onclick="findPath()" style="width:100%">🗺️ Find Path (A*)</button>
                <button class="btn btn-teal" onclick="animateRobot()" id="animate-btn" disabled style="width:100%">▶ Animate Robot</button>
                <button class="btn btn-secondary" onclick="resetMap()" style="width:100%">↺ Reset</button>
            </div>
        </div>
    </div>

    <div class="card" style="margin-bottom:0">
        <div class="card-header" style="padding:12px 16px"><div class="card-title">📊 Path Stats</div></div>
        <div class="card-body" style="padding:12px 16px">
            <div style="display:flex;flex-direction:column;gap:8px;font-size:13px">
                <div style="display:flex;justify-content:space-between"><span style="color:var(--text-3)">Algorithm</span><span class="badge badge-info">A* Search</span></div>
                <div style="display:flex;justify-content:space-between"><span style="color:var(--text-3)">Distance</span><span id="stat-dist" style="color:var(--text-1);font-family:var(--mono)">—</span></div>
                <div style="display:flex;justify-content:space-between"><span style="color:var(--text-3)">Steps</span><span id="stat-steps" style="color:var(--text-1);font-family:var(--mono)">—</span></div>
                <div style="display:flex;justify-content:space-between"><span style="color:var(--text-3)">Est. Time</span><span id="stat-time" style="color:var(--teal);font-weight:600">—</span></div>
                <div style="display:flex;justify-content:space-between"><span style="color:var(--text-3)">Obstacles</span><span id="stat-obs" style="color:var(--text-1);font-family:var(--mono)">0</span></div>
            </div>
        </div>
    </div>

    <div class="card" style="margin-bottom:0">
        <div class="card-header" style="padding:12px 16px"><div class="card-title">💡 How it works</div></div>
        <div class="card-body" style="padding:12px 16px;font-size:12.5px;color:var(--text-2);line-height:1.7">
            <p><strong style="color:var(--accent)">A* pathfinding</strong> calculates the optimal route from the pharmacy to any patient room, avoiding walls and obstacles.</p>
            <br>
            <p>Click anywhere on the map to <strong style="color:var(--danger)">add/remove obstacles</strong> (equipment, furniture).</p>
            <br>
            <p>The robot icon animates along the computed path in real time.</p>
        </div>
    </div>
</div>
</div>
</div>

<script src="/medbot/assets/pathfinding.js"></script>
<?php renderFoot(); ?>
