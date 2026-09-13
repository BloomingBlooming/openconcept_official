(() => {
    'use strict';

    const PLUGIN_ID = 'ai-search-voice';
    const t = (key, parameters = {}) => window.OpenConceptI18n?.t(PLUGIN_ID, key, parameters, key) || key;
    const RATE_OPTIONS = [
        { value: 3, labelKey: 'settings.rate.fast' },
        { value: 2.5, labelKey: 'settings.rate.mediumFast' },
        { value: 2, labelKey: 'settings.rate.normal' },
        { value: 1.75, labelKey: 'settings.rate.slow' },
    ];
    const SILENCE_OPTIONS = [
        { value: 4, labelKey: 'settings.silence.short' },
        { value: 6, labelKey: 'settings.silence.normal' },
        { value: 8, labelKey: 'settings.silence.long' },
    ];
    const STYLE_OPTIONS = [
        ['ring', 'settings.style.ring'],
        ['wave', 'settings.style.wave'],
        ['orb', 'settings.style.orb'],
        ['pulse', 'settings.style.pulse'],
        ['spectrum', 'settings.style.spectrum'],
    ];
    const LAYOUT_OPTIONS = [
        ['stacked', 'settings.layout.stacked'],
        ['columns', 'settings.layout.columns'],
        ['chat', 'settings.layout.chat'],
        ['visualizer', 'settings.layout.visualizer'],
    ];
    const SPEECH_END_SILENCE_MS = 1200;
    const SPEECH_CONFIRM_MS = 120;
    const MICROPHONE_PREWARM_MS = 1800;
    const ECHO_MARGIN_MS = 140;
    const VAD_ECHO_GUARD_MS = 80;
    const MAX_TIMING_LOGS = 50;
    const csrf = () => extensionContext?.csrf?.() || window.OPENCONCEPT_BOOT?.csrf || '';

    let extensionContext = null;
    let root = null;
    let refs = {};
    let mountedConversationId = 0;
    let settingsOpen = false;
    let lifecycleEpoch = 0;
    let generating = false;
    let transcribing = false;
    let activeSession = null;
    let sessionSequence = 0;
    let mediaStream = null;
    let microphonePromise = null;
    let microphoneRequestSerial = 0;
    let audioContext = null;
    let analyser = null;
    let vadPromise = null;
    let transcriptionAbort = null;
    let submissionAbort = null;
    let automaticTimer = 0;
    let prewarmTimer = 0;
    let prewarmed = false;
    let currentUtterance = null;
    let currentSpeechMeta = null;
    let speechActive = false;
    let visualizerFrame = 0;
    let visualizerMode = 'idle';
    let audioLevel = 0;
    let boundaryPulse = 0;
    let statusTitle = t('status.ready');
    let statusDetail = t('status.readyHelp');
    let transitionSequence = 0;
    let pendingTransition = null;
    const timingLogs = [];

    let ttsEnabled = stored('tts', 'on') !== 'off';
    let handsFree = stored('hands-free', 'off') === 'on';
    let speechRate = numberSetting('rate', RATE_OPTIONS.map(item => item.value), 2);
    let silenceSeconds = numberSetting('silence', SILENCE_OPTIONS.map(item => item.value), 6);
    let visualizerStyle = optionSetting('visualizer', STYLE_OPTIONS.map(item => item[0]), 'ring');
    let visualizerLayout = optionSetting('layout', LAYOUT_OPTIONS.map(item => item[0]), 'stacked');
    let selectedVoiceKey = stored('voice', '');
    if (visualizerLayout === 'text') visualizerLayout = 'chat';

    function stored(name, fallback) {
        try { return localStorage.getItem(`openconcept.ai-search-voice.${name}`) ?? fallback; }
        catch (_) { return fallback; }
    }

    function persist(name, value) {
        try { localStorage.setItem(`openconcept.ai-search-voice.${name}`, String(value)); }
        catch (_) { /* Keep the setting for this page session. */ }
    }

    function numberSetting(name, allowed, fallback) {
        const value = Number(stored(name, String(fallback)));
        return allowed.includes(value) ? value : fallback;
    }

    function optionSetting(name, allowed, fallback) {
        const value = stored(name, fallback);
        return allowed.includes(value) ? value : fallback;
    }

    function voiceKey(voice) {
        return JSON.stringify([voice.voiceURI || '', voice.name || '', voice.lang || '']);
    }

    function currentSpeechLocale() {
        return window.OpenConceptI18n?.locale() || document.documentElement.lang || navigator.language || 'en-US';
    }

    function compatibleVoices() {
        if (!('speechSynthesis' in window)) return [];
        const locale = currentSpeechLocale();
        const language = locale.toLowerCase().split('-')[0];
        return window.speechSynthesis.getVoices()
            .filter(voice => String(voice.lang || '').toLowerCase().split('-')[0] === language)
            .sort((left, right) => Number(right.default) - Number(left.default) || String(left.name).localeCompare(String(right.name), locale));
    }

    function selectedVoice() {
        const voices = compatibleVoices();
        return voices.find(voice => voiceKey(voice) === selectedVoiceKey)
            || voices.find(voice => voice.default)
            || voices[0]
            || null;
    }

    function renderVoiceOptions() {
        if (!refs.voice) return;
        const voices = compatibleVoices();
        refs.voice.replaceChildren();
        const standard = new Option(voices.length ? t('settings.voiceDefault') : t('settings.voiceUnavailable'), '');
        refs.voice.appendChild(standard);
        voices.forEach(voice => refs.voice.appendChild(new Option(voice.name || voice.voiceURI || voice.lang, voiceKey(voice))));
        refs.voice.value = voices.some(voice => voiceKey(voice) === selectedVoiceKey) ? selectedVoiceKey : '';
        refs.voice.disabled = voices.length === 0;
    }

    function mount(context) {
        const nextConversationId = Number(context.conversationId || 0);
        if (mountedConversationId !== nextConversationId && extensionContext !== null) {
            resetLifecycle('conversation-changed');
        }
        mountedConversationId = nextConversationId;
        extensionContext = context;
        context.host.replaceChildren();
        root = document.createElement('section');
        root.className = 'ai-search-voice';
        root.dataset.mode = visualizerMode;
        root.innerHTML = `
            <div class="ai-search-voice-toolbar">
                <button class="ai-search-voice-mic" type="button" data-voice-action="record" aria-label="${t('input.startRecording')}">🎙️</button>
                <div class="ai-search-voice-stage">
                    <div class="ai-search-voice-canvas-wrap"><canvas aria-hidden="true"></canvas><span>AI</span></div>
                    <div class="ai-search-voice-status"><strong></strong><span></span></div>
                </div>
                <div class="ai-search-voice-actions">
                    <button type="button" data-voice-action="toggle-tts" aria-label="${t('settings.ttsToggle')}" aria-pressed="${ttsEnabled}">${ttsEnabled ? '🔊' : '🔇'}</button>
                    <button class="ai-search-voice-auto" type="button" data-voice-action="toggle-hands-free" aria-label="${t('settings.autoMic')}" aria-pressed="${handsFree}"><span>${t('settings.autoMic')}</span><i></i></button>
                    <button type="button" data-voice-action="settings" aria-label="${t('settings.open')}" aria-expanded="${settingsOpen}">⚙</button>
                </div>
            </div>
            <div class="ai-search-voice-settings ${settingsOpen ? 'is-open' : ''}">
                <label><span>${t('settings.layout')}</span><select data-setting="layout" aria-label="${t('settings.layoutLabel')}">${LAYOUT_OPTIONS.map(([value, labelKey]) => `<option value="${value}" ${value === visualizerLayout ? 'selected' : ''}>${t(labelKey)}</option>`).join('')}</select></label>
                <label><span>${t('settings.display')}</span><select data-setting="style" aria-label="${t('settings.styleLabel')}">${STYLE_OPTIONS.map(([value, labelKey]) => `<option value="${value}" ${value === visualizerStyle ? 'selected' : ''}>${t(labelKey)}</option>`).join('')}</select></label>
                <label><span>${t('settings.voice')}</span><select data-setting="voice" aria-label="${t('settings.voiceLabel')}"><option value="">${t('settings.voiceLoading')}</option></select></label>
                <label><span>${t('settings.rate')}</span><select data-setting="rate" aria-label="${t('settings.rateLabel')}">${RATE_OPTIONS.map(item => `<option value="${item.value}" ${item.value === speechRate ? 'selected' : ''}>${t(item.labelKey)}</option>`).join('')}</select></label>
                <label><span>${t('settings.silence')}</span><select data-setting="silence" aria-label="${t('settings.silenceLabel')}">${SILENCE_OPTIONS.map(item => `<option value="${item.value}" ${item.value === silenceSeconds ? 'selected' : ''}>${t(item.labelKey)}</option>`).join('')}</select></label>
            </div>`;
        context.host.appendChild(root);
        refs = {
            mic: root.querySelector('.ai-search-voice-mic'),
            status: root.querySelector('.ai-search-voice-status strong'),
            detail: root.querySelector('.ai-search-voice-status span'),
            canvas: root.querySelector('canvas'),
            settings: root.querySelector('.ai-search-voice-settings'),
            tts: root.querySelector('[data-voice-action="toggle-tts"]'),
            auto: root.querySelector('[data-voice-action="toggle-hands-free"]'),
            voice: root.querySelector('[data-setting="voice"]'),
        };
        root.addEventListener('click', handleClick);
        root.addEventListener('change', handleSettingChange);
        applyLayout();
        renderVoiceOptions();
        updateStatus();
        updateControls();
        if (!visualizerFrame) visualizerFrame = requestAnimationFrame(drawVisualizer);
    }

    function unmount() {
        resetLifecycle('panel-closed');
        if (extensionContext?.window) delete extensionContext.window.dataset.aiSearchVoiceLayout;
        root?.remove();
        extensionContext = null;
        root = null;
        refs = {};
        if (visualizerFrame) cancelAnimationFrame(visualizerFrame);
        visualizerFrame = 0;
    }

    function applyLayout() {
        if (extensionContext?.window) extensionContext.window.dataset.aiSearchVoiceLayout = visualizerLayout;
        if (root) root.dataset.layout = visualizerLayout;
    }

    async function handleClick(event) {
        const target = event.target.closest('[data-voice-action]');
        if (!target) return;
        const action = target.dataset.voiceAction;
        if (action === 'record') {
            if (activeSession?.recorder.state === 'recording') stopRecording(false, activeSession);
            else await startRecording(false);
        } else if (action === 'toggle-tts') {
            ttsEnabled = !ttsEnabled;
            persist('tts', ttsEnabled ? 'on' : 'off');
            if (!ttsEnabled) {
                const wasActive = speechActive;
                const eventAt = performance.now();
                stopSpeech();
                if (wasActive) maybeStartAutomaticMicrophone(beginTransition('assistant-aborted', eventAt, performance.now(), ECHO_MARGIN_MS));
            }
            if (refs.tts) {
                refs.tts.textContent = ttsEnabled ? '🔊' : '🔇';
                refs.tts.setAttribute('aria-pressed', String(ttsEnabled));
            }
        } else if (action === 'toggle-hands-free') {
            handsFree = !handsFree;
            persist('hands-free', handsFree ? 'on' : 'off');
            refs.auto?.setAttribute('aria-pressed', String(handsFree));
            if (!handsFree) {
                clearTimers();
                stopSpeech();
                if (activeSession?.automatic) stopRecording(true, activeSession);
                else if (prewarmed || microphonePromise) releaseMicrophone();
                setStatus(t('status.autoMicOff'), t('status.autoMicOffHelp'), 'idle');
            } else if (!generating && !speechActive && !activeSession) {
                await startRecording(true);
            } else {
                schedulePrewarm();
            }
        } else if (action === 'settings') {
            settingsOpen = !settingsOpen;
            refs.settings?.classList.toggle('is-open', settingsOpen);
            target.setAttribute('aria-expanded', String(settingsOpen));
        }
    }

    function handleSettingChange(event) {
        const select = event.target.closest('[data-setting]');
        if (!select) return;
        if (select.dataset.setting === 'layout') {
            visualizerLayout = optionSettingValue(select.value, LAYOUT_OPTIONS.map(item => item[0]), visualizerLayout);
            persist('layout', visualizerLayout);
            applyLayout();
        } else if (select.dataset.setting === 'style') {
            visualizerStyle = optionSettingValue(select.value, STYLE_OPTIONS.map(item => item[0]), visualizerStyle);
            persist('visualizer', visualizerStyle);
        } else if (select.dataset.setting === 'voice') {
            selectedVoiceKey = select.value;
            persist('voice', selectedVoiceKey);
        } else if (select.dataset.setting === 'rate') {
            speechRate = numberSettingValue(select.value, RATE_OPTIONS.map(item => item.value), speechRate);
            persist('rate', speechRate);
        } else if (select.dataset.setting === 'silence') {
            silenceSeconds = numberSettingValue(select.value, SILENCE_OPTIONS.map(item => item.value), silenceSeconds);
            persist('silence', silenceSeconds);
        }
        setStatus(t('settings.changed'), t('settings.changedHelp'), visualizerMode === 'error' ? 'idle' : visualizerMode);
    }

    function optionSettingValue(value, allowed, fallback) { return allowed.includes(value) ? value : fallback; }
    function numberSettingValue(value, allowed, fallback) { const number = Number(value); return allowed.includes(number) ? number : fallback; }

    function onSubmitStart() {
        generating = true;
        stopSpeech();
        setStatus(t('status.thinking'), t('status.thinkingHelp'), 'thinking');
        updateControls();
    }

    function onResult(payload) {
        generating = false;
        mountedConversationId = Number(payload.conversation?.id || mountedConversationId || 0);
        updateControls();
        const answer = String(payload.result?.answer || '').trim();
        if (handsFree && ttsEnabled && answer && 'speechSynthesis' in window) {
            speak(answer);
        } else {
            setStatus(t('status.ready'), t('status.completed'), 'idle');
            maybeStartAutomaticMicrophone(beginTransition('assistant-text-complete', performance.now(), performance.now(), 0));
        }
    }

    function onError(payload) {
        generating = false;
        updateControls();
        if (payload.error?.name !== 'AbortError') showError(payload.error?.message || t('error.answer'));
    }

    function setStatus(title, detail, mode) {
        statusTitle = title;
        statusDetail = detail;
        visualizerMode = mode;
        updateStatus();
    }

    function updateStatus() {
        if (!root) return;
        root.dataset.mode = visualizerMode;
        if (refs.status) refs.status.textContent = statusTitle;
        if (refs.detail) refs.detail.textContent = statusDetail;
    }

    function showError(message) {
        setStatus(t('status.needsAttention'), message, 'error');
    }

    function updateControls() {
        if (!refs.mic) return;
        refs.mic.disabled = generating || transcribing;
        refs.mic.classList.toggle('is-recording', Boolean(activeSession));
        refs.mic.textContent = activeSession ? '■' : (transcribing ? '…' : '🎙️');
        refs.mic.setAttribute('aria-label', activeSession ? t('input.stopRecording') : t('input.startRecording'));
    }

    async function startRecording(automatic, transition = null) {
        if (!extensionContext || activeSession || generating || transcribing) return;
        if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
            showError(t('error.recordingUnsupported'));
            return;
        }
        if (automatic && !handsFree) return;
        if (!automatic && speechActive) {
            const eventAt = performance.now();
            stopSpeech();
            transition = beginTransition('assistant-interrupted', eventAt, performance.now(), ECHO_MARGIN_MS);
        }
        const epoch = lifecycleEpoch;
        if (transition) markResumeStart(transition);
        try {
            const [stream, safe] = await Promise.all([prepareMicrophone(epoch), waitEchoMargin(transition, epoch)]);
            if (!stream || !safe || epoch !== lifecycleEpoch || (automatic && !handsFree)) return;
            const mime = selectMime();
            const recorder = mime ? new MediaRecorder(stream, { mimeType: mime }) : new MediaRecorder(stream);
            const now = performance.now();
            const session = {
                id: ++sessionSequence,
                epoch,
                conversationId: mountedConversationId,
                recorder,
                stream: mediaStream,
                audioContext,
                analyser,
                chunks: [],
                mime: recorder.mimeType || mime || 'audio/webm',
                automatic,
                startedAt: now,
                speechDetectionStartsAt: now + VAD_ECHO_GUARD_MS,
                speechCandidateAt: 0,
                lastSpeechAt: now,
                speechDetected: false,
                discard: false,
                vadFrame: 0,
                released: false,
            };
            activeSession = session;
            recorder.addEventListener('dataavailable', event => {
                if (event.data?.size && !session.discard && !session.released) session.chunks.push(event.data);
            });
            recorder.addEventListener('stop', () => handleRecordingStopped(session), { once: true });
            recorder.start(250);
            prewarmed = false;
            markInputReady(transition);
            updateControls();
        setStatus(
            automatic ? t('status.autoListening') : t('status.listening'),
            automatic
                ? t('status.autoListeningHelp', { count: silenceSeconds })
                : t('status.listeningHelp'),
            'listening'
        );
            startVad(session).catch(error => showError(error.message));
        } catch (error) {
            releaseMicrophone();
            if (automatic && error.name === 'NotAllowedError') {
                handsFree = false;
                persist('hands-free', 'off');
            }
            showError(error.name === 'NotAllowedError' ? t('error.microphoneDenied') : error.message);
        }
    }

    async function prepareMicrophone(epoch) {
        const stream = await acquireMicrophone();
        if (!stream || epoch !== lifecycleEpoch) return null;
        await prepareVad();
        return epoch === lifecycleEpoch ? stream : null;
    }

    async function acquireMicrophone() {
        if (mediaStream?.getAudioTracks().some(track => track.readyState === 'live')) return mediaStream;
        if (microphonePromise) return microphonePromise;
        const serial = ++microphoneRequestSerial;
        const promise = navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true }, video: false });
        microphonePromise = promise;
        try {
            const stream = await promise;
            if (serial !== microphoneRequestSerial) {
                stream.getTracks().forEach(track => track.stop());
                return null;
            }
            mediaStream = stream;
            return stream;
        } finally {
            if (microphonePromise === promise) microphonePromise = null;
        }
    }

    async function prepareVad() {
        if (analyser && audioContext?.state !== 'closed') return;
        if (vadPromise) return vadPromise;
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextClass || !mediaStream) return;
        const stream = mediaStream;
        vadPromise = (async () => {
            const context = new AudioContextClass();
            await context.resume();
            if (stream !== mediaStream) return context.close();
            const source = context.createMediaStreamSource(stream);
            const nextAnalyser = context.createAnalyser();
            nextAnalyser.fftSize = 1024;
            nextAnalyser.smoothingTimeConstant = 0.25;
            source.connect(nextAnalyser);
            audioContext = context;
            analyser = nextAnalyser;
        })();
        try { await vadPromise; }
        finally { vadPromise = null; }
    }

    async function startVad(session) {
        await prepareVad();
        if (!session.analyser && analyser) session.analyser = analyser;
        if (!session.analyser || activeSession !== session) return;
        const samples = new Float32Array(session.analyser.fftSize);
        let baseline = 0.008;
        const inspect = now => {
            if (activeSession !== session || session.epoch !== lifecycleEpoch || session.recorder.state !== 'recording') return;
            session.analyser.getFloatTimeDomainData(samples);
            let sum = 0;
            for (const sample of samples) sum += sample * sample;
            const rms = Math.sqrt(sum / samples.length);
            audioLevel += (Math.min(1, rms * 8) - audioLevel) * 0.35;
            const elapsed = now - session.startedAt;
            const threshold = Math.max(0.018, baseline * 2.8);
            const above = now >= session.speechDetectionStartsAt && rms > threshold;
            if (!session.speechDetected && !session.speechCandidateAt && elapsed < 1000 && rms <= threshold) {
                baseline = baseline * 0.92 + rms * 0.08;
            }
            if (above) {
                if (!session.speechCandidateAt) session.speechCandidateAt = now;
                if (session.speechDetected || now - session.speechCandidateAt >= SPEECH_CONFIRM_MS) {
                    session.speechDetected = true;
                    session.lastSpeechAt = now;
                    boundaryPulse = Math.min(1, boundaryPulse + 0.18);
                }
            } else if (!session.speechDetected) {
                session.speechCandidateAt = 0;
            }
            if (session.speechDetected && now - session.lastSpeechAt >= SPEECH_END_SILENCE_MS) return stopRecording(false, session);
            const noSpeechLimit = session.automatic ? silenceSeconds * 1000 : 10000;
            if (elapsed >= 30000 || (!session.speechDetected && elapsed >= noSpeechLimit)) return stopRecording(false, session);
            session.vadFrame = requestAnimationFrame(inspect);
        };
        session.vadFrame = requestAnimationFrame(inspect);
    }

    function stopRecording(discard, session = activeSession) {
        if (!session || session !== activeSession) return;
        session.discard ||= Boolean(discard);
        if (session.recorder.state === 'recording') session.recorder.stop();
    }

    async function handleRecordingStopped(session) {
        const current = activeSession === session && session.epoch === lifecycleEpoch;
        const blob = new Blob(session.chunks, { type: session.mime });
        const detected = session.speechDetected;
        const automatic = session.automatic;
        const discard = session.discard || !current;
        releaseMicrophone(session);
        if (discard) return;
        if (!detected && automatic) return setStatus(t('status.autoStopped'), t('status.autoStoppedHelp', { count: silenceSeconds }), 'idle');
        if (!detected || blob.size < 200) return showError(t('error.noSpeech'));
        transcribing = true;
        updateControls();
        setStatus(t('status.transcribing'), t('status.transcribingHelp'), 'thinking');
        const abort = new AbortController();
        transcriptionAbort?.abort();
        transcriptionAbort = abort;
        try {
            const form = new FormData();
            form.append('audio', blob, recordingFilename(session.mime));
            const response = await fetch('api.php?action=plugin-ai-search-voice-transcribe', {
                method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf() }, body: form, signal: abort.signal,
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(
                response.status === 401 ? t('error.session')
                    : response.status === 403 ? t('error.forbidden')
                        : response.status === 429 ? t('error.rateLimit')
                            : t('error.transcription')
            );
            if (session.epoch !== lifecycleEpoch || session.conversationId !== mountedConversationId) return;
            if (!data.text) throw new Error(t('error.transcription'));
            await submitVoiceText(String(data.text));
        } catch (error) {
            if (error.name !== 'AbortError') showError(error.message);
        } finally {
            if (transcriptionAbort === abort) transcriptionAbort = null;
            transcribing = false;
            updateControls();
        }
    }

    async function submitVoiceText(text) {
        if (!extensionContext?.submit) return;
        const abort = new AbortController();
        submissionAbort?.abort();
        submissionAbort = abort;
        try { await extensionContext.submit(text, { source: 'voice', signal: abort.signal }); }
        finally { if (submissionAbort === abort) submissionAbort = null; }
    }

    function releaseMicrophone(session = activeSession) {
        if (session) {
            session.released = true;
            if (session.vadFrame) cancelAnimationFrame(session.vadFrame);
            session.stream?.getTracks().forEach(track => track.stop());
            session.chunks.length = 0;
        } else {
            mediaStream?.getTracks().forEach(track => track.stop());
        }
        microphoneRequestSerial += 1;
        microphonePromise = null;
        mediaStream = null;
        if (audioContext && audioContext.state !== 'closed') audioContext.close().catch(() => {});
        audioContext = null;
        analyser = null;
        vadPromise = null;
        activeSession = null;
        prewarmed = false;
        audioLevel = 0;
        updateControls();
    }

    function selectMime() {
        return ['audio/webm;codecs=opus', 'audio/mp4', 'audio/ogg;codecs=opus', 'audio/webm']
            .find(type => MediaRecorder.isTypeSupported(type)) || '';
    }

    function recordingFilename(mime) {
        if (mime.includes('mp4')) return 'recording.m4a';
        if (mime.includes('ogg')) return 'recording.ogg';
        return 'recording.webm';
    }

    function speak(text) {
        if (!handsFree || !ttsEnabled || !('speechSynthesis' in window)) return;
        stopSpeech();
        const utterance = new SpeechSynthesisUtterance(text);
        utterance.lang = currentSpeechLocale();
        utterance.rate = speechRate;
        const voice = selectedVoice();
        if (voice) {
            utterance.voice = voice;
            utterance.lang = voice.lang || currentSpeechLocale();
        }
        const epoch = lifecycleEpoch;
        const meta = { text, rate: speechRate, startedAt: 0, boundaryIndex: 0, boundaryElapsed: 0, epoch };
        currentUtterance = utterance;
        currentSpeechMeta = meta;
        utterance.onstart = () => {
            if (currentUtterance !== utterance || epoch !== lifecycleEpoch) return;
            speechActive = true;
            meta.startedAt = performance.now();
            setStatus(t('status.speaking'), t('status.speakingHelp'), 'speaking');
            schedulePrewarm();
        };
        utterance.onboundary = event => {
            if (currentUtterance !== utterance) return;
            boundaryPulse = 1;
            meta.boundaryIndex = Math.max(meta.boundaryIndex, Number(event.charIndex) || 0);
            meta.boundaryElapsed = Number(event.elapsedTime) > 0 ? Number(event.elapsedTime) * 1000 : performance.now() - meta.startedAt;
            schedulePrewarm();
        };
        const finish = event => {
            if (currentUtterance !== utterance) return;
            const eventAt = performance.now();
            currentUtterance = null;
            currentSpeechMeta = null;
            speechActive = false;
            clearTimeout(prewarmTimer);
            prewarmTimer = 0;
            setStatus(t('status.ready'), t('status.continue'), 'idle');
            maybeStartAutomaticMicrophone(beginTransition(event.type === 'error' ? 'assistant-aborted' : 'assistant-ended', eventAt, performance.now(), ECHO_MARGIN_MS));
        };
        utterance.onend = finish;
        utterance.onerror = finish;
        window.speechSynthesis.speak(utterance);
    }

    function stopSpeech() {
        clearTimeout(prewarmTimer);
        prewarmTimer = 0;
        if ('speechSynthesis' in window) window.speechSynthesis.cancel();
        currentUtterance = null;
        currentSpeechMeta = null;
        speechActive = false;
        boundaryPulse = 0;
    }

    function estimatedRemaining() {
        const meta = currentSpeechMeta;
        if (!meta?.startedAt) return 0;
        if (meta.boundaryIndex > 0 && meta.boundaryElapsed > 200) {
            return Math.max(0, (meta.text.length - meta.boundaryIndex) * (meta.boundaryElapsed / meta.boundaryIndex));
        }
        return Math.max(0, meta.text.length * 92 / Math.max(0.5, meta.rate) + 420 - (performance.now() - meta.startedAt));
    }

    function schedulePrewarm() {
        clearTimeout(prewarmTimer);
        prewarmTimer = 0;
        if (!handsFree || !speechActive || activeSession || prewarmed || microphonePromise) return;
        const epoch = lifecycleEpoch;
        prewarmTimer = setTimeout(async () => {
            prewarmTimer = 0;
            if (epoch !== lifecycleEpoch || !handsFree || !speechActive) return;
            try {
                await prepareMicrophone(epoch);
                if (epoch === lifecycleEpoch && handsFree) prewarmed = true;
            } catch (_) { releaseMicrophone(); }
        }, Math.max(0, estimatedRemaining() - MICROPHONE_PREWARM_MS));
    }

    function maybeStartAutomaticMicrophone(transition) {
        if (!handsFree || generating || speechActive || activeSession || !extensionContext) return;
        startRecording(true, transition);
    }

    function beginTransition(reason, assistantEventAt, audioStopStartedAt, echoMarginMs) {
        const transition = {
            id: ++transitionSequence,
            epoch: lifecycleEpoch,
            reason,
            assistantEventAt,
            audioStopStartedAt,
            microphoneResumeStartedAt: null,
            microphoneInputReadyAt: null,
            echoMarginMs,
        };
        pendingTransition = transition;
        emitTiming(transition, 'assistant-event');
        return transition;
    }

    function markResumeStart(transition) {
        if (!transition || transition.epoch !== lifecycleEpoch) return;
        transition.microphoneResumeStartedAt = performance.now();
        emitTiming(transition, 'microphone-resume-start');
    }

    function markInputReady(transition) {
        if (!transition || transition.epoch !== lifecycleEpoch) return;
        transition.microphoneInputReadyAt = performance.now();
        emitTiming(transition, 'microphone-input-ready');
        if (pendingTransition === transition) pendingTransition = null;
    }

    function waitEchoMargin(transition, epoch) {
        if (!transition || transition.echoMarginMs <= 0) return Promise.resolve(epoch === lifecycleEpoch);
        const wait = Math.max(0, transition.echoMarginMs - (performance.now() - transition.assistantEventAt));
        return new Promise(resolve => {
            automaticTimer = setTimeout(() => {
                automaticTimer = 0;
                resolve(epoch === lifecycleEpoch && pendingTransition === transition);
            }, wait);
        });
    }

    function emitTiming(transition, stage) {
        const origin = Number.isFinite(performance.timeOrigin) ? performance.timeOrigin : Date.now() - performance.now();
        const iso = value => Number.isFinite(value) ? new Date(origin + value).toISOString() : null;
        const entry = {
            stage,
            measurement_id: transition.id,
            turn_epoch: transition.epoch,
            reason: transition.reason,
            assistant_event_at: iso(transition.assistantEventAt),
            audio_stop_start_at: iso(transition.audioStopStartedAt),
            microphone_resume_start_at: iso(transition.microphoneResumeStartedAt),
            microphone_input_ready_at: iso(transition.microphoneInputReadyAt),
            elapsed_ms: Number.isFinite(transition.microphoneInputReadyAt) ? Math.round((transition.microphoneInputReadyAt - transition.assistantEventAt) * 10) / 10 : null,
            echo_margin_ms: transition.echoMarginMs,
        };
        timingLogs.push(entry);
        if (timingLogs.length > MAX_TIMING_LOGS) timingLogs.shift();
        console.info('[ai-search-voice][timing]', entry);
        window.dispatchEvent(new CustomEvent('openconcept:ai-search-voice-timing', { detail: { ...entry } }));
    }

    function clearTimers() {
        clearTimeout(automaticTimer);
        clearTimeout(prewarmTimer);
        automaticTimer = 0;
        prewarmTimer = 0;
        pendingTransition = null;
    }

    function resetLifecycle(reason) {
        lifecycleEpoch += 1;
        clearTimers();
        transcriptionAbort?.abort();
        transcriptionAbort = null;
        submissionAbort?.abort();
        submissionAbort = null;
        transcribing = false;
        generating = false;
        stopSpeech();
        if (activeSession) {
            activeSession.discard = true;
            stopRecording(true, activeSession);
        } else {
            releaseMicrophone();
        }
        setStatus(t('status.ready'), reason === 'panel-closed' ? t('status.sessionEnded') : t('status.readyHelp'), 'idle');
    }

    function rgba(color, alpha) { return `rgba(${color.join(',')},${Math.max(0, Math.min(1, alpha))})`; }

    function drawVisualizer(now) {
        if (!root?.isConnected || !refs.canvas) {
            visualizerFrame = 0;
            return;
        }
        const canvas = refs.canvas;
        const rect = canvas.getBoundingClientRect();
        const ratio = Math.min(2, window.devicePixelRatio || 1);
        const width = Math.max(1, Math.round(rect.width * ratio));
        const height = Math.max(1, Math.round(rect.height * ratio));
        if (canvas.width !== width || canvas.height !== height) { canvas.width = width; canvas.height = height; }
        const ctx = canvas.getContext('2d');
        ctx.clearRect(0, 0, width, height);
        const cx = width / 2;
        const cy = height / 2;
        const radius = Math.max(4 * ratio, Math.min(width, height) / 2 - 7 * ratio);
        const elapsed = now / 1000;
        boundaryPulse *= 0.91;
        const levels = { idle: .08, listening: Math.max(.12, audioLevel), thinking: .24, speaking: .34 + boundaryPulse * .58, error: .13 };
        const colors = { idle: [108, 126, 118], listening: [42, 163, 116], thinking: [92, 99, 204], speaking: [126, 83, 204], error: [205, 76, 76] };
        const level = levels[visualizerMode] ?? levels.idle;
        const color = colors[visualizerMode] ?? colors.idle;
        ctx.lineCap = 'round';
        if (visualizerStyle === 'orb') {
            const gradient = ctx.createRadialGradient(cx, cy, 1, cx, cy, radius);
            gradient.addColorStop(0, rgba(color, .52 + level * .3));
            gradient.addColorStop(1, rgba(color, 0));
            ctx.fillStyle = gradient;
            ctx.beginPath(); ctx.arc(cx, cy, radius * (.82 + Math.sin(elapsed * 3) * .05), 0, Math.PI * 2); ctx.fill();
        } else if (visualizerStyle === 'pulse') {
            for (let index = 0; index < 3; index += 1) {
                const progress = (elapsed * (.35 + level * .3) + index / 3) % 1;
                ctx.strokeStyle = rgba(color, (1 - progress) * (.25 + level));
                ctx.lineWidth = 2 * ratio;
                ctx.beginPath(); ctx.arc(cx, cy, radius * (.25 + progress * .72), 0, Math.PI * 2); ctx.stroke();
            }
        } else if (visualizerStyle === 'spectrum') {
            const count = 17;
            const gap = radius * 1.7 / (count - 1);
            for (let index = 0; index < count; index += 1) {
                const rhythm = (Math.sin(elapsed * 7 + index * 1.6) + 1) / 2;
                const line = Math.min(radius * 1.8, (6 + level * (18 + rhythm * 30)) * ratio);
                const x = cx - radius * .85 + index * gap;
                ctx.strokeStyle = rgba(color, .35 + level * .6);
                ctx.lineWidth = 2.2 * ratio;
                ctx.beginPath(); ctx.moveTo(x, cy - line / 2); ctx.lineTo(x, cy + line / 2); ctx.stroke();
            }
        } else {
            const count = visualizerStyle === 'wave' ? 64 : 40;
            const points = [];
            for (let index = 0; index < count; index += 1) {
                const angle = Math.PI * 2 * index / count - Math.PI / 2;
                const rhythm = (Math.sin(elapsed * 6 + index * 1.47) + 1) / 2;
                const base = visualizerStyle === 'wave' ? radius * .6 : radius * .55;
                const extent = Math.min(radius - base, (4 + level * (8 + rhythm * 20)) * ratio);
                const outer = base + extent;
                if (visualizerStyle === 'wave') points.push([cx + Math.cos(angle) * outer, cy + Math.sin(angle) * outer]);
                else {
                    ctx.strokeStyle = rgba(color, .3 + level * .65);
                    ctx.lineWidth = 2.3 * ratio;
                    ctx.beginPath(); ctx.moveTo(cx + Math.cos(angle) * base, cy + Math.sin(angle) * base); ctx.lineTo(cx + Math.cos(angle) * outer, cy + Math.sin(angle) * outer); ctx.stroke();
                }
            }
            if (points.length) {
                ctx.strokeStyle = rgba(color, .75);
                ctx.lineWidth = 2 * ratio;
                ctx.beginPath(); points.forEach(([x, y], index) => index ? ctx.lineTo(x, y) : ctx.moveTo(x, y)); ctx.closePath(); ctx.stroke();
            }
        }
        visualizerFrame = requestAnimationFrame(drawVisualizer);
    }

    window.OpenConceptAiSearchVoiceTiming = Object.freeze({
        configuration: Object.freeze({ targetMs: 500, prewarmMs: MICROPHONE_PREWARM_MS, echoMarginMs: ECHO_MARGIN_MS, vadEchoGuardMs: VAD_ECHO_GUARD_MS }),
        recent: () => timingLogs.map(entry => ({ ...entry })),
    });

    if ('speechSynthesis' in window && typeof window.speechSynthesis.addEventListener === 'function') {
        window.speechSynthesis.addEventListener('voiceschanged', renderVoiceOptions);
    }
    if (window.OpenConceptPlugins?.register && window.OpenConceptPlugins?.registerAiSearch) {
        window.OpenConceptPlugins.register(PLUGIN_ID, () => window.OpenConceptAI?.open());
        window.OpenConceptPlugins.registerAiSearch(PLUGIN_ID, { mount, unmount, onSubmitStart, onResult, onError });
    }
    window.addEventListener('openconcept:locale-change', () => {
        statusTitle = t('status.ready');
        statusDetail = t('status.readyHelp');
        if (!extensionContext) return;
        const context = extensionContext;
        resetLifecycle('locale-changed');
        mount(context);
    });
})();
