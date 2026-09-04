// MedBot Voice Interface — Web Speech API
const voiceBot = (() => {
    let recognition = null;
    let listening = false;

    function isSupported() {
        return 'webkitSpeechRecognition' in window || 'SpeechRecognition' in window;
    }

    function speak(text) {
        const utter = new SpeechSynthesisUtterance(text);
        utter.rate = 0.95;
        utter.pitch = 1.0;
        utter.volume = 1;
        const voices = speechSynthesis.getVoices();
        const preferred = voices.find(v => v.name.includes('Google') && v.lang === 'en-US') ||
                          voices.find(v => v.lang === 'en-US') || voices[0];
        if (preferred) utter.voice = preferred;
        speechSynthesis.speak(utter);
    }

    async function answerQuestion(question) {
        const q = question.toLowerCase();
        const bubble = document.getElementById('voice-bubble');
        const heard = document.getElementById('voice-heard');
        const answer = document.getElementById('voice-answer');

        if (bubble) { bubble.classList.add('show'); }
        if (heard) heard.textContent = question;
        if (answer) answer.textContent = '🤖 Thinking...';

        let response = '';

        // Fetch today's schedule for context
        try {
            const res = await fetch('/medbot/api/schedule.php');
            const data = await res.json();
            const schedule = data.schedule || [];
            const now = new Date();
            const nowMin = now.getHours() * 60 + now.getMinutes();

            if (q.includes('take') || q.includes('taken') || q.includes('did i')) {
                const taken = schedule.filter(d => ['taken','dispensed'].includes(d.status));
                const pending = schedule.filter(d => d.status === 'pending');
                const missed = schedule.filter(d => d.status === 'missed');
                if (taken.length > 0) {
                    const names = [...new Set(taken.map(d => d.medication_name))].join(', ');
                    response = `You have taken ${taken.length} dose${taken.length>1?'s':''} today: ${names}.`;
                    if (pending.length > 0) response += ` You still have ${pending.length} dose${pending.length>1?'s':''} pending.`;
                    if (missed.length > 0) response += ` Warning: ${missed.length} dose${missed.length>1?'s':''} were missed!`;
                } else {
                    response = 'No doses have been taken or dispensed yet today.';
                }
            } else if (q.includes('next dose') || q.includes('when') || q.includes('next medication')) {
                const pending = schedule.filter(d => d.status === 'pending');
                if (pending.length > 0) {
                    const next = pending[0];
                    const parts = next.scheduled_time.split(':');
                    const doseMin = parseInt(parts[0]) * 60 + parseInt(parts[1]);
                    const diffMin = doseMin - nowMin;
                    const timeStr = `${parts[0]}:${parts[1]}`;
                    if (diffMin > 0) {
                        const hrs = Math.floor(diffMin / 60), mins = diffMin % 60;
                        const timeLeft = hrs > 0 ? `${hrs} hour${hrs>1?'s':''} and ${mins} minutes` : `${mins} minutes`;
                        response = `Your next dose is ${next.medication_name} ${next.dosage_mg}mg for patient ${next.patient_name}, scheduled at ${timeStr} — in ${timeLeft}.`;
                    } else {
                        response = `${next.medication_name} was scheduled at ${timeStr} and is now overdue!`;
                    }
                } else {
                    response = 'No more pending doses scheduled for today. All done!';
                }
            } else if (q.includes('missed') || q.includes('skip')) {
                const missed = schedule.filter(d => d.status === 'missed');
                if (missed.length > 0) {
                    const names = missed.map(d => `${d.medication_name} at ${d.scheduled_time.substring(0,5)}`).join(', ');
                    response = `There are ${missed.length} missed dose${missed.length>1?'s':''} today: ${names}. Please notify the care team.`;
                } else {
                    response = 'Great news! No missed doses today.';
                }
            } else if (q.includes('how many') || q.includes('medications') || q.includes('prescription')) {
                const meds = [...new Set(schedule.map(d => d.medication_name))];
                if (meds.length > 0) {
                    response = `Today's schedule includes ${schedule.length} dose${schedule.length>1?'s':''} of: ${meds.join(', ')}.`;
                } else {
                    response = 'No medications are scheduled for today.';
                }
            } else if (q.includes('status') || q.includes('robot')) {
                try {
                    const stRes = await fetch('/medbot/api/robot_action.php?action=status');
                    const st = await stRes.json();
                    const action = st.state?.current_action || 'idle';
                    const loc = st.state?.current_location || 'pharmacy';
                    response = `MedBot is currently ${action === 'idle' ? 'idle and ready' : action} at ${loc}.`;
                } catch(e) { response = 'MedBot status is unavailable right now.'; }
            } else if (q.includes('alert') || q.includes('interaction') || q.includes('warning')) {
                try {
                    const alRes = await fetch('/medbot/api/alerts.php');
                    const al = await alRes.json();
                    if (al.count > 0) {
                        response = `There are ${al.count} unresolved drug interaction alert${al.count>1?'s':''}. Please check the alerts page immediately.`;
                    } else {
                        response = 'No active drug interaction alerts. All medications appear safe.';
                    }
                } catch(e) { response = 'Could not check alerts right now.'; }
            } else if (q.includes('hello') || q.includes('hi') || q.includes('hey')) {
                response = 'Hello! I am MedBot, your AI medication management assistant. You can ask me about medications, doses, schedules, or robot status.';
            } else if (q.includes('help') || q.includes('what can you')) {
                response = 'You can ask me: Did I take my medication? When is the next dose? Are there any missed doses? How many medications today? What is the robot status? Are there any drug interaction alerts?';
            } else {
                response = `I heard: "${question}". You can ask me about medication schedules, missed doses, next doses, drug alerts, or robot status.`;
            }
        } catch(e) {
            response = 'Sorry, I could not fetch medication data right now. Please check your connection.';
        }

        if (answer) answer.textContent = '🤖 ' + response;
        speak(response);

        // Auto-hide after 8 seconds
        setTimeout(() => {
            if (bubble) bubble.classList.remove('show');
        }, 8000);
    }

    function startListening() {
        if (!isSupported()) {
            showToast('Voice not supported in this browser. Use Chrome.', 'warning');
            return;
        }
        const SR = window.webkitSpeechRecognition || window.SpeechRecognition;
        recognition = new SR();
        recognition.lang = 'en-US';
        recognition.interimResults = false;
        recognition.maxAlternatives = 1;

        recognition.onstart = () => {
            listening = true;
            const btn = document.getElementById('voice-btn');
            if (btn) { btn.classList.add('listening'); btn.textContent = '🔴'; }
            showToast('🎙️ Listening... speak now!', 'info', 3000);
        };

        recognition.onresult = (event) => {
            const transcript = event.results[0][0].transcript;
            answerQuestion(transcript);
        };

        recognition.onerror = (event) => {
            showToast('Voice error: ' + event.error, 'warning');
            stopListening();
        };

        recognition.onend = () => stopListening();
        recognition.start();
    }

    function stopListening() {
        listening = false;
        const btn = document.getElementById('voice-btn');
        if (btn) { btn.classList.remove('listening'); btn.textContent = '🎙️'; }
        if (recognition) { recognition.stop(); recognition = null; }
    }

    return {
        start: startListening,
        stop: stopListening,
        toggle() { listening ? stopListening() : startListening(); }
    };
})();

function toggleVoice() { voiceBot.toggle(); }
