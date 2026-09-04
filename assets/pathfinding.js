// MedBot Hospital A* Pathfinding Engine — Fixed & Enhanced
const mapEngine = (() => {
    const COLS = 40, ROWS = 28, CELL = 22;
    let canvas, ctx;
    let path = [], robotPos = null, animating = false, robotAnimPos = null;
    let animRafId = null;

    // Named locations
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

    // Wall layout
    const WALLS = new Set();
    function initWalls() {
        for (let c = 0; c < COLS; c++) { WALLS.add(`${c},0`); WALLS.add(`${c},${ROWS-1}`); }
        for (let r = 0; r < ROWS; r++) { WALLS.add(`0,${r}`); WALLS.add(`${COLS-1},${r}`); }
        for (let c = 1; c < COLS - 1; c++) {
            if (c < 10 || c > 16) WALLS.add(`${c},8`);
            if (c < 16 || c > 24) WALLS.add(`${c},17`);
        }
        for (let r = 1; r < 9; r++)         { WALLS.add(`10,${r}`); WALLS.add(`17,${r}`); WALLS.add(`25,${r}`); WALLS.add(`32,${r}`); }
        for (let r = 17; r < ROWS - 1; r++) { WALLS.add(`10,${r}`); WALLS.add(`17,${r}`); WALLS.add(`25,${r}`); WALLS.add(`32,${r}`); }
        ['10,4','17,4','25,4','32,4','10,21','17,21','25,21','32,21'].forEach(w => WALLS.delete(w));
        [[5,10],[5,11],[15,10],[15,11],[22,19],[29,10],[36,10]].forEach(([c,r]) => WALLS.add(`${c},${r}`));
    }

    const userObstacles = new Set();
    function isBlocked(c, r) { return WALLS.has(`${c},${r}`) || userObstacles.has(`${c},${r}`); }

    // A* algorithm
    function heuristic(a, b) { return Math.abs(a.c - b.c) + Math.abs(a.r - b.r); }

    function aStar(start, goal) {
        const key = n => `${n.c},${n.r}`;
        const open = [{ ...start, g: 0, h: heuristic(start, goal), f: heuristic(start, goal), parent: null }];
        const closed = new Set();
        const best = new Map();
        best.set(key(start), 0);
        while (open.length) {
            open.sort((a, b) => a.f - b.f);
            const cur = open.shift();
            if (cur.c === goal.c && cur.r === goal.r) {
                const p = [];
                let n = cur;
                while (n) { p.unshift({ c: n.c, r: n.r }); n = n.parent; }
                return p;
            }
            const ck = key(cur);
            if (closed.has(ck)) continue;
            closed.add(ck);
            for (const nb of [{ c: cur.c-1, r: cur.r },{ c: cur.c+1, r: cur.r },{ c: cur.c, r: cur.r-1 },{ c: cur.c, r: cur.r+1 }]) {
                if (nb.c < 0 || nb.c >= COLS || nb.r < 0 || nb.r >= ROWS || isBlocked(nb.c, nb.r)) continue;
                const nk = key(nb);
                if (closed.has(nk)) continue;
                const g = cur.g + 1;
                if (!best.has(nk) || g < best.get(nk)) {
                    best.set(nk, g);
                    const h = heuristic(nb, goal);
                    open.push({ ...nb, g, h, f: g + h, parent: cur });
                }
            }
        }
        return null;
    }

    function cellCenter(c, r) { return { x: c * CELL + CELL / 2, y: r * CELL + CELL / 2 }; }

    function drawMap() {
        if (!canvas || !ctx) return;
        const W = canvas.width, H = canvas.height;
        ctx.clearRect(0, 0, W, H);

        // ── BACKGROUND — dark grey (visible) ──
        ctx.fillStyle = '#1a1d26';
        ctx.fillRect(0, 0, W, H);

        // ── CELLS ──
        for (let r = 0; r < ROWS; r++) {
            for (let c = 0; c < COLS; c++) {
                const x = c * CELL, y = r * CELL;
                const key = `${c},${r}`;
                if (WALLS.has(key)) {
                    // Walls: solid grey blocks, clearly visible
                    ctx.fillStyle = '#3a3d4a';
                    ctx.fillRect(x + 0.5, y + 0.5, CELL - 1, CELL - 1);
                } else if (userObstacles.has(key)) {
                    // User obstacles: red with pattern
                    ctx.fillStyle = '#4a1a20';
                    ctx.fillRect(x + 0.5, y + 0.5, CELL - 1, CELL - 1);
                    ctx.strokeStyle = '#ff4d6d';
                    ctx.lineWidth = 1.5;
                    ctx.strokeRect(x + 2, y + 2, CELL - 4, CELL - 4);
                    // X pattern
                    ctx.beginPath();
                    ctx.moveTo(x + 4, y + 4); ctx.lineTo(x + CELL - 4, y + CELL - 4);
                    ctx.moveTo(x + CELL - 4, y + 4); ctx.lineTo(x + 4, y + CELL - 4);
                    ctx.strokeStyle = 'rgba(255,77,109,0.5)';
                    ctx.lineWidth = 1;
                    ctx.stroke();
                } else {
                    // Open floor — lighter grey so rooms stand out
                    ctx.fillStyle = '#252836';
                    ctx.fillRect(x + 0.5, y + 0.5, CELL - 1, CELL - 1);
                    // Subtle grid lines
                    ctx.strokeStyle = 'rgba(255,255,255,0.05)';
                    ctx.lineWidth = 0.5;
                    ctx.strokeRect(x + 0.5, y + 0.5, CELL - 1, CELL - 1);
                }
            }
        }

        // ── CORRIDOR HIGHLIGHT (rows 8-17 / 0-8) ──
        ctx.fillStyle = 'rgba(108,99,255,0.04)';
        for (let c = 1; c < COLS - 1; c++) {
            for (let r = 9; r < 17; r++) {
                if (!isBlocked(c, r)) {
                    ctx.fillRect(c * CELL + 0.5, r * CELL + 0.5, CELL - 1, CELL - 1);
                }
            }
        }

        // ── PATH ──
        if (path.length > 1) {
            // Thick shadow
            ctx.beginPath();
            const ps = cellCenter(path[0].c, path[0].r);
            ctx.moveTo(ps.x, ps.y);
            for (let i = 1; i < path.length; i++) {
                const p = cellCenter(path[i].c, path[i].r);
                ctx.lineTo(p.x, p.y);
            }
            ctx.strokeStyle = 'rgba(108,99,255,0.25)';
            ctx.lineWidth = CELL * 0.7;
            ctx.lineCap = 'round'; ctx.lineJoin = 'round';
            ctx.stroke();

            // Bright path line
            ctx.beginPath();
            ctx.moveTo(ps.x, ps.y);
            for (let i = 1; i < path.length; i++) {
                const p = cellCenter(path[i].c, path[i].r);
                ctx.lineTo(p.x, p.y);
            }
            ctx.strokeStyle = '#6c63ff';
            ctx.lineWidth = 4;
            ctx.stroke();

            // Dashed animated overlay
            const t = Date.now() / 200;
            ctx.beginPath();
            ctx.moveTo(ps.x, ps.y);
            for (let i = 1; i < path.length; i++) {
                const p = cellCenter(path[i].c, path[i].r);
                ctx.lineTo(p.x, p.y);
            }
            ctx.strokeStyle = 'rgba(255,255,255,0.6)';
            ctx.lineWidth = 2;
            ctx.setLineDash([6, 10]);
            ctx.lineDashOffset = -t % 16;
            ctx.stroke();
            ctx.setLineDash([]);

            // Start / end markers
            const startC = cellCenter(path[0].c, path[0].r);
            const endC   = cellCenter(path[path.length - 1].c, path[path.length - 1].r);
            [{ pt: startC, color: '#00d4aa', label: 'S' }, { pt: endC, color: '#ffa94d', label: 'E' }].forEach(({ pt, color, label }) => {
                ctx.beginPath();
                ctx.arc(pt.x, pt.y, 9, 0, Math.PI * 2);
                ctx.fillStyle = color;
                ctx.fill();
                ctx.fillStyle = '#000';
                ctx.font = 'bold 9px Inter, sans-serif';
                ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
                ctx.fillText(label, pt.x, pt.y);
            });
        }

        // ── NAMED LOCATIONS ──
        for (const [k, loc] of Object.entries(LOCATIONS)) {
            const { x, y } = cellCenter(loc.col, loc.r);
            const isSpecial = loc.big;
            const r = isSpecial ? CELL : CELL * 0.85;

            // Glow
            const grad = ctx.createRadialGradient(x, y, 0, x, y, r * 1.5);
            grad.addColorStop(0, loc.color + '55');
            grad.addColorStop(1, loc.color + '00');
            ctx.beginPath();
            ctx.arc(x, y, r * 1.5, 0, Math.PI * 2);
            ctx.fillStyle = grad;
            ctx.fill();

            // Body
            ctx.beginPath();
            if (isSpecial) {
                ctx.arc(x, y, r * 0.9, 0, Math.PI * 2);
            } else {
                const hs = r * 0.85;
                ctx.roundRect(x - hs, y - hs, hs * 2, hs * 2, 4);
            }
            ctx.fillStyle = loc.color;
            ctx.globalAlpha = 0.92;
            ctx.fill();
            ctx.globalAlpha = 1;

            // Border
            ctx.strokeStyle = 'rgba(255,255,255,0.4)';
            ctx.lineWidth = 1.5;
            ctx.stroke();

            // Label
            ctx.fillStyle = '#ffffff';
            ctx.font = `bold ${isSpecial ? 9 : 8}px Inter, sans-serif`;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(loc.label, x, y);
        }

        // ── ROBOT ──
        const rp = robotAnimPos
            || (robotPos ? cellCenter(robotPos.c, robotPos.r) : null)
            || cellCenter(LOCATIONS.pharmacy.col, LOCATIONS.pharmacy.r);
        drawRobot(rp.x, rp.y);
    }

    function drawRobot(x, y) {
        const t = Date.now() / 600;
        const pulse = Math.sin(t) * 3 + 5;

        // Outer glow
        const grad = ctx.createRadialGradient(x, y, 0, x, y, 22 + pulse);
        grad.addColorStop(0, 'rgba(0,212,170,0.7)');
        grad.addColorStop(0.5, 'rgba(0,212,170,0.25)');
        grad.addColorStop(1, 'rgba(0,212,170,0)');
        ctx.beginPath();
        ctx.arc(x, y, 22 + pulse, 0, Math.PI * 2);
        ctx.fillStyle = grad;
        ctx.fill();

        // Shadow
        ctx.beginPath();
        ctx.arc(x + 1, y + 1, 11, 0, Math.PI * 2);
        ctx.fillStyle = 'rgba(0,0,0,0.5)';
        ctx.fill();

        // Main circle
        ctx.beginPath();
        ctx.arc(x, y, 11, 0, Math.PI * 2);
        const bodyGrad = ctx.createRadialGradient(x - 3, y - 3, 0, x, y, 11);
        bodyGrad.addColorStop(0, '#33eebb');
        bodyGrad.addColorStop(1, '#00a884');
        ctx.fillStyle = bodyGrad;
        ctx.fill();
        ctx.strokeStyle = '#ffffff';
        ctx.lineWidth = 2;
        ctx.stroke();

        // Icon
        ctx.font = '12px serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText('🤖', x, y);
    }

    // ── ANIMATION LOOP ──
    function startAnimLoop() {
        function loop() {
            drawMap();
            animRafId = requestAnimationFrame(loop);
        }
        if (animRafId) cancelAnimationFrame(animRafId);
        animRafId = requestAnimationFrame(loop);
    }

    // ── ROBOT MOVEMENT ANIMATION ──
    async function animateRobotAlongPath() {
        if (!path.length || animating) return;
        animating = true;
        const btn = document.getElementById('animate-btn');
        if (btn) btn.disabled = true;

        const totalNodes = path.length;
        const totalDuration = Math.min(4000, Math.max(2000, totalNodes * 80));
        const stepDuration = totalDuration / (totalNodes - 1);

        for (let i = 0; i < totalNodes - 1; i++) {
            if (!animating) break;
            const from = cellCenter(path[i].c, path[i].r);
            const to   = cellCenter(path[i + 1].c, path[i + 1].r);
            const steps = 12;
            for (let s = 0; s <= steps; s++) {
                const t = s / steps;
                const ease = t < 0.5 ? 2 * t * t : -1 + (4 - 2 * t) * t;
                robotAnimPos = {
                    x: from.x + (to.x - from.x) * ease,
                    y: from.y + (to.y - from.y) * ease
                };
                await new Promise(r => setTimeout(r, stepDuration / steps));
            }
        }

        robotAnimPos = null;
        robotPos = path[path.length - 1];
        animating = false;

        const st = document.getElementById('path-status');
        if (st) { st.textContent = '✅ Arrived!'; st.className = 'badge badge-safe'; }
        if (btn) btn.disabled = false;
        if (typeof showToast === 'function') showToast('🤖 MedBot arrived at destination!', 'success');
    }

    function init() {
        canvas = document.getElementById('map-canvas');
        if (!canvas) return;

        function resize() {
            const container = canvas.parentElement;
            canvas.width  = container.clientWidth;
            canvas.height = container.clientHeight;
        }
        resize();
        ctx = canvas.getContext('2d');
        initWalls();
        robotPos = { c: LOCATIONS.pharmacy.col, r: LOCATIONS.pharmacy.r };
        startAnimLoop(); // start continuous animation loop (handles pulse + dash animation)

        window.addEventListener('resize', () => { resize(); });

        // Click to toggle user obstacles
        canvas.addEventListener('click', e => {
            const rect = canvas.getBoundingClientRect();
            const scaleX = canvas.width / rect.width;
            const scaleY = canvas.height / rect.height;
            const c = Math.floor(((e.clientX - rect.left) * scaleX) / CELL);
            const r = Math.floor(((e.clientY - rect.top)  * scaleY) / CELL);
            const k = `${c},${r}`;
            if (!WALLS.has(k) && !isLocationCell(c, r)) {
                if (userObstacles.has(k)) userObstacles.delete(k);
                else userObstacles.add(k);
                const obsEl = document.getElementById('stat-obs');
                if (obsEl) obsEl.textContent = userObstacles.size;
            }
        });
    }

    function isLocationCell(c, r) {
        return Object.values(LOCATIONS).some(l => l.col === c && l.r === r);
    }

    function resolveRoom(key) {
        if (LOCATIONS[key]) return LOCATIONS[key];
        const m = key.match(/room_(\d+)/);
        if (m) {
            const id = parseInt(m[1]);
            return LOCATIONS[`room_${id}`] || LOCATIONS['room_101'];
        }
        return LOCATIONS.pharmacy;
    }

    return {
        findPath() {
            const fromKey = document.getElementById('from-room')?.value || 'pharmacy';
            const toKey   = document.getElementById('to-room')?.value || '';
            if (!toKey) {
                if (typeof showToast === 'function') showToast('Please select a destination room', 'warning');
                return;
            }
            const fromLoc = resolveRoom(fromKey);
            const toLoc   = resolveRoom(toKey);
            robotPos    = { c: fromLoc.col, r: fromLoc.r };
            robotAnimPos = null;

            const st = document.getElementById('path-status');
            if (st) { st.textContent = '🔍 Computing...'; st.className = 'badge badge-warning'; }

            const result = aStar({ c: fromLoc.col, r: fromLoc.r }, { c: toLoc.col, r: toLoc.r });

            if (!result) {
                if (st) { st.textContent = '❌ No path'; st.className = 'badge badge-danger'; }
                if (typeof showToast === 'function') showToast('No path found! Try removing some obstacles.', 'error');
                return;
            }
            path = result;

            const dist    = result.length - 1;
            const estTime = (dist * 0.5).toFixed(1);
            const stDist  = document.getElementById('stat-dist');
            const stSteps = document.getElementById('stat-steps');
            const stTime  = document.getElementById('stat-time');
            const stBadge = document.getElementById('path-distance');
            if (stDist)  stDist.textContent  = `${dist} cells`;
            if (stSteps) stSteps.textContent = dist;
            if (stTime)  stTime.textContent  = `~${estTime}s`;
            if (stBadge) { stBadge.textContent = `${dist} steps`; stBadge.style.display = ''; }
            if (st) { st.textContent = '✅ Path ready'; st.className = 'badge badge-safe'; }

            const btn = document.getElementById('animate-btn');
            if (btn) btn.disabled = false;

            if (typeof showToast === 'function') showToast(`✅ Path found: ${dist} steps (~${estTime}s)`, 'success');
        },

        animateRobot() { animateRobotAlongPath(); },

        resetMap() {
            path = [];
            animating = false;
            robotAnimPos = null;
            robotPos = { c: LOCATIONS.pharmacy.col, r: LOCATIONS.pharmacy.r };
            userObstacles.clear();
            const st = document.getElementById('path-status');
            if (st) { st.textContent = 'Ready'; st.className = 'badge badge-info'; }
            const btn = document.getElementById('animate-btn');
            if (btn) btn.disabled = true;
            ['stat-dist','stat-steps','stat-time'].forEach(id => { const el = document.getElementById(id); if (el) el.textContent = '—'; });
            const obsEl = document.getElementById('stat-obs');
            if (obsEl) obsEl.textContent = '0';
            const stBadge = document.getElementById('path-distance');
            if (stBadge) stBadge.style.display = 'none';
        }
    };
})();

function findPath()    { mapEngine.findPath(); }
function animateRobot(){ mapEngine.animateRobot(); }
function resetMap()    { mapEngine.resetMap(); }

document.addEventListener('DOMContentLoaded', () => {
    setTimeout(() => {
        mapEngine.resetMap();
    }, 200);
});
