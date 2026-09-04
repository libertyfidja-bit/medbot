// MedBot Dashboard JS — live updates, robot status, adherence ring
(function() {
    // Update robot status in topbar
    async function updateRobotStatus() {
        try {
            const res = await fetch('/medbot/api/robot_action.php?action=status');
            const data = await res.json();
            const state = data.state;
            if (!state) return;
            const dot = document.getElementById('topbar-dot');
            const txt = document.getElementById('topbar-status-text');
            const robotDot = document.getElementById('robot-dot');
            const robotTxt = document.getElementById('robot-status-text');
            const robotLoc = document.getElementById('robot-location');
            const action = state.current_action || 'idle';
            const busy = action !== 'idle';
            if (dot) { dot.className = 'conn-dot' + (busy ? ' busy' : ''); }
            if (txt) txt.textContent = busy ? action : '🤖 Idle — Ready';
            if (robotDot) robotDot.className = 'robot-dot' + (busy ? ' busy' : '');
            if (robotTxt) robotTxt.textContent = busy ? action : 'Idle — Ready';
            if (robotLoc) robotLoc.textContent = '📍 ' + (state.current_location || 'Pharmacy');
        } catch(e) {}
    }

    // Update adherence ring
    async function updateAdherence() {
        try {
            const res = await fetch('/medbot/api/adherence.php?days=7');
            const data = await res.json();
            const pct = data.adherence_pct || 0;
            const circle = document.getElementById('adherence-circle');
            const pctEl = document.getElementById('adherence-pct');
            if (circle) {
                const circumference = 314;
                const offset = circumference - (pct / 100) * circumference;
                circle.style.transition = 'stroke-dashoffset 1.5s ease';
                circle.setAttribute('stroke-dashoffset', offset);
            }
            if (pctEl) {
                pctEl.textContent = pct + '%';
                pctEl.style.background = pct >= 80
                    ? 'linear-gradient(135deg,var(--teal),var(--accent))'
                    : pct >= 50
                    ? 'linear-gradient(135deg,var(--warning),var(--accent))'
                    : 'linear-gradient(135deg,var(--danger),var(--warning))';
                pctEl.style.webkitBackgroundClip = 'text';
                pctEl.style.backgroundClip = 'text';
                pctEl.style.webkitTextFillColor = 'transparent';
            }
        } catch(e) {}
    }

    // Auto-refresh activity log
    async function refreshActivityLog() {
        const logEl = document.getElementById('activity-log');
        if (!logEl) return;
        try {
            const res = await fetch('/medbot/api/robot_action.php?action=status');
            const data = await res.json();
            const logs = data.recent_log || [];
            if (!logs.length) return;
            const icons = { dispense:'💊', pick:'🦾', place:'📦', navigate:'🗺️', alert:'⚠️' };
            logEl.innerHTML = logs.map(l => `
                <div class="log-item">
                    <span class="log-icon">${icons[l.action_type] || '🤖'}</span>
                    <span class="log-text">
                        <strong>${l.action_type ? l.action_type.charAt(0).toUpperCase() + l.action_type.slice(1) : ''}</strong>
                        ${l.medication_name ? ' — ' + l.medication_name : ''}
                        ${l.patient_name ? ' for ' + l.patient_name : ''}
                        ${l.to_location ? ' → ' + l.to_location : ''}
                    </span>
                    <span class="log-time">${l.created_at ? l.created_at.substring(11,16) : ''}</span>
                </div>
            `).join('');
        } catch(e) {}
    }

    // Alert badge update
    async function updateAlertBadge() {
        try {
            const res = await fetch('/medbot/api/alerts.php');
            const data = await res.json();
            const badges = document.querySelectorAll('.nav-badge');
            badges.forEach(b => { b.textContent = data.count || 0; b.style.display = data.count > 0 ? '' : 'none'; });
        } catch(e) {}
    }

    // Init
    function init() {
        updateRobotStatus();
        updateAdherence();
        refreshActivityLog();
        updateAlertBadge();
        setInterval(updateRobotStatus, 5000);
        setInterval(updateAdherence, 30000);
        setInterval(refreshActivityLog, 10000);
        setInterval(updateAlertBadge, 15000);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
