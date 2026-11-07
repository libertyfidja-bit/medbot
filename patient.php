<?php
require_once __DIR__ . '/auth.php';
$user = auth_user();
if (!$user || $user['role'] !== 'patient') auth_redirect('login.php');
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MedBot — My health</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/medbot/assets/style.css">
<style>
body{background:var(--bg-0)}
.pt-wrap{max-width:560px;margin:0 auto;padding:12px 14px 40px}
.pt-top{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 0 14px}
.pt-top .who{font-size:13px;color:var(--text-2)}
.pt-top .who strong{display:block;font-size:17px;color:var(--text-1)}
.pt-links{display:flex;gap:8px;flex-wrap:wrap;align-items:center;font-size:12.5px}
.pt-links a{color:var(--accent);text-decoration:none}
.pt-lang{display:flex;gap:4px}
.pt-lang button{background:var(--bg-3);border:1px solid var(--border-grey);color:var(--text-2);border-radius:8px;padding:5px 9px;font:inherit;font-size:12px;cursor:pointer}
.pt-lang button.on{background:rgba(124,109,255,.2);border-color:var(--border-accent);color:var(--accent)}
.pt-big{display:flex;align-items:center;justify-content:center;gap:10px;width:100%;min-height:68px;border:none;border-radius:18px;font:inherit;font-size:18px;font-weight:700;cursor:pointer;margin-bottom:12px;padding:14px 16px;text-align:center}
.pt-voice{background:linear-gradient(135deg,var(--accent),var(--teal));color:#0a0a0f}
.pt-sos{background:rgba(255,77,109,.15);border:2px solid var(--danger);color:var(--danger)}
.pt-sos:active,.pt-voice:active{transform:scale(.98)}
.pt-card{background:var(--bg-card);border:1px solid var(--border-grey);border-radius:16px;margin-bottom:14px;overflow:hidden}
.pt-card h2{font-size:15px;margin:0;padding:14px 16px;border-bottom:1px solid var(--border);color:var(--text-1)}
.pt-empty{padding:18px 16px;color:var(--text-3);font-size:14px}
.pt-dose{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 16px;border-bottom:1px solid var(--border)}
.pt-dose:last-child{border-bottom:0}
.pt-dose .t{font-family:var(--mono);font-size:13px;color:var(--text-3)}
.pt-dose .n{font-size:16px;font-weight:600;color:var(--text-1)}
.pt-dose .d{font-size:13px;color:var(--text-2)}
.pt-took{min-height:48px;padding:10px 14px;border:none;border-radius:12px;font:inherit;font-weight:700;font-size:14px;background:linear-gradient(135deg,#00d4aa,#00b894);color:#0a0a0f;cursor:pointer;flex-shrink:0}
.pt-next{margin:12px 16px 0;padding:10px 12px;border-radius:12px;background:rgba(124,109,255,.12);border:1px solid var(--border-accent);color:var(--text-1);font-size:14px}
.pt-msg{padding:14px 16px;border-bottom:1px solid var(--border);font-size:14.5px;color:var(--text-1);line-height:1.5}
.pt-msg:last-child{border-bottom:0}
.pt-msg .by{font-size:12px;color:var(--text-3);margin-top:4px}
.pt-rep{padding:12px 16px;border-bottom:1px solid var(--border);font-size:14px;color:var(--text-2)}
.pt-rep:last-child{border-bottom:0}
.pt-rep .row{display:flex;justify-content:space-between;gap:8px;margin-top:6px;font-size:12px;color:var(--text-3);align-items:center}
.pt-err{margin:10px 0;padding:10px 12px;border-radius:10px;background:rgba(255,77,109,.1);border:1px solid rgba(255,77,109,.35);color:var(--danger);font-size:13.5px}
.pt-toast{position:fixed;left:12px;right:12px;bottom:20px;z-index:500;background:var(--bg-2);border:1px solid var(--border-accent);border-radius:12px;padding:14px 16px;color:var(--text-1);font-size:14.5px;box-shadow:var(--shadow-lg);display:none}
</style>
<script>window.MEDBOT_ROLE = "patient"; window.MEDBOT_USER = <?= json_encode($user['full_name'], $jsonFlags) ?>;</script>
</head>
<body>
<div class="pt-wrap">

    <div class="pt-top">
        <div class="who"><span data-i="hello"></span><strong id="pt-name"></strong></div>
        <div style="display:flex;flex-direction:column;gap:8px;align-items:flex-end">
            <div class="pt-lang" role="group" aria-label="Language">
                <button type="button" data-ui="rw">Kinyarwanda</button>
                <button type="button" data-ui="en">English</button>
                <button type="button" data-ui="fr">Français</button>
            </div>
            <div class="pt-links"><a href="change_password.php" data-i="password"></a> · <a href="logout.php" data-i="signOut"></a></div>
        </div>
    </div>

    <button type="button" class="pt-big pt-voice" id="btn-voice" data-i="tell"></button>
    <button type="button" class="pt-big pt-sos" id="btn-sos" data-i="call"></button>

    <div id="pt-error" class="pt-err" hidden></div>

    <div class="pt-card">
        <h2 data-i="medsTitle"></h2>
        <div id="pt-next" class="pt-next" hidden></div>
        <div id="pt-doses"></div>
    </div>

    <div class="pt-card">
        <h2 data-i="msgTitle"></h2>
        <div id="pt-msgs"></div>
    </div>

    <div class="pt-card">
        <h2 data-i="repTitle"></h2>
        <div id="pt-reps"></div>
    </div>
</div>
<div class="pt-toast" id="pt-toast" role="status" aria-live="polite"></div>

<script src="/medbot/assets/voice.js"></script>
<script>
(function () {
    'use strict';
    var API = '/medbot/api/me.php', API_SYM = '/medbot/api/symptoms.php';
    var $ = function (id) { return document.getElementById(id); };
    var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };

    // Interface texts. The Kinyarwanda block is a DRAFT: please have a Kinyarwanda speaker check it.
    var T = {
        en: { hello: 'Hello', signOut: 'Sign out', password: 'Change password',
            tell: '🎙️ Tell the care team how you feel', call: '🚨 Call a nurse now', confirmCall: 'Alert the nurses now?', sosSent: '🚨 The nurses have been alerted.',
            medsTitle: '💊 My medicines today', noMeds: 'No medicines are planned for today.', took: '✅ I took it', thanks: 'Thank you. It was recorded.',
            pending: 'Not taken yet', taken: 'Taken ✓', dispensed: 'Brought by the robot. Please take it.', missed: 'Not taken', skipped: 'Skipped',
            next: 'Next medicine', at: 'at', msgTitle: '💬 Messages from the care team', noMsgs: 'No messages yet.', repTitle: '🩺 What I told the care team', noReps: 'You have not sent anything yet.',
            sNew: 'Sent', sAck: 'Seen by staff', sDone: 'Closed', now: 'just now', m: ' min ago', h: ' h ago', d: ' d ago', loadFail: 'Could not load your information. Please try again.', by: 'From' },
        fr: { hello: 'Bonjour', signOut: 'Se déconnecter', password: 'Changer le mot de passe',
            tell: '🎙️ Dire à l’équipe comment je me sens', call: '🚨 Appeler une infirmière', confirmCall: 'Alerter les infirmières maintenant ?', sosSent: '🚨 Les infirmières ont été alertées.',
            medsTitle: '💊 Mes médicaments aujourd’hui', noMeds: 'Aucun médicament prévu aujourd’hui.', took: '✅ Je l’ai pris', thanks: 'Merci. C’est enregistré.',
            pending: 'Pas encore pris', taken: 'Pris ✓', dispensed: 'Apporté par le robot. Prenez-le.', missed: 'Non pris', skipped: 'Ignoré',
            next: 'Prochain médicament', at: 'à', msgTitle: '💬 Messages de l’équipe soignante', noMsgs: 'Aucun message pour le moment.', repTitle: '🩺 Ce que j’ai dit à l’équipe', noReps: 'Vous n’avez encore rien envoyé.',
            sNew: 'Envoyé', sAck: 'Vu par le personnel', sDone: 'Clôturé', now: 'à l’instant', m: ' min', h: ' h', d: ' j', loadFail: 'Impossible de charger vos informations. Réessayez.', by: 'De' },
        rw: { hello: 'Muraho', signOut: 'Sohoka', password: 'Hindura ijambo ry’ibanga',
            tell: '🎙️ Bwira abaganga uko wumva umeze', call: '🚨 Hamagara umuforomo ubu', confirmCall: 'Menyesha abaforomo ubu?', sosSent: '🚨 Abaforomo bamenyeshejwe.',
            medsTitle: '💊 Imiti yanjye uyu munsi', noMeds: 'Nta miti iteganyijwe uyu munsi.', took: '✅ Nayinyweye', thanks: 'Murakoze. Byanditswe.',
            pending: 'Ntiranywa', taken: 'Yanyowe ✓', dispensed: 'Yashyikirijwe — yinywe.', missed: 'Ntiyanyowe', skipped: 'Ntiyanyowe',
            next: 'Umuti ukurikira', at: 'saa', msgTitle: '💬 Ubutumwa buturutse ku baganga', noMsgs: 'Nta butumwa buraboneka.', repTitle: '🩺 Ibyo nabwiye abaganga', noReps: 'Nta kintu urohereza.',
            sNew: 'Byoherejwe', sAck: 'Byabonywe n’abaganga', sDone: 'Byarangiye', now: 'ubu nyine', m: ' min ishize', h: ' h ishize', d: ' iminsi ishize', loadFail: 'Amakuru yawe ntiyabonetse. Ongera ugerageze.', by: 'Kuva kuri' }
    };
    var VOICE_LANG = { rw: 'rw-RW', en: 'en-US', fr: 'fr-FR' };
    var ui = 'en';
    try { ui = localStorage.getItem('medbot_ui') || 'rw'; } catch (e) { ui = 'rw'; }
    if (!T[ui]) ui = 'en';
    var data = null;

    function t(k) { return (T[ui] && T[ui][k] != null) ? T[ui][k] : T.en[k]; }
    function ago(min) {
        if (min < 1) return t('now');
        if (min < 60) return min + t('m');
        if (min < 1440) return Math.floor(min / 60) + t('h');
        return Math.floor(min / 1440) + t('d');
    }
    function toast(msg) {
        var el = $('pt-toast'); el.textContent = msg; el.style.display = 'block';
        clearTimeout(toast.t); toast.t = setTimeout(function () { el.style.display = 'none'; }, 3500);
    }

    function applyLang() {
        document.documentElement.lang = ui;
        document.querySelectorAll('[data-i]').forEach(function (el) { el.textContent = t(el.dataset.i); });
        document.querySelectorAll('[data-ui]').forEach(function (b) { b.classList.toggle('on', b.dataset.ui === ui); });
        if (typeof voiceBot !== 'undefined' && voiceBot.setLang) voiceBot.setLang(VOICE_LANG[ui]);
        render();
    }

    function render() {
        if (!data) return;
        $('pt-name').textContent = data.name || '';
        var doses = data.schedule || [];
        var box = $('pt-doses'), next = $('pt-next');
        if (!doses.length) { box.innerHTML = '<div class="pt-empty">' + esc(t('noMeds')) + '</div>'; next.hidden = true; }
        else {
            box.innerHTML = doses.map(function (d) {
                var canTake = d.status === 'pending' || d.status === 'dispensed';
                var statusKey = d.status === 'pending' ? 'pending' : d.status === 'taken' ? 'taken' : d.status === 'dispensed' ? 'dispensed' : d.status === 'skipped' ? 'skipped' : 'missed';
                return '<div class="pt-dose"><div><div class="t">' + esc(String(d.scheduled_time).slice(0, 5)) + '</div>' +
                    '<div class="n">' + esc(d.medication_name) + ' · ' + esc(parseFloat(d.dosage_mg)) + ' mg</div>' +
                    '<div class="d">' + esc(t(statusKey)) + (d.instructions ? ' — ' + esc(d.instructions) : '') + '</div></div>' +
                    (canTake ? '<button type="button" class="pt-took" data-dose="' + d.id + '">' + esc(t('took')) + '</button>' : '') + '</div>';
            }).join('');
            var pend = doses.filter(function (d) { return d.status === 'pending' || d.status === 'dispensed'; })[0];
            if (pend) { next.hidden = false; next.textContent = t('next') + ': ' + pend.medication_name + ' ' + t('at') + ' ' + String(pend.scheduled_time).slice(0, 5); }
            else next.hidden = true;
        }

        var reps = data.reports || [];
        var replied = reps.filter(function (r) { return r.staff_reply; });
        $('pt-msgs').innerHTML = replied.length ? replied.map(function (r) {
            return '<div class="pt-msg">' + esc(r.staff_reply) + '<div class="by">' + esc(t('by')) + ' ' + esc(r.handled_by || '') + '</div></div>';
        }).join('') : '<div class="pt-empty">' + esc(t('noMsgs')) + '</div>';

        $('pt-reps').innerHTML = reps.length ? reps.map(function (r) {
            var s = r.status === 'new' ? 'sNew' : r.status === 'acknowledged' ? 'sAck' : 'sDone';
            return '<div class="pt-rep">' + esc(r.transcript) + '<div class="row"><span>' + esc(ago(r.age_min)) + '</span><span class="badge ' + (r.status === 'resolved' ? 'badge-safe' : r.status === 'acknowledged' ? 'badge-warning' : 'badge-info') + '">' + esc(t(s)) + '</span></div></div>';
        }).join('') : '<div class="pt-empty">' + esc(t('noReps')) + '</div>';
    }

    async function load() {
        try {
            var res = await fetch(API + '?action=overview', { cache: 'no-store' });
            var j = await res.json();
            if (res.status === 401) { location.href = 'login.php'; return; }
            if (!res.ok || j.error) throw new Error(j.message || 'HTTP ' + res.status);
            data = j;
            $('pt-error').hidden = true;
            render();
        } catch (e) {
            $('pt-error').hidden = false;
            $('pt-error').textContent = t('loadFail') + ' (' + e.message + ')';
        }
    }

    $('pt-doses').addEventListener('click', async function (e) {
        var b = e.target.closest('[data-dose]');
        if (!b) return;
        b.disabled = true;
        try {
            var res = await fetch(API, { method: 'POST', cache: 'no-store', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'taken', dose_id: parseInt(b.dataset.dose, 10) }) });
            var j = await res.json();
            if (!res.ok || j.error) throw new Error(j.message || 'HTTP ' + res.status);
            toast(t('thanks'));
        } catch (err) { toast(err.message); }
        load();
    });

    $('btn-voice').addEventListener('click', function () { if (typeof voiceBot !== 'undefined') voiceBot.open(); });

    $('btn-sos').addEventListener('click', async function () {
        if (!confirm(t('confirmCall'))) return;
        var b = this; b.disabled = true;
        try {
            var res = await fetch(API_SYM, { method: 'POST', cache: 'no-store', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'create', source: 'emergency', transcript: 'EMERGENCY: patient pressed the “Call a nurse now” button.', lang: VOICE_LANG[ui] }) });
            var j = await res.json();
            if (!res.ok || j.error) throw new Error(j.message || 'HTTP ' + res.status);
            toast(t('sosSent'));
            load();
        } catch (err) { toast(err.message); }
        b.disabled = false;
    });

    document.querySelectorAll('[data-ui]').forEach(function (b) {
        b.addEventListener('click', function () {
            ui = b.dataset.ui;
            try { localStorage.setItem('medbot_ui', ui); } catch (e) { /* ignore */ }
            applyLang();
        });
    });

    applyLang();
    load();
    setInterval(load, 30000);
})();
</script>
</body>
</html>
