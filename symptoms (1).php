<?php require_once 'connect.php'; require_once 'layout.php';
renderHead('Symptom Reports', 'symptoms');
renderTopbar('Symptom Reports', 'What patients told MedBot, for nurses and doctors');
?>
<style>
    .sr-card { border-left:4px solid var(--border-grey); }
    .sr-card.urgent { border-left-color:var(--danger); }
    .sr-card.attention { border-left-color:var(--warning); }
    .sr-card.routine { border-left-color:var(--teal); }
    .sr-quote { margin:0 0 10px; padding:10px 12px; background:var(--bg-0); border:1px solid var(--border); border-radius:var(--radius-sm); color:var(--text-1); font-size:14px; line-height:1.6; white-space:pre-wrap; word-break:break-word; }
    .sr-meta { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:10px; font-size:12px; color:var(--text-3); align-items:center; }
    .sr-reply { margin-top:8px; padding:8px 12px; border:1px solid var(--border-accent); border-radius:var(--radius-sm); background:rgba(124,109,255,.08); color:var(--text-1); font-size:13px; }
    .sr-actions { display:flex; flex-wrap:wrap; gap:8px; margin-top:8px; }
    .sr-actions .btn { flex:1 1 140px; justify-content:center; }
    .sr-empty { text-align:center; padding:40px 16px; color:var(--text-3); }
    .sr-tabs { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:14px; }
</style>
<div class="page-content">

    <div class="stats-grid">
        <div class="stat-card"><div class="stat-icon">🚨</div><div class="stat-value danger" id="sc-urgent">0</div><div class="stat-label">Urgent &amp; open</div></div>
        <div class="stat-card"><div class="stat-icon">🆕</div><div class="stat-value" id="sc-new">0</div><div class="stat-label">New (not yet seen)</div></div>
        <div class="stat-card"><div class="stat-icon">📋</div><div class="stat-value teal" id="sc-open">0</div><div class="stat-label">Open reports</div></div>
    </div>

    <div class="card">
        <div class="card-body">
            <label class="form-label" for="sr-staff">Your name (recorded when you acknowledge or resolve)</label>
            <input id="sr-staff" type="text" maxlength="100" placeholder="Nurse or doctor name" autocomplete="name">
        </div>
    </div>

    <div class="sr-tabs">
        <button class="btn btn-primary btn-sm" id="tab-open" type="button">Open reports</button>
        <button class="btn btn-secondary btn-sm" id="tab-resolved" type="button">Resolved</button>
        <span style="font-size:12px;color:var(--text-3)" id="sr-updated">Loading…</span>
    </div>

    <div id="sr-list"></div>
</div>

<script>
(function () {
    var API = '/medbot/api/symptoms.php';
    var view = 'open';
    var seen = null;            // ids already shown (null until first load)
    var lastSig = '';
    var $ = function (id) { return document.getElementById(id); };
    var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };

    var URG = { urgent: ['🔴 URGENT', 'badge-danger'], attention: ['🟠 Needs attention', 'badge-warning'], routine: ['🟢 Routine', 'badge-safe'] };
    var ST  = { 'new': ['New', 'badge-info'], acknowledged: ['Acknowledged', 'badge-warning'], resolved: ['Resolved', 'badge-safe'] };
    var DUR = { just_now: 'just now', today: 'since today', few_days: '2–3 days', week_plus: 'a week or more' };
    var LANG = { 'en-US': 'English', 'fr-FR': 'Français', 'sw-KE': 'Kiswahili', 'rw-RW': 'Kinyarwanda' };

    try { $('sr-staff').value = localStorage.getItem('medbot_staff') || ''; } catch (e) {}
    $('sr-staff').addEventListener('input', function () { try { localStorage.setItem('medbot_staff', this.value); } catch (e) {} });

    function ago(m) {
        if (m == null) return '';
        if (m < 1) return 'just now';
        if (m < 60) return m + ' min ago';
        if (m < 1440) return Math.floor(m / 60) + ' h ago';
        return Math.floor(m / 1440) + ' d ago';
    }

    function beep() {
        try {
            var C = window.AudioContext || window.webkitAudioContext;
            var ctx = new C(), o = ctx.createOscillator(), g = ctx.createGain();
            o.connect(g); g.connect(ctx.destination);
            o.frequency.value = 880; g.gain.value = 0.08;
            o.start(); o.stop(ctx.currentTime + 0.25);
        } catch (e) {}
        try { if (navigator.vibrate) navigator.vibrate([200, 100, 200]); } catch (e) {}
    }

    function card(r) {
        var u = URG[r.urgency] || URG.routine, s = ST[r.status] || ST['new'];
        var flags = (r.flags || []).map(function (f) { return '<span class="badge badge-gray">' + esc(f) + '</span>'; }).join(' ');
        var meta = [];
        if (r.pain_level != null) meta.push('Pain ' + r.pain_level + '/10');
        if (r.duration) meta.push('Duration: ' + esc(DUR[r.duration] || r.duration));
        meta.push(r.source === 'voice' ? '🎙️ spoken' : r.source === 'emergency' ? '🚨 emergency button' : '⌨️ typed');
        if (r.lang) meta.push(esc(LANG[r.lang] || r.lang));
        var html = '<div class="card sr-card ' + esc(r.urgency) + '" data-id="' + r.id + '">' +
            '<div class="card-header"><div class="card-title">' + esc(r.patient_name) + (r.room_number ? ' <span style="color:var(--text-3);font-weight:400">· Room ' + esc(r.room_number) + '</span>' : '') + '</div>' +
            '<div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap"><span class="badge ' + u[1] + '">' + u[0] + '</span><span class="badge ' + s[1] + '">' + s[0] + '</span><span style="font-size:12px;color:var(--text-3)">' + esc(ago(r.age_min)) + '</span></div></div>' +
            '<div class="card-body"><blockquote class="sr-quote">' + esc(r.transcript) + '</blockquote>' +
            '<div class="sr-meta">' + meta.join(' · ') + '</div>' +
            (flags ? '<div class="sr-meta">Warning words: ' + flags + '</div>' : '');
        if (r.staff_reply) html += '<div class="sr-reply"><strong>' + esc(r.handled_by || 'Staff') + ':</strong> ' + esc(r.staff_reply) + '</div>';
        else if (r.handled_by) html += '<div class="sr-meta">' + esc(ST[r.status][0]) + ' by ' + esc(r.handled_by) + '</div>';
        if (r.status !== 'resolved') {
            html += '<textarea class="sr-note" rows="2" maxlength="1000" placeholder="Note or reply for the patient (optional)" style="margin-top:8px"></textarea>' +
                '<div class="sr-actions">' +
                (r.status === 'new' ? '<button type="button" class="btn btn-secondary" data-act="acknowledge">👀 Acknowledge</button>' : '') +
                '<button type="button" class="btn btn-teal" data-act="resolve">✅ Resolve</button></div>';
        }
        return html + '</div></div>';
    }

    async function load() {
        try {
            var res = await fetch(API + '?action=list&view=' + view, { cache: 'no-store' });
            var data = await res.json();
            if (!res.ok || data.error) throw new Error(data.message || 'HTTP ' + res.status);

            $('sc-urgent').textContent = data.counts.urgent_open;
            $('sc-new').textContent = data.counts['new'];
            $('sc-open').textContent = data.counts.open;
            $('sr-updated').textContent = 'Updated ' + new Date().toLocaleTimeString();

            var fresh = data.reports.filter(function (r) { return seen && !seen.has(r.id) && r.status === 'new'; });
            if (view === 'open') {
                if (seen) {
                    fresh.forEach(function (r) {
                        if (typeof showToast === 'function') showToast((r.urgency === 'urgent' ? '🚨 URGENT: ' : '🩺 New report: ') + r.patient_name, r.urgency === 'urgent' ? 'warning' : 'info');
                    });
                    if (fresh.some(function (r) { return r.urgency === 'urgent'; })) beep();
                }
                seen = new Set(data.reports.map(function (r) { return r.id; }));
            }

            var sig = JSON.stringify(data.reports.map(function (r) { return [r.id, r.status, r.staff_reply, r.age_min]; })) + view;
            var ae = document.activeElement;
            var typing = ae && $('sr-list').contains(ae) && (ae.tagName === 'TEXTAREA' || ae.tagName === 'INPUT');
            if (sig !== lastSig && !typing) {
                lastSig = sig;
                $('sr-list').innerHTML = data.reports.length
                    ? data.reports.map(card).join('')
                    : '<div class="sr-empty">' + (view === 'open' ? '🎉 No open reports.' : 'No resolved reports yet.') + '</div>';
            }
        } catch (e) {
            $('sr-updated').textContent = 'Could not load: ' + e.message;
        }
    }

    $('sr-list').addEventListener('click', async function (e) {
        var b = e.target.closest('[data-act]');
        if (!b) return;
        var name = $('sr-staff').value.trim();
        if (!name) { if (typeof showToast === 'function') showToast('Enter your name first', 'warning'); $('sr-staff').focus(); return; }
        var cardEl = b.closest('.sr-card');
        var note = cardEl.querySelector('.sr-note');
        b.disabled = true;
        try {
            var res = await fetch(API, {
                method: 'POST', cache: 'no-store', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'update', id: parseInt(cardEl.dataset.id, 10), decision: b.dataset.act, handled_by: name, reply: note ? note.value : '' })
            });
            var data = await res.json();
            if (!res.ok || data.error) throw new Error(data.message || 'HTTP ' + res.status);
            if (typeof showToast === 'function') showToast(b.dataset.act === 'resolve' ? '✅ Report resolved' : '👀 Report acknowledged', 'success');
        } catch (err) {
            if (typeof showToast === 'function') showToast(err.message, 'error');
        }
        lastSig = '';
        load();
    });

    function setView(v) {
        view = v; lastSig = '';
        $('tab-open').className = 'btn btn-sm ' + (v === 'open' ? 'btn-primary' : 'btn-secondary');
        $('tab-resolved').className = 'btn btn-sm ' + (v === 'resolved' ? 'btn-primary' : 'btn-secondary');
        load();
    }
    $('tab-open').addEventListener('click', function () { setView('open'); });
    $('tab-resolved').addEventListener('click', function () { setView('resolved'); });

    load();
    setInterval(load, 8000);
})();
</script>
<?php renderFoot(); ?>
