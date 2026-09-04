<?php
require_once __DIR__ . '/connect.php';

function renderHead(string $title, string $active = 'dashboard'): void {
    $db = getDB();
    // Count unresolved alerts for badge
    $alertCount = $db->query("SELECT COUNT(*) FROM interaction_alerts WHERE resolved=0")->fetch_row()[0] ?? 0;
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
</head>
<body>
<div id="toast-container"></div>
<div class="layout">
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
            <a href="/medbot/alerts.php" class="<?= $active==='alerts'?'active':'' ?>">
                <span class="nav-icon">⚠️</span> Alerts
                <?php if ($alertCount > 0): ?><span class="nav-badge"><?= $alertCount ?></span><?php endif; ?>
            </a>
            <a href="/medbot/schedule.php" class="<?= $active==='schedule'?'active':'' ?>">
                <span class="nav-icon">📅</span> Schedule
            </a>
        </nav>
    </div>
    <div style="flex:1"></div>
    <div class="sidebar-footer">
        <div class="conn-dot"></div>
        <span>MySQL connected</span>
    </div>
</aside>
<div class="main">
<?php
}

function renderTopbar(string $title, string $sub = ''): void { ?>
<header class="topbar">
    <div class="topbar-left">
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
        <a href="/medbot/prescription.php" class="btn btn-primary">+ New Prescription</a>
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
    <button class="voice-btn" id="voice-btn" title="Ask MedBot a question" onclick="toggleVoice()">🎙️</button>
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
</script>
</body>
</html>
<?php
}
?>
