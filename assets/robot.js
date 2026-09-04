// MedBot 3D Robot Arm — Three.js Scene
const robot3D = (() => {
    let scene, camera, renderer, clock;
    let base, shoulder, upperArm, elbow, foreArm, wristGroup, gripper1, gripper2;
    let bottles = [], dispenserTray;
    let autoRotate = false;
    let isAnimating = false;
    let gripperOpen = true;

    // Joint angles (radians)
    const joints = { base: 0, shoulder: 0.4, elbow: -0.6, wrist: 0 };

    function init() {
        const container = document.getElementById('robot-canvas-container');
        if (!container) return;
        const W = container.clientWidth, H = container.clientHeight;

        // Scene
        scene = new THREE.Scene();
        scene.background = new THREE.Color(0x050810);
        scene.fog = new THREE.FogExp2(0x050810, 0.04);

        // Camera
        camera = new THREE.PerspectiveCamera(45, W / H, 0.1, 200);
        camera.position.set(6, 5, 8);
        camera.lookAt(0, 2, 0);

        // Renderer
        renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
        renderer.setSize(W, H);
        renderer.setPixelRatio(window.devicePixelRatio);
        renderer.shadowMap.enabled = true;
        renderer.shadowMap.type = THREE.PCFSoftShadowMap;
        renderer.toneMapping = THREE.ACESFilmicToneMapping;
        renderer.toneMappingExposure = 1.2;
        container.appendChild(renderer.domElement);

        clock = new THREE.Clock();

        // Lighting
        const ambient = new THREE.AmbientLight(0x1a1a2e, 2);
        scene.add(ambient);

        const dirLight = new THREE.DirectionalLight(0x6c63ff, 3);
        dirLight.position.set(5, 10, 5);
        dirLight.castShadow = true;
        dirLight.shadow.mapSize.set(2048, 2048);
        scene.add(dirLight);

        const tealLight = new THREE.PointLight(0x00d4aa, 4, 15);
        tealLight.position.set(-3, 4, 0);
        scene.add(tealLight);

        const fillLight = new THREE.PointLight(0x4466ff, 2, 20);
        fillLight.position.set(0, 8, -5);
        scene.add(fillLight);

        // Grid floor
        const gridHelper = new THREE.GridHelper(20, 20, 0x1a1f35, 0x111827);
        scene.add(gridHelper);

        // Floor
        const floorGeo = new THREE.PlaneGeometry(20, 20);
        const floorMat = new THREE.MeshStandardMaterial({ color: 0x080c18, roughness: 0.9, metalness: 0.1 });
        const floor = new THREE.Mesh(floorGeo, floorMat);
        floor.rotation.x = -Math.PI / 2;
        floor.receiveShadow = true;
        scene.add(floor);

        buildRobot();
        buildEnvironment();
        buildParticles();

        // Mouse orbit (simple)
        let mouseDown = false, lastX = 0, camTheta = Math.atan2(8, 6), camPhi = 0.5, camR = 10;
        renderer.domElement.addEventListener('mousedown', e => { mouseDown = true; lastX = e.clientX; });
        window.addEventListener('mouseup', () => mouseDown = false);
        window.addEventListener('mousemove', e => {
            if (!mouseDown) return;
            camTheta -= (e.clientX - lastX) * 0.01;
            lastX = e.clientX;
            camera.position.set(
                camR * Math.sin(camTheta) * Math.cos(camPhi),
                camR * Math.sin(camPhi) + 2,
                camR * Math.cos(camTheta) * Math.cos(camPhi)
            );
            camera.lookAt(0, 2, 0);
        });
        renderer.domElement.addEventListener('wheel', e => {
            camR = Math.max(4, Math.min(18, camR + e.deltaY * 0.02));
            camera.position.normalize().multiplyScalar(camR);
            camera.position.y = camR * Math.sin(camPhi) + 2;
            camera.lookAt(0, 2, 0);
        });

        window.addEventListener('resize', () => {
            const w = container.clientWidth, h = container.clientHeight;
            camera.aspect = w / h;
            camera.updateProjectionMatrix();
            renderer.setSize(w, h);
        });

        animate();
    }

    function makeMat(color, rough = 0.3, metal = 0.7, emissive = 0, emissiveColor = 0x000000) {
        return new THREE.MeshStandardMaterial({ color, roughness: rough, metalness: metal, emissive: emissiveColor, emissiveIntensity: emissive });
    }

    function buildRobot() {
        const robotMat = makeMat(0x1e293b, 0.2, 0.8);
        const accentMat = makeMat(0x6c63ff, 0.3, 0.6, 0.4, 0x6c63ff);
        const tealMat = makeMat(0x00d4aa, 0.3, 0.5, 0.6, 0x00d4aa);
        const jointMat = makeMat(0x334155, 0.4, 0.9);

        // Base platform
        const basePlatGeo = new THREE.CylinderGeometry(1.2, 1.4, 0.3, 32);
        const basePlat = new THREE.Mesh(basePlatGeo, robotMat);
        basePlat.position.y = 0.15;
        basePlat.castShadow = true;
        scene.add(basePlat);

        // Base rotation group
        base = new THREE.Group();
        base.position.y = 0.3;
        scene.add(base);

        // Base column
        const baseColGeo = new THREE.CylinderGeometry(0.4, 0.5, 1.2, 16);
        const baseCol = new THREE.Mesh(baseColGeo, robotMat);
        baseCol.position.y = 0.6;
        baseCol.castShadow = true;
        base.add(baseCol);

        // Accent ring
        const ringGeo = new THREE.TorusGeometry(0.45, 0.05, 8, 32);
        const ring = new THREE.Mesh(ringGeo, accentMat);
        ring.position.y = 1.0;
        ring.rotation.x = Math.PI / 2;
        base.add(ring);

        // Shoulder group
        shoulder = new THREE.Group();
        shoulder.position.y = 1.3;
        base.add(shoulder);

        // Shoulder joint sphere
        const shoulderSphere = new THREE.Mesh(new THREE.SphereGeometry(0.28, 16, 16), jointMat);
        shoulder.add(shoulderSphere);

        // Upper arm
        upperArm = new THREE.Group();
        upperArm.position.y = 0.1;
        shoulder.add(upperArm);

        const uaGeo = new THREE.BoxGeometry(0.22, 1.6, 0.22);
        const ua = new THREE.Mesh(uaGeo, robotMat);
        ua.position.y = 0.8;
        ua.castShadow = true;
        upperArm.add(ua);

        // Accent stripe on upper arm
        const stripeGeo = new THREE.BoxGeometry(0.24, 0.1, 0.24);
        const stripe = new THREE.Mesh(stripeGeo, accentMat);
        stripe.position.y = 0.8;
        upperArm.add(stripe);

        // Elbow group
        elbow = new THREE.Group();
        elbow.position.y = 1.6;
        upperArm.add(elbow);

        const elbowSphere = new THREE.Mesh(new THREE.SphereGeometry(0.22, 16, 16), jointMat);
        elbow.add(elbowSphere);

        // Forearm
        foreArm = new THREE.Group();
        elbow.add(foreArm);

        const faGeo = new THREE.BoxGeometry(0.18, 1.2, 0.18);
        const fa = new THREE.Mesh(faGeo, robotMat);
        fa.position.y = 0.6;
        fa.castShadow = true;
        foreArm.add(fa);

        // Wrist
        wristGroup = new THREE.Group();
        wristGroup.position.y = 1.2;
        foreArm.add(wristGroup);

        const wristGeo = new THREE.CylinderGeometry(0.12, 0.12, 0.2, 12);
        const wrist = new THREE.Mesh(wristGeo, tealMat);
        wristGroup.add(wrist);

        // Gripper fingers
        gripper1 = new THREE.Group();
        gripper1.position.set(0.1, 0.2, 0);
        wristGroup.add(gripper1);

        const fingerGeo = new THREE.BoxGeometry(0.08, 0.35, 0.06);
        const f1 = new THREE.Mesh(fingerGeo, tealMat);
        f1.position.y = 0.18;
        gripper1.add(f1);
        const tip1 = new THREE.Mesh(new THREE.SphereGeometry(0.05, 8, 8), accentMat);
        tip1.position.y = 0.36;
        gripper1.add(tip1);

        gripper2 = new THREE.Group();
        gripper2.position.set(-0.1, 0.2, 0);
        wristGroup.add(gripper2);

        const f2 = new THREE.Mesh(fingerGeo, tealMat);
        f2.position.y = 0.18;
        gripper2.add(f2);
        const tip2 = new THREE.Mesh(new THREE.SphereGeometry(0.05, 8, 8), accentMat);
        tip2.position.y = 0.36;
        gripper2.add(tip2);

        updateJoints();
    }

    function buildEnvironment() {
        // Shelf for pill bottles
        const shelfMat = makeMat(0x0f172a, 0.8, 0.2);
        const shelfGeo = new THREE.BoxGeometry(3, 0.08, 0.8);
        const shelf = new THREE.Mesh(shelfGeo, shelfMat);
        shelf.position.set(-3.5, 2.5, 0);
        scene.add(shelf);

        // Back wall of shelf
        const wallGeo = new THREE.BoxGeometry(3, 1.5, 0.06);
        const wall = new THREE.Mesh(wallGeo, makeMat(0x0d1117, 0.9, 0.1));
        wall.position.set(-3.5, 3.2, -0.4);
        scene.add(wall);

        // Pill bottles
        const bottleColors = [0x6c63ff, 0x00d4aa, 0xff6b9d, 0xffa94d, 0x45b7d1, 0x96ceb4];
        const bottleLabels = ['MED-A', 'MED-B', 'MED-C', 'MED-D', 'MED-E', 'MED-F'];
        for (let i = 0; i < 6; i++) {
            const bottleGroup = new THREE.Group();
            const bodyGeo = new THREE.CylinderGeometry(0.12, 0.12, 0.5, 16);
            const body = new THREE.Mesh(bodyGeo, makeMat(bottleColors[i], 0.3, 0.3, 0.3, bottleColors[i]));
            body.castShadow = true;
            bottleGroup.add(body);
            const capGeo = new THREE.CylinderGeometry(0.13, 0.13, 0.1, 16);
            const cap = new THREE.Mesh(capGeo, makeMat(0xffffff, 0.5, 0.1));
            cap.position.y = 0.3;
            bottleGroup.add(cap);
            bottleGroup.position.set(-4.8 + i * 0.52, 2.8, 0);
            bottleGroup.userData.homePos = bottleGroup.position.clone();
            bottleGroup.userData.name = bottleLabels[i];
            scene.add(bottleGroup);
            bottles.push(bottleGroup);
        }

        // Dispenser tray
        const trayGeo = new THREE.BoxGeometry(1.2, 0.1, 0.8);
        dispenserTray = new THREE.Mesh(trayGeo, makeMat(0x1e3a5f, 0.4, 0.6, 0.2, 0x00d4aa));
        dispenserTray.position.set(2, 0.8, 0);
        dispenserTray.receiveShadow = true;
        scene.add(dispenserTray);

        const trayLabel = new THREE.Mesh(
            new THREE.BoxGeometry(1.0, 0.02, 0.6),
            makeMat(0x00d4aa, 0.5, 0.3, 0.5, 0x00d4aa)
        );
        trayLabel.position.set(2, 0.86, 0);
        scene.add(trayLabel);

        // Robot base glow ring on floor
        const glowGeo = new THREE.RingGeometry(1.3, 1.5, 64);
        const glowMat = new THREE.MeshBasicMaterial({ color: 0x6c63ff, transparent: true, opacity: 0.3, side: THREE.DoubleSide });
        const glow = new THREE.Mesh(glowGeo, glowMat);
        glow.rotation.x = -Math.PI / 2;
        glow.position.y = 0.01;
        scene.add(glow);
    }

    function buildParticles() {
        const count = 80;
        const geo = new THREE.BufferGeometry();
        const pos = new Float32Array(count * 3);
        for (let i = 0; i < count * 3; i++) pos[i] = (Math.random() - 0.5) * 16;
        geo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
        const mat = new THREE.PointsMaterial({ color: 0x6c63ff, size: 0.06, transparent: true, opacity: 0.5 });
        scene.add(new THREE.Points(geo, mat));
    }

    function updateJoints() {
        if (base) base.rotation.y = joints.base;
        if (shoulder) shoulder.rotation.z = joints.shoulder;
        if (elbow) elbow.rotation.z = joints.elbow;
        if (wristGroup) wristGroup.rotation.z = joints.wrist;

        // Update UI
        const d = v => Math.round(v * 180 / Math.PI);
        const el1 = document.getElementById('joint-1');
        const el2 = document.getElementById('joint-2');
        const elG = document.getElementById('gripper-state');
        if (el1) el1.textContent = d(joints.base) + '°';
        if (el2) el2.textContent = d(joints.shoulder) + '°';
        if (elG) elG.textContent = gripperOpen ? 'Open' : 'Closed';
    }

    function animateTo(target, duration = 1200) {
        return new Promise(resolve => {
            const start = { ...joints };
            const startTime = performance.now();
            function step(now) {
                const t = Math.min((now - startTime) / duration, 1);
                const ease = t < 0.5 ? 2 * t * t : -1 + (4 - 2 * t) * t; // easeInOut
                for (const k in target) joints[k] = start[k] + (target[k] - start[k]) * ease;
                updateJoints();
                if (t < 1) requestAnimationFrame(step); else resolve();
            }
            requestAnimationFrame(step);
        });
    }

    function setGripper(open, duration = 400) {
        return new Promise(resolve => {
            const targetX = open ? 0.1 : 0.04;
            const startX1 = gripper1.position.x, startX2 = gripper2.position.x;
            const startTime = performance.now();
            gripperOpen = open;
            function step(now) {
                const t = Math.min((now - startTime) / duration, 1);
                gripper1.position.x = startX1 + (targetX - startX1) * t;
                gripper2.position.x = startX2 + (-targetX - startX2) * t;
                updateJoints();
                if (t < 1) requestAnimationFrame(step); else resolve();
            }
            requestAnimationFrame(step);
        });
    }

    function animate() {
        requestAnimationFrame(animate);
        const t = clock.getElapsedTime();
        if (autoRotate) base.rotation.y += 0.005;
        // Subtle breathing effect
        if (!isAnimating && shoulder) {
            shoulder.rotation.z = joints.shoulder + Math.sin(t * 0.8) * 0.01;
        }
        renderer.render(scene, camera);
    }

    // Public API
    const api = {
        rotateBase(delta) { joints.base += delta; updateJoints(); },
        moveShoulder(delta) { joints.shoulder = Math.max(-0.8, Math.min(1.2, joints.shoulder + delta)); updateJoints(); },
        moveElbow(delta) { joints.elbow = Math.max(-1.5, Math.min(0.5, joints.elbow + delta)); updateJoints(); },
        toggleGripper() { setGripper(!gripperOpen); },
        toggleAutoRotate() { autoRotate = !autoRotate; },
        goHome() {
            animateTo({ base: 0, shoulder: 0.4, elbow: -0.6, wrist: 0 });
            setGripper(true);
        },
        async dispenseMedication(bottleIdx = 0, medName = 'Medication') {
            if (isAnimating) return;
            isAnimating = true;
            const bottle = bottles[bottleIdx % bottles.length];
            const status = document.getElementById('arm-status');
            const pos = document.getElementById('arm-pos');
            const updateStatus = (s, p) => { if (status) status.textContent = s; if (pos) pos.textContent = p; };

            updateStatus('🦾 REACHING', `Bottle ${bottleIdx + 1}`);
            // Face the shelf
            const angle = -Math.PI / 2 - (bottleIdx - 2.5) * 0.15;
            await animateTo({ base: angle, shoulder: 0.7, elbow: -0.3, wrist: 0.2 }, 1000);
            // Open gripper
            await setGripper(true);
            updateStatus('🦾 GRIPPING', medName);
            // Lower to pick
            await animateTo({ base: angle, shoulder: 0.9, elbow: -0.1, wrist: 0 }, 800);
            // Close gripper
            await setGripper(false, 500);
            // Lift
            updateStatus('🦾 LIFTING', medName);
            await animateTo({ base: angle, shoulder: 0.5, elbow: -0.5, wrist: 0 }, 700);
            // Rotate to tray
            updateStatus('🦾 MOVING', 'To dispenser tray');
            await animateTo({ base: Math.PI / 3, shoulder: 0.3, elbow: -0.8, wrist: 0.3 }, 1200);
            // Lower to place
            await animateTo({ base: Math.PI / 3, shoulder: 0.5, elbow: -0.6, wrist: 0 }, 700);
            updateStatus('📦 PLACING', 'Dispenser tray');
            // Release
            await setGripper(true, 500);
            updateStatus('✅ DONE', medName + ' dispensed');
            // Return home
            await animateTo({ base: 0, shoulder: 0.4, elbow: -0.6, wrist: 0 }, 1000);
            updateStatus('🦾 IDLE', 'Home position');
            isAnimating = false;
        },
        async demoSequence() {
            if (isAnimating) return;
            showToast('▶ Running demo sequence...', 'info');
            for (let i = 0; i < Math.min(3, bottles.length); i++) {
                await api.dispenseMedication(i, bottles[i].userData.name);
                await new Promise(r => setTimeout(r, 400));
            }
            showToast('✅ Demo sequence complete!', 'success');
        }
    };

    document.addEventListener('DOMContentLoaded', () => setTimeout(init, 100));
    return api;
})();
