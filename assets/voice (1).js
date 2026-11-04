// MedBot Voice — symptom reporting + medicine questions (Web Speech API)
//
//  * Words appear LIVE while the person speaks and stay on screen.
//  * It keeps listening through pauses.
//  * "Report symptoms" saves the report for nurses and doctors (api/symptoms.php).
//  * The panel speaks English, French and KINYARWANDA. The interface follows the language
//    chosen in "Language spoken" (Kiswahili keeps the English interface).
//  * Kinyarwanda texts below are a DRAFT: have a Kinyarwanda-speaking nurse review them.
//  * If the browser cannot recognise Kinyarwanda, patients can type in the box instead.

const voiceBot = (() => {
    'use strict';

    const SCRIPT_SRC = document.currentScript ? document.currentScript.src : '';
    const BASE = SCRIPT_SRC ? new URL('../', SCRIPT_SRC).pathname : '/medbot/';
    const API_SYMPTOMS = BASE + 'api/symptoms.php';

    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;

    const LANGS = [
        ['rw-RW', 'Kinyarwanda'],
        ['en-US', 'English'],
        ['fr-FR', 'Français'],
        ['sw-KE', 'Kiswahili'],
    ];

    // ── Interface texts ──
    const S = {
        en: {
            title: "🎙️ MedBot Voice", tabSymptoms: "🩺 Report symptoms", tabAsk: "💊 Ask about medicines",
            langLabel: "Language spoken", rwHint: "",
            idleSymptoms: "Tap the microphone and describe how you feel.", idleAsk: "Tap the microphone and ask your question.",
            listeningSymptoms: "Listening… describe how you feel.", listeningAsk: "Listening… ask your question.",
            who: "Who is speaking?", choosePatient: "— Choose your name —", loadingPatients: "Loading…",
            patientsFailed: "Could not load names — you can still call a nurse",
            saidLabel: "What you said (you can edit it)", saidPlaceholder: "Your words appear here as you speak. You can also type.",
            painLabel: "Pain level", painPlaceholder: "Pain? (optional)", painNone: "0 – none", painWorst: "10 – worst",
            durLabel: "Duration", durPlaceholder: "How long? (optional)", durJustNow: "Just now", durToday: "Since today", durFewDays: "2–3 days", durWeek: "A week or more",
            send: "📨 Send to care team", clear: "Clear", emergency: "🚨 Call a nurse now", emergencyConfirm: "Alert the nurses now?",
            sending: "Sending…", sentOk: "✅ Sent to the care team. Someone will respond soon.", sentUrgent: "✅ Sent and marked URGENT. A nurse has been alerted.",
            needPatient: "Please choose who is speaking.", needText: "Please say or type a few words first.",
            note: "MedBot does not diagnose. A nurse or doctor reads every report. In an emergency, call for help straight away.",
            heardLabel: "You said", answerLabel: "MedBot answer", askHint: "Tap the microphone and ask, for example: “When is my next dose?”", thinking: "🤖 Thinking…",
            errBlocked: "The microphone is blocked. Tap the padlock in the address bar, allow the microphone, then try again.",
            errNoMic: "No microphone was found on this device.", errNetwork: "Voice recognition needs an internet connection.",
            errLang: "This browser cannot recognise that language. Pick another language or type below.",
            errGeneric: "Voice error ({code}). You can type instead.", unsupported: "Voice typing is not supported in this browser. Use Chrome or Edge, or type below.",
            noSpeech: "I did not hear anything. Tap the microphone to try again.", startFail: "Could not start the microphone. Please try again.",
            sendFail: "Please try again or call a nurse.", toastUrgent: "🚨 Urgent report sent to the care team", toastOk: "✅ Report sent to the care team",
            closeAria: "Close", micAria: "Start or stop the microphone",
        },
        fr: {
            title: "🎙️ Voix MedBot", tabSymptoms: "🩺 Signaler des symptômes", tabAsk: "💊 Questions sur les médicaments",
            langLabel: "Langue parlée", rwHint: "",
            idleSymptoms: "Appuyez sur le micro et décrivez comment vous vous sentez.", idleAsk: "Appuyez sur le micro et posez votre question.",
            listeningSymptoms: "J’écoute… décrivez comment vous vous sentez.", listeningAsk: "J’écoute… posez votre question.",
            who: "Qui parle ?", choosePatient: "— Choisissez votre nom —", loadingPatients: "Chargement…",
            patientsFailed: "Impossible de charger les noms — vous pouvez quand même appeler une infirmière",
            saidLabel: "Ce que vous avez dit (vous pouvez le corriger)", saidPlaceholder: "Vos mots apparaissent ici pendant que vous parlez. Vous pouvez aussi écrire.",
            painLabel: "Douleur", painPlaceholder: "Douleur ? (facultatif)", painNone: "0 – aucune", painWorst: "10 – la pire",
            durLabel: "Durée", durPlaceholder: "Depuis combien de temps ? (facultatif)", durJustNow: "À l’instant", durToday: "Depuis aujourd’hui", durFewDays: "2–3 jours", durWeek: "Une semaine ou plus",
            send: "📨 Envoyer à l’équipe soignante", clear: "Effacer", emergency: "🚨 Appeler une infirmière", emergencyConfirm: "Alerter les infirmières maintenant ?",
            sending: "Envoi…", sentOk: "✅ Envoyé à l’équipe soignante. Quelqu’un vous répondra bientôt.", sentUrgent: "✅ Envoyé et marqué URGENT. Une infirmière a été alertée.",
            needPatient: "Veuillez choisir qui parle.", needText: "Veuillez dire ou écrire quelques mots d’abord.",
            note: "MedBot ne pose pas de diagnostic. Une infirmière ou un médecin lit chaque signalement. En cas d’urgence, appelez de l’aide immédiatement.",
            heardLabel: "Vous avez dit", answerLabel: "Réponse de MedBot", askHint: "Appuyez sur le micro et demandez, par exemple : “When is my next dose?” (les réponses sont en anglais)", thinking: "🤖 Réflexion…",
            errBlocked: "Le micro est bloqué. Appuyez sur le cadenas dans la barre d’adresse, autorisez le micro, puis réessayez.",
            errNoMic: "Aucun micro détecté sur cet appareil.", errNetwork: "La reconnaissance vocale nécessite une connexion internet.",
            errLang: "Ce navigateur ne reconnaît pas cette langue. Choisissez une autre langue ou écrivez ci-dessous.",
            errGeneric: "Erreur vocale ({code}). Vous pouvez écrire à la place.", unsupported: "La saisie vocale n’est pas prise en charge sur ce navigateur. Utilisez Chrome ou Edge, ou écrivez ci-dessous.",
            noSpeech: "Je n’ai rien entendu. Appuyez sur le micro pour réessayer.", startFail: "Impossible de démarrer le micro. Réessayez.",
            sendFail: "Réessayez ou appelez une infirmière.", toastUrgent: "🚨 Signalement urgent envoyé à l’équipe soignante", toastOk: "✅ Signalement envoyé à l’équipe soignante",
            closeAria: "Fermer", micAria: "Démarrer ou arrêter le micro",
        },
        // DRAFT Kinyarwanda — please have a Kinyarwanda-speaking nurse or doctor check these.
        rw: {
            title: "🎙️ MedBot Ijwi", tabSymptoms: "🩺 Bwira ibimenyetso", tabAsk: "💊 Baza ku miti",
            langLabel: "Ururimi uvuga",
            rwHint: "Kumva Ikinyarwanda bishobora kudakora neza kuri telefoni zimwe. Niba amagambo atari yo, yandike mu kazu kari hepfo.",
            idleSymptoms: "Kanda mikoro maze uvuge uko wumva umeze.", idleAsk: "Kanda mikoro maze ubaze ikibazo cyawe.",
            listeningSymptoms: "Ndakumva… vuga uko wumva umeze.", listeningAsk: "Ndakumva… baza ikibazo cyawe.",
            who: "Ni nde uvuga?", choosePatient: "— Hitamo izina ryawe —", loadingPatients: "Biraza…",
            patientsFailed: "Amazina ntiyabonetse — ushobora guhamagara umuforomo",
            saidLabel: "Ibyo wavuze (ushobora kubikosora)", saidPlaceholder: "Amagambo yawe agaragara hano uko uvuga. Ushobora no kuyandika.",
            painLabel: "Ububabare", painPlaceholder: "Ububabare? (si ngombwa)", painNone: "0 – nta bubabare", painWorst: "10 – bukabije cyane",
            durLabel: "Igihe bimaze", durPlaceholder: "Igihe bimaze? (si ngombwa)", durJustNow: "Ubu nyine", durToday: "Kuva uyu munsi", durFewDays: "Iminsi 2–3", durWeek: "Icyumweru cyangwa kirenga",
            send: "📨 Ohereza ku baganga", clear: "Siba", emergency: "🚨 Hamagara umuforomo ubu", emergencyConfirm: "Menyesha abaforomo ubu?",
            sending: "Biroherezwa…", sentOk: "✅ Byoherejwe. Hari umuntu uragusubiza vuba.", sentUrgent: "✅ Byoherejwe nk’ibyihutirwa. Umuforomo yamenyeshejwe.",
            needPatient: "Hitamo izina ry’uri kuvuga.", needText: "Banza uvuge cyangwa wandike amagambo make.",
            note: "MedBot ntipima indwara. Umuforomo cyangwa umuganga asoma buri butumwa. Mu byihutirwa, saba ubufasha ako kanya.",
            heardLabel: "Wavuze", answerLabel: "Igisubizo cya MedBot", askHint: "Kanda mikoro maze ubaze, urugero: “When is my next dose?” (ibisubizo biri mu Cyongereza)", thinking: "🤖 Ndatekereza…",
            errBlocked: "Mikoro irafunze. Kanda ku ngufuri iri hejuru mu mwanya w’aderesi, wemere mikoro, maze ugerageze nanone.",
            errNoMic: "Nta mikoro yabonetse kuri iki gikoresho.", errNetwork: "Kumva ijwi bisaba murandasi (internet).",
            errLang: "Iyi porogaramu ntishobora kumva Ikinyarwanda kuri iki gikoresho. Andika ibimenyetso byawe hepfo, cyangwa uhitemo urundi rurimi.",
            errGeneric: "Ikibazo cy’ijwi ({code}). Ushobora kwandika.", unsupported: "Kuvuga ntibishoboka kuri iyi porogaramu. Koresha Chrome cyangwa Edge, cyangwa wandike hepfo.",
            noSpeech: "Sinumvise ijwi. Kanda mikoro ugerageze nanone.", startFail: "Mikoro ntiyashoboye gufungurwa. Ongera ugerageze.",
            sendFail: "Ongera ugerageze cyangwa uhamagare umuforomo.", toastUrgent: "🚨 Ubutumwa bwihutirwa bwoherejwe ku baganga", toastOk: "✅ Ubutumwa bwoherejwe ku baganga",
            closeAria: "Funga", micAria: "Fungura cyangwa ufunge mikoro",
        },
    };

    const $ = id => document.getElementById(id);
    const store = {
        get(k) { try { return localStorage.getItem(k) || ''; } catch (e) { return ''; } },
        set(k, v) { try { localStorage.setItem(k, v); } catch (e) { /* storage unavailable */ } },
    };
    const toast = (msg, type) => { if (typeof showToast === 'function') showToast(msg, type); };

    let built = false;
    let mode = 'symptoms';          // 'symptoms' | 'ask'
    // Kinyarwanda is the default for a Rwandan hospital; a patient's own choice is remembered.
    let lang = store.get('medbot_lang') || 'rw-RW';
    let recognition = null;
    let listening = false;
    let keepListening = false;
    let quietRestarts = 0;
    let statusKind = 'idle';
    let usedVoice = false;
    let askUsed = false;
    let patientState = 'loading';   // 'loading' | 'ready' | 'failed'
    let patientsLoaded = false;

    function ui() {
        const p = lang.split('-')[0];
        return S[p] ? p : 'en';
    }
    function t(key, code) {
        const s = (S[ui()][key] != null ? S[ui()][key] : S.en[key]);
        return String(s == null ? key : s).replace('{code}', code == null ? '' : code);
    }

    // ── Styles (injected so this file is self-contained) ──
    function injectStyles() {
        if ($('vp-styles')) return;
        const css = `
        #voice-bubble{display:none !important}
        .vp-wrap{position:fixed;inset:0;z-index:300;display:none;background:rgba(0,0,0,.6);align-items:flex-end;justify-content:center}
        .vp-wrap.open{display:flex}
        .vp-panel{width:100%;max-width:480px;max-height:92vh;overflow-y:auto;background:var(--bg-2);border:1px solid var(--border-accent);border-radius:16px 16px 0 0;padding:16px 16px calc(16px + env(safe-area-inset-bottom,0px));box-shadow:var(--shadow-lg);color:var(--text-2);font-size:13.5px;line-height:1.5}
        .vp-panel [hidden]{display:none !important}
        @media(min-width:601px){
            .vp-wrap{background:transparent;pointer-events:none;justify-content:flex-end;align-items:flex-end;padding:0 24px 92px 0}
            .vp-panel{pointer-events:auto;border-radius:14px;width:400px;max-height:calc(100vh - 120px)}
        }
        .vp-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
        .vp-title{font-size:15px;font-weight:700;color:var(--text-1)}
        .vp-x{background:none;border:none;color:var(--text-3);font-size:22px;line-height:1;cursor:pointer;padding:4px 8px}
        .vp-x:hover{color:var(--text-1)}
        .vp-tabs{display:flex;gap:6px;margin-bottom:12px}
        .vp-tab{flex:1;padding:9px 6px;border-radius:var(--radius-sm);border:1px solid var(--border-grey);background:var(--bg-3);color:var(--text-2);font:inherit;font-weight:600;font-size:12.5px;cursor:pointer}
        .vp-tab.active{background:rgba(124,109,255,.15);border-color:var(--border-accent);color:var(--accent)}
        .vp-label{display:block;font-size:11px;font-weight:600;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;margin:10px 0 4px}
        .vp-hint{margin-top:6px;font-size:12px;color:var(--warning)}
        .vp-panel select,.vp-panel textarea{font-size:16px}
        .vp-microw{display:flex;align-items:center;gap:12px;margin:12px 0 6px}
        .vp-mic{width:64px;height:64px;border-radius:50%;border:none;cursor:pointer;font-size:26px;flex-shrink:0;background:linear-gradient(135deg,var(--accent),var(--teal));box-shadow:0 6px 20px var(--accent-glow)}
        .vp-mic.on{background:var(--danger);animation:vpPulse 1.1s infinite}
        @keyframes vpPulse{0%,100%{box-shadow:0 0 0 0 rgba(255,77,109,.5)}50%{box-shadow:0 0 0 14px rgba(255,77,109,0)}}
        .vp-status{font-size:13px;color:var(--text-2)}
        .vp-status.live{color:var(--danger);font-weight:600}
        .vp-status.warn{color:var(--warning)}
        .vp-status.error{color:var(--danger)}
        .vp-interim{min-height:20px;font-style:italic;color:var(--text-3);margin-top:4px;word-break:break-word}
        .vp-two{display:grid;grid-template-columns:1fr 1fr;gap:10px}
        .vp-actions{display:flex;gap:8px;margin-top:12px}
        .vp-actions .btn:first-child{flex:1;justify-content:center}
        .vp-emergency{width:100%;justify-content:center;margin-top:10px;padding:11px}
        .vp-result{margin-top:10px;font-size:13px;min-height:18px}
        .vp-result.ok{color:var(--teal)} .vp-result.warn{color:var(--warning)} .vp-result.error{color:var(--danger)}
        .vp-note{margin-top:12px;font-size:11.5px;color:var(--text-3)}
        .vp-heard{background:var(--bg-0);border:1px solid var(--border);border-radius:var(--radius-sm);padding:10px 12px;margin-top:8px;color:var(--text-1);min-height:42px;word-break:break-word}
        .vp-answer{background:rgba(124,109,255,.08);border:1px solid var(--border-accent);border-radius:var(--radius-sm);padding:10px 12px;margin-top:8px;color:var(--text-1);min-height:42px}
        `;
        const el = document.createElement('style');
        el.id = 'vp-styles';
        el.textContent = css;
        document.head.appendChild(el);
    }

    // ── Panel ──
    function buildPanel() {
        if (built) return;
        built = true;
        injectStyles();

        const wrap = document.createElement('div');
        wrap.className = 'vp-wrap';
        wrap.id = 'vp-wrap';
        wrap.innerHTML = `
        <div class="vp-panel" role="dialog" aria-modal="true" aria-labelledby="vp-title">
            <div class="vp-head">
                <div class="vp-title" id="vp-title" data-i="title"></div>
                <button type="button" class="vp-x" id="vp-close" data-aria="closeAria">✕</button>
            </div>
            <div class="vp-tabs">
                <button type="button" class="vp-tab active" data-mode="symptoms" data-i="tabSymptoms"></button>
                <button type="button" class="vp-tab" data-mode="ask" data-i="tabAsk"></button>
            </div>

            <label class="vp-label" for="vp-lang" data-i="langLabel"></label>
            <select id="vp-lang"></select>
            <div class="vp-hint" id="vp-hint" hidden></div>

            <div class="vp-microw">
                <button type="button" class="vp-mic" id="vp-mic" data-aria="micAria">🎙️</button>
                <div class="vp-status" id="vp-status" role="status" aria-live="polite"></div>
            </div>

            <div id="vp-sec-symptoms">
                <label class="vp-label" for="vp-patient" data-i="who"></label>
                <select id="vp-patient"><option value=""></option></select>

                <label class="vp-label" for="vp-text" data-i="saidLabel"></label>
                <textarea id="vp-text" rows="5" data-ph="saidPlaceholder"></textarea>
                <div class="vp-interim" id="vp-interim" aria-hidden="true"></div>

                <div class="vp-two">
                    <div>
                        <label class="vp-label" for="vp-pain" data-i="painLabel"></label>
                        <select id="vp-pain"></select>
                    </div>
                    <div>
                        <label class="vp-label" for="vp-duration" data-i="durLabel"></label>
                        <select id="vp-duration"></select>
                    </div>
                </div>

                <div class="vp-actions">
                    <button type="button" class="btn btn-teal" id="vp-send" data-i="send" disabled></button>
                    <button type="button" class="btn btn-secondary" id="vp-clear" data-i="clear"></button>
                </div>
                <button type="button" class="btn btn-danger vp-emergency" id="vp-emergency" data-i="emergency"></button>
                <div class="vp-result" id="vp-result" role="status" aria-live="polite"></div>
                <div class="vp-note" data-i="note"></div>
            </div>

            <div id="vp-sec-ask" hidden>
                <label class="vp-label" data-i="heardLabel"></label>
                <div class="vp-heard" id="vp-heard">—</div>
                <label class="vp-label" data-i="answerLabel"></label>
                <div class="vp-answer" id="vp-answer"></div>
            </div>
        </div>`;
        document.body.appendChild(wrap);

        const langSel = $('vp-lang');
        LANGS.forEach(([code, name]) => langSel.add(new Option(name, code)));
        if (!LANGS.some(l => l[0] === lang)) lang = 'rw-RW';
        langSel.value = lang;

        // Events
        $('vp-close').addEventListener('click', closePanel);
        wrap.addEventListener('click', e => { if (e.target === wrap && window.innerWidth <= 600) closePanel(); });
        document.addEventListener('keydown', e => { if (e.key === 'Escape' && wrap.classList.contains('open')) closePanel(); });
        wrap.querySelectorAll('.vp-tab').forEach(b => b.addEventListener('click', () => setMode(b.dataset.mode)));
        langSel.addEventListener('change', () => {
            lang = langSel.value;
            store.set('medbot_lang', lang);
            stopListening();
            setResult('', '');
            applyStrings();
        });
        $('vp-mic').addEventListener('click', () => (listening || keepListening) ? stopListening() : startListening());
        $('vp-text').addEventListener('input', updateSendState);
        $('vp-send').addEventListener('click', () => send('normal'));
        $('vp-emergency').addEventListener('click', () => send('emergency'));
        $('vp-clear').addEventListener('click', () => { $('vp-text').value = ''; $('vp-interim').textContent = ''; usedVoice = false; setResult('', ''); updateSendState(); });
        $('vp-patient').addEventListener('change', () => store.set('medbot_patient', $('vp-patient').value));

        applyStrings();
    }

    // Writes every visible text in the chosen interface language.
    function applyStrings() {
        const wrap = $('vp-wrap');
        if (!wrap) return;
        wrap.querySelectorAll('[data-i]').forEach(el => { el.textContent = t(el.dataset.i); });
        wrap.querySelectorAll('[data-ph]').forEach(el => { el.placeholder = t(el.dataset.ph); });
        wrap.querySelectorAll('[data-aria]').forEach(el => { el.setAttribute('aria-label', t(el.dataset.aria)); });

        // Select lists that hold translated labels (keep what was chosen)
        const painVal = $('vp-pain').value, durVal = $('vp-duration').value;
        const pain = $('vp-pain');
        pain.innerHTML = '';
        pain.add(new Option(t('painPlaceholder'), ''));
        for (let i = 0; i <= 10; i++) pain.add(new Option(i === 0 ? t('painNone') : i === 10 ? t('painWorst') : String(i), String(i)));
        pain.value = painVal;
        const dur = $('vp-duration');
        dur.innerHTML = '';
        [['', 'durPlaceholder'], ['just_now', 'durJustNow'], ['today', 'durToday'], ['few_days', 'durFewDays'], ['week_plus', 'durWeek']]
            .forEach(([v, k]) => dur.add(new Option(t(k), v)));
        dur.value = durVal;

        // Patient placeholder (names themselves are data and stay as they are)
        const first = $('vp-patient').options[0];
        if (first && first.value === '') {
            first.textContent = patientState === 'loading' ? t('loadingPatients') : patientState === 'failed' ? t('patientsFailed') : t('choosePatient');
        }

        const hint = $('vp-hint');
        const text = lang === 'rw-RW' ? t('rwHint') : '';
        hint.textContent = text;
        hint.hidden = !text;

        if (!askUsed) $('vp-answer').textContent = t('askHint');
        if (statusKind === 'idle' || statusKind === 'warn' || statusKind === 'error') setStatus(idleText(), 'idle');
    }

    function idleText() {
        if (!SR) return t('unsupported');
        return mode === 'symptoms' ? t('idleSymptoms') : t('idleAsk');
    }

    function setStatus(text, kind) {
        statusKind = kind;
        const el = $('vp-status');
        if (!el) return;
        el.textContent = text;
        el.className = 'vp-status ' + kind;
    }

    function setResult(text, kind) {
        const el = $('vp-result');
        if (!el) return;
        el.textContent = text;
        el.className = 'vp-result ' + kind;
    }

    function setMode(m) {
        mode = m;
        stopListening();
        document.querySelectorAll('.vp-tab').forEach(b => b.classList.toggle('active', b.dataset.mode === m));
        $('vp-sec-symptoms').hidden = m !== 'symptoms';
        $('vp-sec-ask').hidden = m !== 'ask';
        setStatus(idleText(), 'idle');
    }

    function setMicState(on) {
        const mic = $('vp-mic'), fab = $('voice-btn');
        if (mic) { mic.classList.toggle('on', on); mic.textContent = on ? '⏹' : '🎙️'; }
        if (fab) { fab.classList.toggle('listening', on); fab.textContent = on ? '🔴' : '🎙️'; }
    }

    function updateSendState() {
        const btn = $('vp-send');
        if (btn) btn.disabled = $('vp-text').value.trim().length < 3;
    }

    function showInterim(text) {
        if (mode === 'symptoms') {
            const el = $('vp-interim');
            if (el) el.textContent = text ? '… ' + text : '';
        } else if (text) {
            const el = $('vp-heard');
            if (el) el.textContent = text + ' …';
        }
    }

    async function loadPatients() {
        if (patientsLoaded) return;
        const sel = $('vp-patient');
        try {
            const res = await fetch(API_SYMPTOMS + '?action=patients', { cache: 'no-store' });
            const data = await res.json();
            if (!res.ok || data.error) throw new Error(data.message || 'failed');
            sel.innerHTML = '';
            sel.add(new Option('', ''));
            (data.patients || []).forEach(p => sel.add(new Option(p.name + (p.room_number ? ' — ' + p.room_number : ''), String(p.id))));
            patientState = 'ready';
            patientsLoaded = true;
            const saved = store.get('medbot_patient');
            if (saved && [...sel.options].some(o => o.value === saved)) sel.value = saved;
        } catch (e) {
            sel.innerHTML = '';
            sel.add(new Option('', ''));
            patientState = 'failed';
        }
        applyStrings();
    }

    function openPanel() {
        buildPanel();
        $('vp-wrap').classList.add('open');
        loadPatients();
        updateSendState();
    }

    function closePanel() {
        stopListening();
        const w = $('vp-wrap');
        if (w) w.classList.remove('open');
    }

    function togglePanel() {
        const w = $('vp-wrap');
        if (w && w.classList.contains('open')) closePanel(); else openPanel();
    }

    // ── Speech recognition ──
    function errorText(code) {
        switch (code) {
            case 'not-allowed':
            case 'service-not-allowed': return t('errBlocked');
            case 'audio-capture': return t('errNoMic');
            case 'network': return t('errNetwork');
            case 'language-not-supported': return t('errLang');
            default: return t('errGeneric', code);
        }
    }

    function startListening() {
        if (!SR) { setStatus(idleText(), 'warn'); return; }
        if (listening || recognition) return;   // previous session is still closing
        keepListening = true;
        quietRestarts = 0;
        beginSession();
    }

    function beginSession() {
        recognition = new SR();
        recognition.lang = lang;
        recognition.interimResults = true;   // show words while the person is still speaking
        recognition.continuous = false;      // restarted below, which is more reliable on phones
        recognition.maxAlternatives = 1;

        recognition.onstart = () => {
            listening = true;
            setMicState(true);
            setStatus(mode === 'symptoms' ? t('listeningSymptoms') : t('listeningAsk'), 'live');
        };

        recognition.onresult = e => {
            let interim = '', final = '';
            for (let i = e.resultIndex; i < e.results.length; i++) {
                const r = e.results[i];
                if (r.isFinal) final += r[0].transcript; else interim += r[0].transcript;
            }
            quietRestarts = 0;
            showInterim(interim);
            if (final.trim()) { showInterim(''); handleFinal(final.trim()); }
        };

        recognition.onerror = e => {
            if (e.error === 'no-speech') { quietRestarts++; return; }
            if (e.error === 'aborted') return;
            keepListening = false;
            setStatus(errorText(e.error), 'error');
        };

        recognition.onend = () => {
            listening = false;
            recognition = null;
            if (keepListening && mode === 'symptoms' && quietRestarts < 3) {
                setTimeout(() => { if (keepListening && !recognition) beginSession(); }, 250);
                return;
            }
            if (keepListening && quietRestarts >= 3) setStatus(t('noSpeech'), 'warn');
            keepListening = false;
            setMicState(false);
            showInterim('');
            if (statusKind === 'live') setStatus(idleText(), 'idle');
        };

        try { recognition.start(); }
        catch (err) { keepListening = false; recognition = null; setStatus(t('startFail'), 'error'); }
    }

    function stopListening() {
        keepListening = false;
        if (recognition) { try { recognition.stop(); } catch (e) { /* already stopped */ } }
        listening = false;
        setMicState(false);
        showInterim('');
        if (statusKind === 'live') setStatus(idleText(), 'idle');
    }

    function handleFinal(text) {
        if (mode === 'symptoms') {
            const ta = $('vp-text');
            ta.value = (ta.value.trim() ? ta.value.trim() + ' ' : '') + text;
            usedVoice = true;
            updateSendState();
            setResult('', '');
        } else {
            keepListening = false;
            askAnswer(text);
        }
    }

    // ── Sending a symptom report ──
    async function send(kind) {
        const emergency = kind === 'emergency';
        const patientId = $('vp-patient').value;
        let text = $('vp-text').value.trim();
        if (!emergency) {
            if (!patientId) { setResult(t('needPatient'), 'warn'); $('vp-patient').focus(); return; }
            if (text.length < 3) { setResult(t('needText'), 'warn'); return; }
        } else if (!text) {
            text = 'EMERGENCY: patient pressed the “Call a nurse now” button.';
        }
        if (emergency && !confirm(t('emergencyConfirm'))) return;

        stopListening();
        const sendBtn = $('vp-send'), emBtn = $('vp-emergency');
        sendBtn.disabled = emBtn.disabled = true;
        setResult(t('sending'), '');
        try {
            const res = await fetch(API_SYMPTOMS, {
                method: 'POST',
                cache: 'no-store',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'create',
                    patient_id: patientId || null,
                    transcript: text,
                    lang,
                    source: emergency ? 'emergency' : (usedVoice ? 'voice' : 'typed'),
                    pain_level: $('vp-pain').value,
                    duration: $('vp-duration').value,
                }),
            });
            let data = null;
            try { data = await res.json(); } catch (e) { /* not JSON */ }
            if (!res.ok || !data || data.error) throw new Error((data && data.message) || 'HTTP ' + res.status);

            const urgent = data.report && data.report.urgency === 'urgent';
            setResult(urgent ? t('sentUrgent') : t('sentOk'), 'ok');
            toast(urgent ? t('toastUrgent') : t('toastOk'), urgent ? 'warning' : 'success');
            $('vp-text').value = '';
            $('vp-pain').value = '';
            $('vp-duration').value = '';
            usedVoice = false;
        } catch (err) {
            setResult('❌ ' + err.message + ' — ' + t('sendFail'), 'error');
        } finally {
            emBtn.disabled = false;
            updateSendState();
        }
    }

    // ── Medicine questions (answers are in English for now) ──
    function speak(text) {
        if (!('speechSynthesis' in window)) return;
        const utter = new SpeechSynthesisUtterance(text);
        utter.rate = 0.95;
        utter.lang = 'en-US';
        const voices = speechSynthesis.getVoices();
        const v = voices.find(x => x.lang.toLowerCase().indexOf('en') === 0);
        if (v) utter.voice = v;
        speechSynthesis.speak(utter);
    }

    async function getAnswer(question) {
        const q = question.toLowerCase();
        try {
            const res = await fetch(BASE + 'api/schedule.php', { cache: 'no-store' });
            const data = await res.json();
            const schedule = data.schedule || [];
            const now = new Date();
            const nowMin = now.getHours() * 60 + now.getMinutes();

            if (/\b(take|taken|did i)\b/.test(q)) {
                const taken = schedule.filter(d => ['taken', 'dispensed'].includes(d.status));
                const pending = schedule.filter(d => d.status === 'pending');
                const missed = schedule.filter(d => d.status === 'missed');
                if (taken.length > 0) {
                    const names = [...new Set(taken.map(d => d.medication_name))].join(', ');
                    let r = `You have taken ${taken.length} dose${taken.length > 1 ? 's' : ''} today: ${names}.`;
                    if (pending.length > 0) r += ` You still have ${pending.length} dose${pending.length > 1 ? 's' : ''} pending.`;
                    if (missed.length > 0) r += ` Warning: ${missed.length} dose${missed.length > 1 ? 's were' : ' was'} missed!`;
                    return r;
                }
                return 'No doses have been taken or dispensed yet today.';
            }
            if (/next dose|\bwhen\b|next medication/.test(q)) {
                const pending = schedule.filter(d => d.status === 'pending');
                if (!pending.length) return 'No more pending doses scheduled for today. All done!';
                const next = pending[0];
                const parts = next.scheduled_time.split(':');
                const diff = parseInt(parts[0], 10) * 60 + parseInt(parts[1], 10) - nowMin;
                const timeStr = `${parts[0]}:${parts[1]}`;
                if (diff > 0) {
                    const hrs = Math.floor(diff / 60), mins = diff % 60;
                    const left = hrs > 0 ? `${hrs} hour${hrs > 1 ? 's' : ''} and ${mins} minutes` : `${mins} minutes`;
                    return `Your next dose is ${next.medication_name} ${next.dosage_mg}mg for ${next.patient_name}, scheduled at ${timeStr} — in ${left}.`;
                }
                return `${next.medication_name} was scheduled at ${timeStr} and is now overdue!`;
            }
            if (/\b(missed|skip|skipped)\b/.test(q)) {
                const missed = schedule.filter(d => d.status === 'missed');
                if (!missed.length) return 'Great news! No missed doses today.';
                const names = missed.map(d => `${d.medication_name} at ${d.scheduled_time.substring(0, 5)}`).join(', ');
                return `There ${missed.length > 1 ? 'are' : 'is'} ${missed.length} missed dose${missed.length > 1 ? 's' : ''} today: ${names}. Please tell the care team.`;
            }
            if (/how many|medications|prescription/.test(q)) {
                const meds = [...new Set(schedule.map(d => d.medication_name))];
                return meds.length
                    ? `Today's schedule includes ${schedule.length} dose${schedule.length > 1 ? 's' : ''} of: ${meds.join(', ')}.`
                    : 'No medications are scheduled for today.';
            }
            if (/\b(status|robot)\b/.test(q)) {
                try {
                    const st = await (await fetch(BASE + 'api/robot_action.php?action=status', { cache: 'no-store' })).json();
                    const action = (st.state && st.state.current_action) || 'idle';
                    const loc = (st.state && st.state.current_location) || 'pharmacy';
                    return `MedBot is currently ${action === 'idle' ? 'idle and ready' : action} at ${loc}.`;
                } catch (e) { return 'MedBot status is unavailable right now.'; }
            }
            if (/\b(alert|alerts|interaction|interactions|warning)\b/.test(q)) {
                try {
                    const al = await (await fetch(BASE + 'api/alerts.php', { cache: 'no-store' })).json();
                    return al.count > 0
                        ? `There ${al.count > 1 ? 'are' : 'is'} ${al.count} unresolved drug interaction alert${al.count > 1 ? 's' : ''}. Please check the alerts page.`
                        : 'No active drug interaction alerts. All medications appear safe.';
                } catch (e) { return 'Could not check alerts right now.'; }
            }
            if (/\b(hello|hi|hey)\b/.test(q)) {
                return 'Hello! I am MedBot. You can ask about medicines, doses, schedules or robot status, or switch to “Report symptoms” to tell the care team how you feel.';
            }
            if (/\b(help|what can you)\b/.test(q)) {
                return 'You can ask: Did I take my medication? When is the next dose? Are there missed doses? What is the robot status? Are there drug interaction alerts?';
            }
            if (/\b(pain|hurt|hurts|ache|fever|cough|dizzy|nausea|vomit|vomiting|bleeding|breathe|breathing|headache|sick)\b/.test(q)) {
                return 'That sounds like a symptom. Switch to “Report symptoms” above so a nurse or doctor can see it.';
            }
            return `I heard: “${question}”. You can ask about medication schedules, missed doses, next doses, drug alerts or robot status.`;
        } catch (e) {
            return 'Sorry, I could not fetch medication data right now. Please check your connection.';
        }
    }

    async function askAnswer(question) {
        askUsed = true;
        $('vp-heard').textContent = question;
        $('vp-answer').textContent = t('thinking');
        const response = await getAnswer(question);
        $('vp-answer').textContent = '🤖 ' + response;
        speak(response);
    }

    return {
        togglePanel,
        open: openPanel,
        close: closePanel,
        toggle: togglePanel,
        start: () => { openPanel(); startListening(); },
        stop: stopListening,
    };
})();

function toggleVoice() { voiceBot.togglePanel(); }
