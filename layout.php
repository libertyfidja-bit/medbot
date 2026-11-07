<?php
require_once __DIR__ . '/connect.php';
require_once __DIR__ . '/auth.php';

function renderHead(string $title, string $active = 'dashboard'): void {
    $db = getDB();
    $me = auth_user();
    $role = $me['role'] ?? '';
    $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    // Count unresolved alerts for badge
    $alertCount = $db->query("SELECT COUNT(*) FROM interaction_alerts WHERE resolved=0")->fetch_row()[0] ?? 0;
    // Count new patient symptom reports for badge (table is created the first time the symptoms API runs)
    $symCount = 0;
    try {
        $sr = $db->query("SELECT COUNT(*) FROM symptom_reports WHERE status='new'");
        if ($sr) { $symCount = (int) ($sr->fetch_row()[0] ?? 0); }
    } catch (Throwable $e) { $symCount = 0; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="description" content="MedBot — AI Medication Management Robot System">
<title><?= htmlspecialchars($title) ?> — MedBot</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/medbot/assets/style.css">
<script>window.MEDBOT_ROLE = <?= json_encode($role, $jsonFlags) ?>; window.MEDBOT_USER = <?= json_encode($me['full_name'] ?? '', $jsonFlags) ?>;</script>
<style>
/* ── Phone fixes ── */
.menu-btn{display:none;background:var(--bg-3);border:1px solid var(--border-grey);color:var(--text-1);width:40px;height:40px;border-radius:10px;font-size:20px;line-height:1;cursor:pointer;flex-shrink:0}
.sidebar-backdrop{display:none}
@media(max-width:900px){
    .menu-btn{display:inline-flex;align-items:center;justify-content:center}
    .sidebar{box-shadow:var(--shadow-lg)}
    .sidebar-backdrop.show{display:block;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:90}
    .topbar{height:auto;min-height:62px;flex-wrap:wrap;gap:8px;padding:8px 12px}
    .topbar-left{min-width:0;flex:1}
    .topbar-title{font-size:15px}
    .page-content{padding-bottom:96px !important}
}
@media(max-width:600px){
    #topbar-robot-status{display:none !important}
    .topbar-sub{display:none}
    .topbar-right .btn{padding:7px 11px;font-size:12px}
    #toast-container{bottom:auto;top:72px;left:12px;right:12px}
    .toast{min-width:0;max-width:none}
    .voice-interface{bottom:16px;right:16px}
}
</style>
</head>
<body>
<div id="toast-container"></div>
<div class="layout">
<div class="sidebar-backdrop" id="sidebar-backdrop"></div>
<aside class="sidebar">
    <div class="sidebar-logo">
        <div class="logo-icon">🤖</div>
        <div>
            <div class="logo-text">MedBot</div>
            <div class="logo-sub">Medication Robot</div>
        </div>
    </div>
    <div class="sidebar-section">
        <div class="sidebar-label">Main</div>
        <nav class="sidebar-nav">
            <a href="/medbot/home.php" class="<?= $active==='home'?'active':'' ?>">
                <span class="nav-icon">🏠</span> My workspace
            </a>
            <a href="/medbot/" class="<?= $active==='dashboard'?'active':'' ?>">
                <span class="nav-icon">📊</span> Dashboard
            </a>
            <a href="/medbot/prescription.php" class="<?= $active==='prescription'?'active':'' ?>">
                <span class="nav-icon">💊</span> Prescriptions
            </a>
            <a href="/medbot/robot.php" class="<?= $active==='robot'?'active':'' ?>">
                <span class="nav-icon">🦾</span> Robot View
            </a>
            <a href="/medbot/pathfinding.php" class="<?= $active==='pathfinding'?'active':'' ?>">
                <span class="nav-icon">🗺️</span> Navigation Map
            </a>
        </nav>
    </div>
    <div class="sidebar-section">
        <div class="sidebar-label">Management</div>
        <nav class="sidebar-nav">
            <a href="/medbot/patients.php" class="<?= $active==='patients'?'active':'' ?>">
                <span class="nav-icon">👥</span> Patients
            </a>
            <a href="/medbot/symptom-reports.php" class="<?= $active==='symptoms'?'active':'' ?>">
                <span class="nav-icon">🩺</span> Symptoms
                <?php if ($symCount > 0): ?><span class="nav-badge"><?= $symCount ?></span><?php endif; ?>
            </a>
            <a href="/medbot/alerts.php" class="<?= $active==='alerts'?'active':'' ?>">
                <span class="nav-icon">⚠️</span> Alerts
                <?php if ($alertCount > 0): ?><span class="nav-badge"><?= $alertCount ?></span><?php endif; ?>
            </a>
            <a href="/medbot/schedule.php" class="<?= $active==='schedule'?'active':'' ?>">
                <span class="nav-icon">📅</span> Schedule
            </a>
            <a href="/medbot/users.php" class="<?= $active==='users'?'active':'' ?>">
                <span class="nav-icon">🔑</span> <?= $role === 'admin' ? 'Accounts' : 'Patient accounts' ?>
            </a>
        </nav>
    </div>
    <div style="flex:1"></div>
    <div class="sidebar-footer">
        <div class="conn-dot"></div>
        <div style="min-width:0;flex:1;line-height:1.3">
            <div style="color:var(--text-1);font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($me['full_name'] ?? '') ?></div>
            <div style="font-size:11px"><?= htmlspecialchars(ucfirst($role)) ?> · <a href="/medbot/change_password.php" style="color:var(--accent)">password</a> · <a href="/medbot/logout.php" style="color:var(--accent)">sign out</a></div>
        </div>
    </div>
</aside>
<div class="main">
<?php
}

function renderTopbar(string $title, string $sub = ''): void { ?>
<header class="topbar">
    <div class="topbar-left">
        <button type="button" class="menu-btn" id="menu-btn" aria-label="Open menu" aria-expanded="false">☰</button>
        <div>
            <div class="topbar-title"><?= htmlspecialchars($title) ?></div>
            <?php if ($sub): ?><div class="topbar-sub"><?= htmlspecialchars($sub) ?></div><?php endif; ?>
        </div>
    </div>
    <div class="topbar-right">
        <div class="robot-status" style="margin-bottom:0;padding:7px 14px" id="topbar-robot-status">
            <div class="robot-dot" id="topbar-dot"></div>
            <span id="topbar-status-text" style="font-size:12.5px">Loading...</span>
        </div>
        <?php $tbRole = auth_user()['role'] ?? ''; if (in_array($tbRole, ['doctor', 'admin'], true)): ?>
        <a href="/medbot/prescription.php" class="btn btn-primary">+ New Prescription</a>
        <?php endif; ?>
    </div>
</header>
<?php
}

function renderFoot(): void { ?>
<!-- Voice Interface -->
<div class="voice-interface">
    <div class="voice-bubble" id="voice-bubble">
        <div class="voice-bubble-q" id="voice-q">You said: <span id="voice-heard"></span></div>
        <div class="voice-bubble-a" id="voice-answer">🤖 Listening...</div>
    </div>
    <button class="voice-btn" id="voice-btn" title="Talk to MedBot: report symptoms or ask about medicines" aria-label="Talk to MedBot" onclick="toggleVoice()">🎙️</button>
</div>
</div><!-- .main -->
</div><!-- .layout -->
<script src="/medbot/assets/dashboard.js"></script>
<script src="/medbot/assets/voice.js"></script>
<script>
// Toast helper
function showToast(msg, type='info', dur=3500){
    const c=document.getElementById('toast-container');
    const icons={success:'✅',error:'❌',warning:'⚠️',info:'ℹ️'};
    const t=document.createElement('div');
    t.className=`toast ${type}`;
    t.innerHTML=`<span>${icons[type]||'💬'}</span><span>${msg}</span>`;
    c.appendChild(t);
    setTimeout(()=>{t.style.animation='slideIn .3s ease reverse';setTimeout(()=>t.remove(),300)},dur);
}
function openModal(id){document.getElementById(id)?.classList.add('open')}
function closeModal(id){document.getElementById(id)?.classList.remove('open')}
document.querySelectorAll('.modal-overlay').forEach(o=>o.addEventListener('click',e=>{if(e.target===o)o.classList.remove('open')}));

// Phone menu: open/close the sidebar
(function(){
    const btn=document.getElementById('menu-btn'), sb=document.querySelector('.sidebar'), bd=document.getElementById('sidebar-backdrop');
    if(!btn||!sb) return;
    function setOpen(o){
        sb.classList.toggle('open',o);
        if(bd) bd.classList.toggle('show',o);
        btn.setAttribute('aria-expanded',o?'true':'false');
    }
    btn.addEventListener('click',()=>setOpen(!sb.classList.contains('open')));
    if(bd) bd.addEventListener('click',()=>setOpen(false));
    sb.querySelectorAll('a').forEach(a=>a.addEventListener('click',()=>setOpen(false)));
    document.addEventListener('keydown',e=>{if(e.key==='Escape')setOpen(false)});
})();
</script>
</body>
</html>
<?php
}
?>
