// MedBot Hospital Navigation — A* pathfinding + doctor-confirmed robot missions
//
// FIX: the previous version defined init() but never called it, so the canvas was never
// set up (blank map) and the walls were never created. init() now runs on page load.
//
// New: missions are saved on the server (api/missions.php), so every screen shows the same
// robot movement. When the robot arrives it STOPS and waits; nothing is released until a
// doctor confirms.

const mapEngine = (() => {
    'use strict';

    const SCRIPT_SRC = document.currentScript ? document.currentScript.src : '';
    const BASE = SCRIPT_SRC ? new URL('../', SCRIPT_SRC).pathname : '/medbot/';
    const API  = BASE + 'api/missions.php';

    const COLS = 40, ROWS = 28, CELL = 22, TOP_PAD = 56;
    const POLL_MS = 1500;
    const ROLE = window.MEDBOT_ROLE || '';
    const CAN_CONFIRM = ROLE === 'doctor' || ROLE === 'admin';   // the server enforces this too

    const $ = id => document.getElementById(id);
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
    const toast = (msg, type) => { if (typeof showToast === 'function') showToast(msg, type); };

    // ── Services the robot can carry ──
    const TASKS = {
        medicine:          { icon: '💊', label: 'Medicine delivery',              release: 'release the medicine' },
        first_aid:         { icon: '🩹', label: 'First-aid box',                  release: 'hand over the first-aid box' },
        surgical_supplies: { icon: '🧰', label: 'Surgical / operation materials', release: 'release the surgical materials' },
        message:           { icon: '✉️', label: 'Message / document',             release: 'deliver the message' },
        other:             { icon: '📦', label: 'Other item or service',          release: 'release the item' },
        return:            { icon: '↩️', label: 'Return to base',                 release: '' },
    };

    const STATUS = {
        moving:                { text: 'On the way',      badge: 'badge-warning' },
        awaiting_confirmation: { text: 'Awaiting doctor', badge: 'badge-warning' },
        released:              { text: 'Released',        badge: 'badge-safe' },
        completed:             { text: 'Completed',       badge: 'badge-safe' },
        rejected:              { text: 'Held by doctor',  badge: 'badge-danger' },
        cancelled:             { text: 'Cancelled',       badge: 'badge-gray' },
    };

    // ── Named locations (col, r = grid cell) ──
    const LOCATIONS = {
        pharmacy: { col: 3,  r: 20, label: '🏥 Pharmacy', color: '#6c63ff', big: true },
        nurses:   { col: 20, r: 3,  label: '👩‍⚕️ Nurses',  color: '#ffa94d', big: true },
        icu:      { col: 35, r: 14, label: '🚨 ICU',       color: '#ff4d6d', big: true },
        room_101: { col: 6,  r: 5,  label: '101',          color: '#1e4080' },
        room_102: { col: 13, r: 5,  label: '102',          color: '#1e4080' },
        room_103: { col: 20, r: 12, label: '103',          color: '#1e4080' },
        room_104: { col: 28, r: 5,  label: '104',          color: '#1e4080' },
        room_105: { col: 35, r: 5,  label: '105',          color: '#1e4080' },
        room_1:   { col: 6,  r: 20, label: 'Pt-1',         color: '#1e4080' },
        room_2:   { col: 13, r: 20, label: 'Pt-2',         color: '#1e4080' },
        room_3:   { col: 20, r: 22, label: 'Pt-3',         color: '#1e4080' },
        room_4:   { col: 28, r: 20, label: 'Pt-4',         color: '#1e4080' },
        room_5:   { col: 35, r: 20, label: 'Pt-5',         color: '#1e4080' },
    };

    const patientAt = {};   // location key -> [patient names]

    function locCell(key) {
        const l = LOCATIONS[key] || LOCATIONS.pharmacy;
        return { c: l.col, r: l.r };
    }

    function mapPatients() {
        (window.MEDBOT_PATIENTS || []).forEach(p => {
            const digits = String(p.room_number || '').replace(/\D/g, '');
            let key = null;
            if (digits && LOCATIONS['room_' + digits]) key = 'room_' + digits;
            else if (LOCATIONS['room_' + p.id]) key = 'room_' + p.id;
            if (key) (patientAt[key] = patientAt[key] || []).push(p.name);
        });
    }

    function locName(key) {
        let base = key;
        if (key === 'pharmacy') base = 'Pharmacy';
        else if (key === 'nurses') base = 'Nurses Station';
        else if (key === 'icu') base = 'ICU';
        else if (/^room_1\d\d$/.test(key)) base = 'Room ' + key.slice(5);
        else if (key.indexOf('room_') === 0) base = 'Patient bed ' + key.slice(5);
        const who = patientAt[key];
        return who ? base + ' — ' + who.join(', ') : base;
    }

    // ── Walls ──
    const WALLS = new Set();
    let wallsReady = false;
    function initWalls() {
        if (wallsReady) return;
        wallsReady = true;
        for (let c = 0; c < COLS; c++) { WALLS.add(`${c},0`); WALLS.add(`${c},${ROWS - 1}`); }
        for (let r = 0; r < ROWS; r++) { WALLS.add(`0,${r}`); WALLS.add(`${COLS - 1},${r}`); }
        for (let c = 1; c < COLS - 1; c++) {
            if (c < 10 || c > 16) WALLS.add(`${c},8`);
            if (c < 16 || c > 24) WALLS.add(`${c},17`);
        }
        for (let r = 1; r < 9; r++)         { WALLS.add(`10,${r}`); WALLS.add(`17,${r}`); WALLS.add(`25,${r}`); WALLS.add(`32,${r}`); }
        for (let r = 17; r < ROWS - 1; r++) { WALLS.add(`10,${r}`); WALLS.add(`17,${r}`); WALLS.add(`25,${r}`); WALLS.add(`32,${r}`); }
        ['10,4', '17,4', '25,4', '32,4', '10,21', '17,21', '25,21', '32,21'].forEach(w => WALLS.delete(w));
        [[5, 10], [5, 11], [15, 10], [15, 11], [22, 19], [29, 10], [36, 10]].forEach(([c, r]) => WALLS.add(`${c},${r}`));
    }

    const userObstacles = new Set();
    function isBlocked(c, r) { return WALLS.has(`${c},${r}`) || userObstacles.has(`${c},${r}`); }

    // ── A* ──
    function heuristic(a, b) { return Math.abs(a.c - b.c) + Math.abs(a.r - b.r); }

    function aStar(start, goal) {
        const key = n => `${n.c},${n.r}`;
        const open = [{ c: start.c, r: start.r, g: 0, f: heuristic(start, goal), parent: null }];
        const closed = new Set();
        const best = new Map([[key(start), 0]]);
        while (open.length) {
            open.sort((a, b) => a.f - b.f);
            const cur = open.shift();
            if (cur.c === goal.c && cur.r === goal.r) {
                const p = [];
                for (let n = cur; n; n = n.parent) p.unshift({ c: n.c, r: n.r });
                return p;
            }
            const ck = key(cur);
            if (closed.has(ck)) continue;
            closed.add(ck);
            for (const nb of [{ c: cur.c - 1, r: cur.r }, { c: cur.c + 1, r: cur.r }, { c: cur.c, r: cur.r - 1 }, { c: cur.c, r: cur.r + 1 }]) {
                if (nb.c < 0 || nb.c >= COLS || nb.r < 0 || nb.r >= ROWS || isBlocked(nb.c, nb.r)) continue;
                const nk = key(nb);
                if (closed.has(nk)) continue;
                const g = cur.g + 1;
                if (!best.has(nk) || g < best.get(nk)) {
                    best.set(nk, g);
                    open.push({ c: nb.c, r: nb.r, g, f: g + heuristic(nb, goal), parent: cur });
                }
            }
        }
        return null;
    }

    // ── State ──
    let canvas = null, ctx = null;
    let dpr = 1, scale = 1, offX = 0, offY = 0;
    let preview = [];                 // route from "Find path"
    let mission = null;               // active mission from the server
    let missionFetchedAt = 0;
    let lastPos = null, lastKey = null;
    let history = [];
    let serviceError = '';
    let promptedFor = null;           // mission id the doctor prompt was already shown for
    let cardSig = '';
    const arrivalPolled = {};
    let pollTimer = null;

    function cellCenter(c, r) { return { x: c * CELL + CELL / 2, y: r * CELL + CELL / 2 }; }
    function elapsedNow() { return mission ? mission.elapsed_ms + (performance.now() - missionFetchedAt) : 0; }

    function currentCell() {
        return lastPos ? { c: lastPos.c, r: lastPos.r } : locCell('pharmacy');
    }

    // Where the robot is right now (map pixels) and how far along its path (in cells).
    function robotPose() {
        if (mission && mission.path && mission.path.length > 1) {
            const p = mission.path;
            if (mission.status === 'moving') {
                const f = Math.max(0, Math.min(elapsedNow() / mission.step_ms, p.length - 1));
                const i = Math.min(Math.floor(f), p.length - 2);
                const t = f - i;
                const a = cellCenter(p[i].c, p[i].r), b = cellCenter(p[i + 1].c, p[i + 1].r);
                return { x: a.x + (b.x - a.x) * t, y: a.y + (b.y - a.y) * t, idx: f };
            }
            const e = p[p.length - 1];
            return Object.assign(cellCenter(e.c, e.r), { idx: p.length - 1 });
        }
        const cc = currentCell();
        return Object.assign(cellCenter(cc.c, cc.r), { idx: 0 });
    }

    // ── Canvas sizing: fit the whole 40x28 map inside the card, below the header ──
    function resize() {
        if (!canvas) return;
        const box = canvas.parentElement;
        const w = Math.max(box.clientWidth, 200), h = Math.max(box.clientHeight, 300);
        dpr = window.devicePixelRatio || 1;
        canvas.width = Math.round(w * dpr);
        canvas.height = Math.round(h * dpr);
        const availH = h - TOP_PAD - 8;
        scale = Math.min((w - 16) / (COLS * CELL), availH / (ROWS * CELL));
        offX = (w - COLS * CELL * scale) / 2;
        offY = TOP_PAD + (availH - ROWS * CELL * scale) / 2;
    }

    function rrect(x, y, w, h, r) {
        if (ctx.roundRect) ctx.roundRect(x, y, w, h, r); else ctx.rect(x, y, w, h);
    }

    function tracePath(pts) {
        ctx.beginPath();
        pts.forEach((p, i) => {
            const q = cellCenter(p.c, p.r);
            if (i === 0) ctx.moveTo(q.x, q.y); else ctx.lineTo(q.x, q.y);
        });
    }

    function drawEndpoint(cell, color, label) {
        const pt = cellCenter(cell.c, cell.r);
        ctx.beginPath();
        ctx.arc(pt.x, pt.y, 9, 0, Math.PI * 2);
        ctx.fillStyle = color;
        ctx.fill();
        ctx.fillStyle = '#000';
        ctx.font = 'bold 9px Inter, sans-serif';
        ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
        ctx.fillText(label, pt.x, pt.y);
    }

    function drawMap() {
        if (!ctx) return;
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#12141b';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.setTransform(dpr * scale, 0, 0, dpr * scale, dpr * offX, dpr * offY);

        const W = COLS * CELL, H = ROWS * CELL;

        // Floor
        ctx.fillStyle = '#252836';
        ctx.fillRect(0, 0, W, H);

        // Corridor tint
        ctx.fillStyle = 'rgba(108,99,255,0.05)';
        ctx.fillRect(CELL, 9 * CELL, (COLS - 2) * CELL, 8 * CELL);

        // Grid lines
        ctx.beginPath();
        for (let c = 0; c <= COLS; c++) { ctx.moveTo(c * CELL, 0); ctx.lineTo(c * CELL, H); }
        for (let r = 0; r <= ROWS; r++) { ctx.moveTo(0, r * CELL); ctx.lineTo(W, r * CELL); }
        ctx.strokeStyle = 'rgba(255,255,255,0.05)';
        ctx.lineWidth = 0.5;
        ctx.stroke();

        // Walls
        ctx.fillStyle = '#3a3d4a';
        WALLS.forEach(k => {
            const [c, r] = k.split(',').map(Number);
            ctx.fillRect(c * CELL + 0.5, r * CELL + 0.5, CELL - 1, CELL - 1);
        });

        // User obstacles
        userObstacles.forEach(k => {
            const [c, r] = k.split(',').map(Number);
            const x = c * CELL, y = r * CELL;
            ctx.fillStyle = '#4a1a20';
            ctx.fillRect(x + 0.5, y + 0.5, CELL - 1, CELL - 1);
            ctx.strokeStyle = '#ff4d6d';
            ctx.lineWidth = 1.5;
            ctx.strokeRect(x + 2, y + 2, CELL - 4, CELL - 4);
            ctx.beginPath();
            ctx.moveTo(x + 4, y + 4); ctx.lineTo(x + CELL - 4, y + CELL - 4);
            ctx.moveTo(x + CELL - 4, y + 4); ctx.lineTo(x + 4, y + CELL - 4);
            ctx.strokeStyle = 'rgba(255,77,109,0.5)';
            ctx.lineWidth = 1;
            ctx.stroke();
        });

        const pose = robotPose();
        ctx.lineCap = 'round'; ctx.lineJoin = 'round';

        // Preview route (before dispatch)
        if (!mission && preview.length > 1) {
            tracePath(preview);
            ctx.strokeStyle = 'rgba(108,99,255,0.25)';
            ctx.lineWidth = CELL * 0.7;
            ctx.stroke();
            tracePath(preview);
            ctx.strokeStyle = '#6c63ff';
            ctx.lineWidth = 4;
            ctx.stroke();
            tracePath(preview);
            ctx.strokeStyle = 'rgba(255,255,255,0.6)';
            ctx.lineWidth = 2;
            ctx.setLineDash([6, 10]);
            ctx.lineDashOffset = -(Date.now() / 200) % 16;
            ctx.stroke();
            ctx.setLineDash([]);
            drawEndpoint(preview[0], '#00d4aa', 'S');
            drawEndpoint(preview[preview.length - 1], '#ffa94d', 'E');
        }

        // Active mission route
        if (mission && mission.path && mission.path.length > 1) {
            const p = mission.path;
            tracePath(p);
            ctx.strokeStyle = 'rgba(108,99,255,0.28)';
            ctx.lineWidth = CELL * 0.7;
            ctx.stroke();
            tracePath(p);
            ctx.strokeStyle = 'rgba(108,99,255,0.9)';
            ctx.lineWidth = 3;
            ctx.stroke();

            // Distance already travelled
            const done = Math.floor(pose.idx);
            ctx.beginPath();
            for (let i = 0; i <= done && i < p.length; i++) {
                const q = cellCenter(p[i].c, p[i].r);
                if (i === 0) ctx.moveTo(q.x, q.y); else ctx.lineTo(q.x, q.y);
            }
            ctx.lineTo(pose.x, pose.y);
            ctx.strokeStyle = '#00d4aa';
            ctx.lineWidth = 4;
            ctx.stroke();

            drawEndpoint(p[0], '#00d4aa', 'S');
            const end = p[p.length - 1], ec = cellCenter(end.c, end.r);
            const pulse = (Math.sin(Date.now() / 300) + 1) / 2;
            ctx.beginPath();
            ctx.arc(ec.x, ec.y, 13 + pulse * 6, 0, Math.PI * 2);
            ctx.strokeStyle = mission.status === 'awaiting_confirmation' ? 'rgba(255,169,77,0.95)' : 'rgba(255,169,77,0.5)';
            ctx.lineWidth = 2;
            ctx.stroke();
            drawEndpoint(end, '#ffa94d', 'E');
        }

        // Named locations
        for (const loc of Object.values(LOCATIONS)) {
            const { x, y } = cellCenter(loc.col, loc.r);
            const r = loc.big ? CELL : CELL * 0.85;

            const grad = ctx.createRadialGradient(x, y, 0, x, y, r * 1.5);
            grad.addColorStop(0, loc.color + '55');
            grad.addColorStop(1, loc.color + '00');
            ctx.beginPath();
            ctx.arc(x, y, r * 1.5, 0, Math.PI * 2);
            ctx.fillStyle = grad;
            ctx.fill();

            ctx.beginPath();
            if (loc.big) ctx.arc(x, y, r * 0.9, 0, Math.PI * 2);
            else { const hs = r * 0.85; rrect(x - hs, y - hs, hs * 2, hs * 2, 4); }
            ctx.fillStyle = loc.color;
            ctx.globalAlpha = 0.92;
            ctx.fill();
            ctx.globalAlpha = 1;
            ctx.strokeStyle = 'rgba(255,255,255,0.4)';
            ctx.lineWidth = 1.5;
            ctx.stroke();

            ctx.fillStyle = '#ffffff';
            ctx.font = `bold ${loc.big ? 9 : 8}px Inter, sans-serif`;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(loc.label, x, y);
        }

        drawRobot(pose.x, pose.y);
    }

    function drawRobot(x, y) {
        const waiting = mission && mission.status === 'awaiting_confirmation';
        const t = Date.now() / 600;
        const pulse = Math.sin(t) * 3 + 5;
        const glow = waiting ? '255,169,77' : '0,212,170';

        const grad = ctx.createRadialGradient(x, y, 0, x, y, 22 + pulse);
        grad.addColorStop(0, `rgba(${glow},0.7)`);
        grad.addColorStop(0.5, `rgba(${glow},0.25)`);
        grad.addColorStop(1, `rgba(${glow},0)`);
        ctx.beginPath();
        ctx.arc(x, y, 22 + pulse, 0, Math.PI * 2);
        ctx.fillStyle = grad;
        ctx.fill();

        ctx.beginPath();
        ctx.arc(x + 1, y + 1, 11, 0, Math.PI * 2);
        ctx.fillStyle = 'rgba(0,0,0,0.5)';
        ctx.fill();

        ctx.beginPath();
        ctx.arc(x, y, 11, 0, Math.PI * 2);
        const bodyGrad = ctx.createRadialGradient(x - 3, y - 3, 0, x, y, 11);
        bodyGrad.addColorStop(0, waiting ? '#ffd08a' : '#33eebb');
        bodyGrad.addColorStop(1, waiting ? '#d98a20' : '#00a884');
        ctx.fillStyle = bodyGrad;
        ctx.fill();
        ctx.strokeStyle = '#ffffff';
        ctx.lineWidth = 2;
        ctx.stroke();

        ctx.font = '12px serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = '#fff';
        ctx.fillText('🤖', x, y);

        // Cargo + status tag above the robot
        if (mission) {
            const task = TASKS[mission.task_type] || TASKS.other;
            const tag = waiting ? `${task.icon} waiting for doctor` : task.icon;
            ctx.font = 'bold 10px Inter, sans-serif';
            const w = ctx.measureText(tag).width + 12;
            ctx.beginPath();
            rrect(x - w / 2, y - 34, w, 16, 8);
            ctx.fillStyle = waiting ? 'rgba(217,138,32,0.95)' : 'rgba(13,17,23,0.9)';
            ctx.fill();
            ctx.fillStyle = '#fff';
            ctx.fillText(tag, x, y - 26);
        }
    }

    // ── UI helpers ──
    function setBadge(text, cls) {
        const el = $('path-status');
        if (!el) return;
        if (el.textContent !== text) el.textContent = text;
        const want = 'badge ' + cls;
        if (el.className !== want) el.className = want;
    }

    function missionLabel(m) {
        const t = TASKS[m.task_type] || TASKS.other;
        return `${t.icon} ${t.label}`;
    }

    function renderMissionCard() {
        const box = $('mission-card');
        if (!box) return;
        const dispatchBtn = $('dispatch-btn');
        if (dispatchBtn) dispatchBtn.disabled = !!mission || !!serviceError;

        if (serviceError) {
            box.innerHTML = `<div class="mb-note mb-note-danger"><strong>Mission service unavailable.</strong><br>${esc(serviceError)}<br>Check that <code>api/missions.php</code> is deployed and the database is reachable.</div>`;
            return;
        }

        if (!mission) {
            const where = lastKey ? locName(lastKey) : (lastPos ? 'a stopped position' : 'Pharmacy');
            const away = lastKey !== 'pharmacy' && (lastKey || lastPos);
            box.innerHTML = `<div class="mb-note"><strong>Robot idle</strong> at ${esc(where)}.<br>Pick a destination and service, then dispatch.</div>` +
                (away ? `<button class="btn btn-secondary" data-act="return" style="width:100%;margin-top:10px">↩ Send back to pharmacy</button>` : '');
            return;
        }

        const t = TASKS[mission.task_type] || TASKS.other;
        const head = `<div class="mb-mission-title">${t.icon} ${esc(t.label)}</div>
            <div class="mb-route">${esc(mission.from_label)} <span aria-hidden="true">→</span> ${esc(mission.to_label)}</div>` +
            (mission.note ? `<div class="mb-meta">Note: ${esc(mission.note)}</div>` : '') +
            (mission.requested_by ? `<div class="mb-meta">Requested by ${esc(mission.requested_by)}</div>` : '');

        if (mission.status === 'moving') {
            box.innerHTML = head + `
                <div class="mb-bar"><div id="mc-bar"></div></div>
                <div class="mb-meta" id="mc-eta">Starting…</div>
                <button class="btn btn-secondary" data-act="cancel" style="width:100%;margin-top:10px">⏹ Stop robot</button>`;
        } else {
            box.innerHTML = `<div class="mb-note mb-note-warn"><strong>Robot has arrived at ${esc(mission.to_label)}.</strong><br>It is holding the ${esc(t.label.toLowerCase())} until a doctor confirms.</div>` +
                head + (CAN_CONFIRM
                    ? `<button class="btn btn-teal" data-act="review" style="width:100%;margin-top:10px">🩺 Review &amp; confirm</button>`
                    : `<div class="mb-meta" style="margin-top:10px">Waiting for a doctor to confirm the release.</div>`);
        }
    }

    function tickProgress() {
        if (!mission) {
            if (!serviceError) setBadge(preview.length ? 'Path ready' : 'Ready', 'badge-info');
            return;
        }
        const steps = Math.max(1, mission.path.length - 1);
        if (mission.status === 'moving') {
            const total = steps * mission.step_ms;
            const el = Math.min(elapsedNow(), total);
            const pct = Math.round(el / total * 100);
            const bar = $('mc-bar');
            if (bar) bar.style.width = pct + '%';
            const eta = $('mc-eta');
            if (eta) eta.textContent = `${pct}% — about ${Math.max(0, Math.ceil((total - el) / 1000))} s to go`;
            setBadge(`Moving ${pct}%`, 'badge-warning');
            if (el >= total && !arrivalPolled[mission.id]) {
                arrivalPolled[mission.id] = true;
                poll(true);
            }
        } else if (mission.status === 'awaiting_confirmation') {
            setBadge('Awaiting doctor', 'badge-warning');
        }
    }

    function renderHistory() {
        const box = $('mission-history');
        if (!box) return;
        if (!history.length) { box.innerHTML = '<div class="mb-meta">No missions yet.</div>'; return; }
        box.innerHTML = history.slice(0, 6).map(h => {
            const s = STATUS[h.status] || { text: h.status, badge: 'badge-gray' };
            const t = TASKS[h.task_type] || TASKS.other;
            return `<div class="mb-hist"><div><span aria-hidden="true">${t.icon}</span> #${h.id} ${esc(h.from_label)} → ${esc(h.to_label)}` +
                (h.confirmed_by ? `<div class="mb-meta">by ${esc(h.confirmed_by)}</div>` : '') +
                `</div><span class="badge ${s.badge}">${esc(s.text)}</span></div>`;
        }).join('');
    }

    // ── Doctor confirmation dialog ──
    function modalOpen() { const m = $('confirm-modal'); return m && !m.hidden; }

    function openModal() {
        const modal = $('confirm-modal');
        if (!modal || !mission || mission.status !== 'awaiting_confirmation') return;
        const t = TASKS[mission.task_type] || TASKS.other;
        $('cm-body').innerHTML =
            `<p><strong>${esc(mission.to_label)}</strong> — the robot has arrived with:</p>` +
            `<p class="mb-big">${t.icon} ${esc(t.label)}</p>` +
            (mission.note ? `<p class="mb-meta">Note: ${esc(mission.note)}</p>` : '') +
            `<p class="mb-meta">From ${esc(mission.from_label)}${mission.requested_by ? ' · requested by ' + esc(mission.requested_by) : ''}</p>` +
            `<p>Confirm to ${esc(t.release)}. If you hold it, the robot keeps the item.</p>` +
            `<p class="mb-meta">You are signed in as <strong>${esc(window.MEDBOT_USER || '')}</strong>. Your name is recorded with this decision.</p>`;
        $('cm-error').textContent = '';
        modal.hidden = false;
        $('cm-release').focus();
    }

    function closeModal() { const m = $('confirm-modal'); if (m) m.hidden = true; }

    async function decide(decision) {
        if (!mission) return;
        $('cm-release').disabled = $('cm-reject').disabled = true;
        try {
            await api('POST', { action: 'confirm', id: mission.id, decision });
            closeModal();
            toast(decision === 'release' ? '✅ Released — recorded in the robot log' : '✋ Release refused — recorded in the robot log', decision === 'release' ? 'success' : 'warning');
            await poll(true);
        } catch (e) {
            $('cm-error').textContent = e.message;
            if (e.status === 409) poll(true);
        } finally {
            $('cm-release').disabled = $('cm-reject').disabled = false;
        }
    }

    // ── Server calls ──
    async function api(method, body) {
        const opt = { method, cache: 'no-store', headers: { 'Content-Type': 'application/json' } };
        if (body) opt.body = JSON.stringify(body);
        const res = await fetch(method === 'GET' ? API + '?action=active' : API, opt);
        let data = null;
        try { data = await res.json(); } catch (e) { /* not JSON */ }
        if (res.status === 401) { location.href = BASE + 'login.php'; }
        if (!res.ok || !data || data.error) {
            const err = new Error((data && data.message) || ('Server returned HTTP ' + res.status));
            err.status = res.status;
            throw err;
        }
        return data;
    }

    async function poll(now) {
        clearTimeout(pollTimer);
        try {
            const d = await api('GET');
            serviceError = '';
            const prev = mission;
            mission = d.mission || null;
            missionFetchedAt = performance.now();
            lastPos = d.last_position || null;
            lastKey = d.last_key || null;
            history = d.history || [];

            const sig = mission ? mission.id + ':' + mission.status : 'none';
            if (sig !== cardSig) { cardSig = sig; renderMissionCard(); }
            renderHistory();

            if (mission && mission.status === 'awaiting_confirmation' && promptedFor !== mission.id) {
                promptedFor = mission.id;
                toast('🤖 Robot arrived at ' + mission.to_label + (CAN_CONFIRM ? ' — doctor confirmation needed' : ' — waiting for a doctor to confirm'), 'warning');
                if (CAN_CONFIRM) openModal();
            }
            if (modalOpen() && (!mission || mission.status !== 'awaiting_confirmation')) {
                closeModal();
                if (prev && prev.status === 'awaiting_confirmation') toast('This mission was already decided.', 'info');
            }
        } catch (e) {
            if (serviceError !== e.message) { serviceError = e.message; cardSig = 'err'; renderMissionCard(); }
        }
        pollTimer = setTimeout(poll, POLL_MS);
    }

    // ── Routing ──
    function computeRoute() {
        const fromSel = $('from-room').value;
        const toKey = $('to-room').value;
        if (!toKey) { toast('Please select a destination', 'warning'); return null; }

        let fromKey = fromSel, fromLabel, start;
        if (fromSel === '__current') {
            start = currentCell();
            fromKey = lastKey || 'current';
            fromLabel = lastKey ? locName(lastKey) : 'Current position';
        } else {
            start = locCell(fromSel);
            fromLabel = locName(fromSel);
        }
        const goal = locCell(toKey);
        if (start.c === goal.c && start.r === goal.r) { toast('Origin and destination are the same place', 'warning'); return null; }

        setBadge('Computing…', 'badge-warning');
        const path = aStar(start, goal);
        if (!path) {
            preview = [];
            setBadge('No path', 'badge-danger');
            toast('No path found! Try removing some obstacles.', 'error');
            return null;
        }
        preview = path;

        const dist = path.length - 1;
        const stepMs = parseInt($('speed').value, 10) || 350;
        const secs = (dist * stepMs / 1000).toFixed(1);
        if ($('stat-dist'))  $('stat-dist').textContent  = dist + ' cells';
        if ($('stat-steps')) $('stat-steps').textContent = dist;
        if ($('stat-time'))  $('stat-time').textContent  = '~' + secs + ' s';
        const badge = $('path-distance');
        if (badge) { badge.textContent = dist + ' steps'; badge.style.display = ''; }

        return { fromKey, fromLabel, toKey, toLabel: locName(toKey), path, stepMs, dist, secs };
    }

    async function startMission(route, task, note, requestedBy) {
        try {
            const d = await api('POST', {
                action: 'create', task_type: task, note, requested_by: requestedBy,
                from_key: route.fromKey, to_key: route.toKey,
                from_label: route.fromLabel, to_label: route.toLabel,
                path: route.path, step_ms: route.stepMs,
            });
            mission = d.mission;
            missionFetchedAt = performance.now();
            preview = [];
            cardSig = mission.id + ':' + mission.status;
            renderMissionCard();
            toast(`🚀 Robot dispatched to ${route.toLabel}`, 'success');
            poll(true);
        } catch (e) {
            toast(e.message, 'error');
            if (e.status === 409) poll(true);
        }
    }

    // ── Setup ──
    function addOption(sel, value, text) {
        const o = document.createElement('option');
        o.value = value;
        o.textContent = text;
        sel.appendChild(o);
    }

    function fillSelects() {
        const from = $('from-room'), to = $('to-room');
        if (!from || !to) return;
        from.innerHTML = '';
        to.innerHTML = '';
        addOption(from, '__current', '📍 Robot’s current position');
        addOption(to, '', '— Select destination —');
        Object.keys(LOCATIONS).forEach(k => { addOption(from, k, locName(k)); addOption(to, k, locName(k)); });

        const svc = $('task-type');
        if (svc && !svc.options.length) {
            Object.keys(TASKS).filter(k => k !== 'return').forEach(k => addOption(svc, k, TASKS[k].icon + ' ' + TASKS[k].label));
        }
    }

    function init() {
        initWalls();
        mapPatients();
        canvas = $('map-canvas');
        if (!canvas) return;

        // Make the canvas exactly the size of its card, whatever the grid does.
        canvas.style.position = 'absolute';
        canvas.style.inset = '0';
        canvas.style.width = '100%';
        canvas.style.height = '100%';
        ctx = canvas.getContext('2d');
        resize();
        if (window.ResizeObserver) new ResizeObserver(resize).observe(canvas.parentElement);
        window.addEventListener('resize', resize);

        fillSelects();

        // Click the map to add or remove an obstacle
        canvas.addEventListener('click', e => {
            const rect = canvas.getBoundingClientRect();
            const gx = (e.clientX - rect.left - offX) / scale / CELL;
            const gy = (e.clientY - rect.top - offY) / scale / CELL;
            const c = Math.floor(gx), r = Math.floor(gy);
            if (c < 0 || c >= COLS || r < 0 || r >= ROWS) return;
            const k = `${c},${r}`;
            if (WALLS.has(k) || Object.values(LOCATIONS).some(l => l.col === c && l.r === r)) return;
            if (userObstacles.has(k)) userObstacles.delete(k); else userObstacles.add(k);
            if ($('stat-obs')) $('stat-obs').textContent = userObstacles.size;
        });

        // Mission card buttons
        $('mission-card').addEventListener('click', async e => {
            const b = e.target.closest('[data-act]');
            if (!b || !mission && b.dataset.act !== 'return') return;
            if (b.dataset.act === 'review') openModal();
            if (b.dataset.act === 'cancel') {
                if (!confirm('Stop the robot where it is?')) return;
                try { await api('POST', { action: 'cancel', id: mission.id }); toast('Robot stopped', 'warning'); }
                catch (err) { toast(err.message, 'error'); }
                poll(true);
            }
            if (b.dataset.act === 'return') {
                const start = currentCell();
                const path = aStar(start, locCell('pharmacy'));
                if (!path) { toast('No path back to the pharmacy. Remove some obstacles.', 'error'); return; }
                startMission({
                    fromKey: lastKey || 'current', fromLabel: lastKey ? locName(lastKey) : 'Current position',
                    toKey: 'pharmacy', toLabel: locName('pharmacy'), path,
                    stepMs: parseInt($('speed').value, 10) || 350,
                }, 'return', '', '');
            }
        });

        // Confirmation dialog
        $('cm-release').addEventListener('click', () => decide('release'));
        $('cm-reject').addEventListener('click', () => decide('reject'));
        $('cm-later').addEventListener('click', closeModal);
        document.addEventListener('keydown', e => { if (e.key === 'Escape' && modalOpen()) closeModal(); });

        renderMissionCard();
        renderHistory();
        poll();

        (function loop() {
            drawMap();
            tickProgress();
            requestAnimationFrame(loop);
        })();
    }

    return {
        init,
        // Preview the route without dispatching
        findPath() { computeRoute(); },
        // Send the robot: route + save the mission on the server
        dispatch() {
            if (mission) { toast('The robot is already on a mission.', 'warning'); return; }
            const route = computeRoute();
            if (!route) return;
            const task = $('task-type').value || 'medicine';
            const note = $('task-note').value.trim();
            startMission(route, task, note, '');
        },
        resetMap() {
            preview = [];
            userObstacles.clear();
            ['stat-dist', 'stat-steps', 'stat-time'].forEach(id => { if ($(id)) $(id).textContent = '—'; });
            if ($('stat-obs')) $('stat-obs').textContent = '0';
            if ($('path-distance')) $('path-distance').style.display = 'none';
        },
        // Shortest route between two named locations (useful for testing)
        route(fromKey, toKey) { initWalls(); return aStar(locCell(fromKey), locCell(toKey)); },
    };
})();

function findPath()     { mapEngine.findPath(); }
function dispatchRobot() { mapEngine.dispatch(); }
function resetMap()     { mapEngine.resetMap(); }

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => mapEngine.init());
else mapEngine.init();
