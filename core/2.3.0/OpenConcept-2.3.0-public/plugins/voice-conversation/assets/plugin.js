(() => {
    'use strict';

    const PLUGIN_ID = 'voice-conversation';
    const t = (key, parameters = {}) => window.OpenConceptI18n?.t(PLUGIN_ID, key, parameters, key) || key;
    const SPEECH_RATE_OPTIONS = [
        { value: 3, labelKey: 'settings.rate.fast' },
        { value: 2.5, labelKey: 'settings.rate.mediumFast' },
        { value: 2, labelKey: 'settings.rate.normal' },
        { value: 1.75, labelKey: 'settings.rate.slow' },
    ];
    const SILENCE_TIMEOUT_OPTIONS = [
        { value: 4, labelKey: 'settings.silence.short' },
        { value: 6, labelKey: 'settings.silence.normal' },
        { value: 8, labelKey: 'settings.silence.long' },
    ];
    const SILENCE_TIMEOUT_VALUES = SILENCE_TIMEOUT_OPTIONS.map(option => option.value);
    const VISUALIZER_STYLE_OPTIONS = [
        { value: 'ring', labelKey: 'settings.style.ring' },
        { value: 'wave', labelKey: 'settings.style.wave' },
        { value: 'orb', labelKey: 'settings.style.orb' },
        { value: 'pulse', labelKey: 'settings.style.pulse' },
        { value: 'spectrum', labelKey: 'settings.style.spectrum' },
    ];
    const VISUALIZER_LAYOUT_OPTIONS = [
        { value: 'stacked', labelKey: 'settings.layout.stacked' },
        { value: 'columns', labelKey: 'settings.layout.columns' },
        { value: 'chat', labelKey: 'settings.layout.chat' },
        { value: 'visualizer', labelKey: 'settings.layout.visualizer' },
    ];
    const DEFAULT_SPEECH_RATE = 2;
    const DEFAULT_SILENCE_TIMEOUT_SECONDS = 6;
    const SPEECH_END_SILENCE_MS = 1200;
    const VAD_SPEECH_CONFIRM_MS = 120;
    const MICROPHONE_PREWARM_MS = 1800;
    const MICROPHONE_ECHO_MARGIN_MS = 140;
    const VAD_ECHO_GUARD_MS = 80;
    const MAX_TIMING_LOGS = 50;
    const PANEL_EDGE_MARGIN = 12;
    const csrf = () => window.OPENCONCEPT_BOOT?.csrf || '';
    let overlay = null;
    let refs = {};
    let panelPosition = readStoredPanelPosition();
    let panelDragState = null;
    let conversations = [];
    let currentConversationId = 0;
    let conversationLoadRequestId = 0;
    let lifecycleEpoch = 0;
    let generating = false;
    let generationRequestId = 0;
    let currentAbort = null;
    let transcriptionAbort = null;
    let transcriptionRequestId = 0;
    let recordingSessionSequence = 0;
    let activeRecordingSession = null;
    let mediaStream = null;
    let audioContext = null;
    let analyser = null;
    let vadPreparationPromise = null;
    let automaticMicrophoneTimer = 0;
    let automaticMicrophoneTimerResolve = null;
    let microphonePrewarmTimer = 0;
    let microphoneStreamPromise = null;
    let microphoneRequestSerial = 0;
    let microphonePrewarmed = false;
    let microphoneResumeSequence = 0;
    let microphoneResumePromise = null;
    let microphoneResumeEpoch = -1;
    let pendingMicrophoneTransition = null;
    const microphoneTimingLogs = [];
    let audioLevel = 0;
    let visualizerMode = 'idle';
    let visualizerFrame = 0;
    let visualizerStartedAt = performance.now();
    let boundaryPulse = 0;
    let speechBuffer = '';
    let activeUtterances = 0;
    let currentSpeechMeta = null;
    const utterances = new Set();
    let ttsEnabled = readStoredTtsPreference();
    let speechRate = readStoredSpeechRate();
    let selectedVoiceKey = readStoredVoicePreference();
    let handsFreeEnabled = readStoredHandsFreePreference();
    let silenceTimeoutSeconds = readStoredSilenceTimeout();
    let visualizerStyle = readStoredVisualizerStyle();
    let visualizerLayout = readStoredVisualizerLayout();

    function readStoredTtsPreference() {
        try {
            return localStorage.getItem('openconcept.voice.tts') !== 'off';
        } catch (_) {
            return true;
        }
    }

    function storeTtsPreference() {
        try {
            localStorage.setItem('openconcept.voice.tts', ttsEnabled ? 'on' : 'off');
        } catch (_) {
            // Private browsing can make localStorage unavailable.
        }
    }

    function readStoredSpeechRate() {
        try {
            const value = Number(localStorage.getItem('openconcept.voice.rate'));
            return SPEECH_RATE_OPTIONS.some(option => option.value === value) ? value : DEFAULT_SPEECH_RATE;
        } catch (_) {
            return DEFAULT_SPEECH_RATE;
        }
    }

    function storeSpeechRate() {
        try {
            localStorage.setItem('openconcept.voice.rate', String(speechRate));
        } catch (_) {
            // The selection still applies for the current page session.
        }
    }

    function readStoredVoicePreference() {
        try {
            return localStorage.getItem('openconcept.voice.voice') || '';
        } catch (_) {
            return '';
        }
    }

    function storeVoicePreference() {
        try {
            if (selectedVoiceKey) localStorage.setItem('openconcept.voice.voice', selectedVoiceKey);
            else localStorage.removeItem('openconcept.voice.voice');
        } catch (_) {
            // The selection still applies for the current page session.
        }
    }

    function voiceKey(voice) {
        return JSON.stringify([voice.voiceURI || '', voice.name || '', voice.lang || '']);
    }

    function currentSpeechLocale() {
        return window.OpenConceptI18n?.locale() || document.documentElement.lang || navigator.language || 'en-US';
    }

    function compatibleSpeechVoices() {
        if (!('speechSynthesis' in window)) return [];
        const locale = currentSpeechLocale();
        const language = locale.toLowerCase().split('-')[0];
        return window.speechSynthesis.getVoices()
            .filter(voice => String(voice.lang || '').toLowerCase().split('-')[0] === language)
            .sort((left, right) => {
                if (left.default !== right.default) return left.default ? -1 : 1;
                return String(left.name || '').localeCompare(String(right.name || ''), locale);
            });
    }

    function selectedSpeechVoice(voices = window.speechSynthesis?.getVoices?.() || []) {
        const language = currentSpeechLocale().toLowerCase().split('-')[0];
        const compatibleVoices = voices.filter(voice => String(voice.lang || '').toLowerCase().split('-')[0] === language);
        if (selectedVoiceKey) {
            const selected = compatibleVoices.find(voice => voiceKey(voice) === selectedVoiceKey);
            if (selected) return selected;
        }
        return compatibleVoices.find(voice => voice.default) || compatibleVoices[0] || null;
    }

    function populateVoiceOptions() {
        if (!refs.voice) return;
        const voices = compatibleSpeechVoices();
        refs.voice.replaceChildren();
        const standard = document.createElement('option');
        standard.value = '';
        standard.textContent = voices.length ? t('settings.voiceDefault') : t('settings.voiceUnavailable');
        refs.voice.appendChild(standard);
        voices.forEach(voice => {
            const option = document.createElement('option');
            option.value = voiceKey(voice);
            option.textContent = voice.name || voice.voiceURI || voice.lang;
            refs.voice.appendChild(option);
        });
        const storedVoiceAvailable = voices.some(voice => voiceKey(voice) === selectedVoiceKey);
        refs.voice.value = storedVoiceAvailable ? selectedVoiceKey : '';
        refs.voice.disabled = voices.length === 0;
        refs.voice.title = voices.length
            ? t('settings.voiceHelp')
            : t('settings.voiceUnavailableHelp');
    }

    function readStoredHandsFreePreference() {
        try {
            return localStorage.getItem('openconcept.voice.hands-free') === 'on';
        } catch (_) {
            return false;
        }
    }

    function storeHandsFreePreference() {
        try {
            localStorage.setItem('openconcept.voice.hands-free', handsFreeEnabled ? 'on' : 'off');
        } catch (_) {
            // The selection still applies for the current page session.
        }
    }

    function readStoredSilenceTimeout() {
        try {
            const value = Number(localStorage.getItem('openconcept.voice.silence-timeout'));
            return SILENCE_TIMEOUT_VALUES.includes(value) ? value : DEFAULT_SILENCE_TIMEOUT_SECONDS;
        } catch (_) {
            return DEFAULT_SILENCE_TIMEOUT_SECONDS;
        }
    }

    function storeSilenceTimeout() {
        try {
            localStorage.setItem('openconcept.voice.silence-timeout', String(silenceTimeoutSeconds));
        } catch (_) {
            // The selection still applies for the current page session.
        }
    }

    function readStoredVisualizerStyle() {
        try {
            const value = localStorage.getItem('openconcept.voice.visualizer');
            return VISUALIZER_STYLE_OPTIONS.some(option => option.value === value) ? value : 'ring';
        } catch (_) {
            return 'ring';
        }
    }

    function storeVisualizerStyle() {
        try {
            localStorage.setItem('openconcept.voice.visualizer', visualizerStyle);
        } catch (_) {
            // The selection still applies for the current page session.
        }
    }

    function visualizerStyleLabel() {
        const option = VISUALIZER_STYLE_OPTIONS.find(item => item.value === visualizerStyle) || VISUALIZER_STYLE_OPTIONS[0];
        return t(option.labelKey);
    }

    function readStoredVisualizerLayout() {
        try {
            const value = localStorage.getItem('openconcept.voice.layout');
            if (value === 'text') return 'chat';
            return VISUALIZER_LAYOUT_OPTIONS.some(option => option.value === value) ? value : 'stacked';
        } catch (_) {
            return 'stacked';
        }
    }

    function storeVisualizerLayout() {
        try {
            localStorage.setItem('openconcept.voice.layout', visualizerLayout);
        } catch (_) {
            // The selection still applies for the current page session.
        }
    }

    function visualizerLayoutLabel() {
        const option = VISUALIZER_LAYOUT_OPTIONS.find(item => item.value === visualizerLayout) || VISUALIZER_LAYOUT_OPTIONS[0];
        return t(option.labelKey);
    }

    function readStoredPanelPosition() {
        try {
            const value = JSON.parse(localStorage.getItem('openconcept.voice.position') || 'null');
            if (Number.isFinite(value?.left) && Number.isFinite(value?.top)) {
                return { left: value.left, top: value.top };
            }
        } catch (_) {
            // Use the default top-right position.
        }
        return null;
    }

    function storePanelPosition() {
        if (!panelPosition) return;
        try {
            localStorage.setItem('openconcept.voice.position', JSON.stringify(panelPosition));
        } catch (_) {
            // The position still applies for the current page session.
        }
    }

    function placePanel(left, top, persist = false) {
        if (!refs.popup) return;
        const rect = refs.popup.getBoundingClientRect();
        const maxLeft = Math.max(PANEL_EDGE_MARGIN, window.innerWidth - rect.width - PANEL_EDGE_MARGIN);
        const maxTop = Math.max(PANEL_EDGE_MARGIN, window.innerHeight - rect.height - PANEL_EDGE_MARGIN);
        const nextLeft = Math.min(maxLeft, Math.max(PANEL_EDGE_MARGIN, left));
        const nextTop = Math.min(maxTop, Math.max(PANEL_EDGE_MARGIN, top));
        refs.popup.style.left = `${Math.round(nextLeft)}px`;
        refs.popup.style.top = `${Math.round(nextTop)}px`;
        refs.popup.style.right = 'auto';
        refs.popup.style.bottom = 'auto';
        panelPosition = { left: Math.round(nextLeft), top: Math.round(nextTop) };
        if (persist) storePanelPosition();
    }

    function restorePanelPosition() {
        if (!refs.popup || !overlay || overlay.classList.contains('is-hidden')) return;
        const rect = refs.popup.getBoundingClientRect();
        const fallbackLeft = window.innerWidth - rect.width - PANEL_EDGE_MARGIN;
        placePanel(panelPosition?.left ?? fallbackLeft, panelPosition?.top ?? PANEL_EDGE_MARGIN);
    }

    function startPanelDrag(event) {
        if (event.button !== 0 || !refs.popup || !refs.dragHandle) return;
        const rect = refs.popup.getBoundingClientRect();
        panelDragState = {
            pointerId: event.pointerId,
            offsetX: event.clientX - rect.left,
            offsetY: event.clientY - rect.top,
        };
        refs.dragHandle.setPointerCapture?.(event.pointerId);
        refs.popup.classList.add('is-dragging');
        event.preventDefault();
    }

    function movePanelDrag(event) {
        if (!panelDragState || event.pointerId !== panelDragState.pointerId) return;
        placePanel(event.clientX - panelDragState.offsetX, event.clientY - panelDragState.offsetY);
    }

    function finishPanelDrag(event) {
        if (!panelDragState || event.pointerId !== panelDragState.pointerId) return;
        refs.dragHandle?.releasePointerCapture?.(event.pointerId);
        panelDragState = null;
        refs.popup?.classList.remove('is-dragging');
        storePanelPosition();
    }

    function movePanelWithKeyboard(event) {
        const directions = {
            ArrowLeft: [-1, 0],
            ArrowRight: [1, 0],
            ArrowUp: [0, -1],
            ArrowDown: [0, 1],
        };
        const direction = directions[event.key];
        if (!direction || !refs.popup) return;
        const rect = refs.popup.getBoundingClientRect();
        const distance = event.shiftKey ? 5 : 20;
        placePanel(rect.left + direction[0] * distance, rect.top + direction[1] * distance, true);
        event.preventDefault();
    }

    function timingIso(monotonicTime) {
        if (!Number.isFinite(monotonicTime)) return null;
        const origin = Number.isFinite(performance.timeOrigin)
            ? performance.timeOrigin
            : Date.now() - performance.now();
        return new Date(origin + monotonicTime).toISOString();
    }

    function timingSnapshot(transition, stage) {
        const elapsed = Number.isFinite(transition.microphoneInputReadyAt)
            ? transition.microphoneInputReadyAt - transition.assistantEventAt
            : null;
        return {
            stage,
            measurement_id: transition.id,
            turn_epoch: transition.epoch,
            reason: transition.reason,
            assistant_event_at: timingIso(transition.assistantEventAt),
            assistant_event_at_ms: Math.round(transition.assistantEventAt * 10) / 10,
            audio_stop_start_at: timingIso(transition.audioStopStartedAt),
            audio_stop_start_at_ms: Math.round(transition.audioStopStartedAt * 10) / 10,
            microphone_resume_start_at: timingIso(transition.microphoneResumeStartedAt),
            microphone_resume_start_at_ms: Number.isFinite(transition.microphoneResumeStartedAt)
                ? Math.round(transition.microphoneResumeStartedAt * 10) / 10
                : null,
            microphone_input_ready_at: timingIso(transition.microphoneInputReadyAt),
            microphone_input_ready_at_ms: Number.isFinite(transition.microphoneInputReadyAt)
                ? Math.round(transition.microphoneInputReadyAt * 10) / 10
                : null,
            elapsed_ms: Number.isFinite(elapsed) ? Math.round(elapsed * 10) / 10 : null,
            echo_margin_ms: transition.echoMarginMs,
            cancel_reason: transition.cancelReason || null,
        };
    }

    function emitMicrophoneTiming(transition, stage) {
        const snapshot = timingSnapshot(transition, stage);
        microphoneTimingLogs.push(snapshot);
        if (microphoneTimingLogs.length > MAX_TIMING_LOGS) microphoneTimingLogs.shift();
        console.info('[voice-conversation][timing]', snapshot);
        window.dispatchEvent(new CustomEvent('openconcept:voice-timing', { detail: { ...snapshot } }));
    }

    function beginMicrophoneTransition(reason, assistantEventAt, audioStopStartedAt, echoMarginMs) {
        const transition = {
            id: ++microphoneResumeSequence,
            epoch: lifecycleEpoch,
            reason,
            assistantEventAt,
            audioStopStartedAt,
            microphoneResumeStartedAt: null,
            microphoneInputReadyAt: null,
            echoMarginMs,
            cancelReason: '',
        };
        pendingMicrophoneTransition = transition;
        emitMicrophoneTiming(transition, 'assistant-event');
        return transition;
    }

    function markMicrophoneResumeStarted(transition) {
        if (!transition || transition.epoch !== lifecycleEpoch) return;
        transition.microphoneResumeStartedAt = performance.now();
        emitMicrophoneTiming(transition, 'microphone-resume-start');
    }

    function markMicrophoneInputReady(transition) {
        if (!transition || transition.epoch !== lifecycleEpoch) return;
        transition.microphoneInputReadyAt = performance.now();
        emitMicrophoneTiming(transition, 'microphone-input-ready');
        if (pendingMicrophoneTransition === transition) pendingMicrophoneTransition = null;
    }

    function cancelPendingMicrophoneTransition(reason) {
        const transition = pendingMicrophoneTransition;
        if (!transition || Number.isFinite(transition.microphoneInputReadyAt)) return;
        transition.cancelReason = reason;
        emitMicrophoneTiming(transition, 'microphone-resume-cancelled');
        pendingMicrophoneTransition = null;
    }

    window.OpenConceptVoiceTiming = Object.freeze({
        configuration: Object.freeze({
            targetMs: 500,
            prewarmMs: MICROPHONE_PREWARM_MS,
            echoMarginMs: MICROPHONE_ECHO_MARGIN_MS,
            vadEchoGuardMs: VAD_ECHO_GUARD_MS,
        }),
        recent: () => microphoneTimingLogs.map(entry => ({ ...entry })),
    });

    function silenceTimeoutLabel() {
        return t('settings.seconds', { count: silenceTimeoutSeconds });
    }

    function speechRateLabel(rate = speechRate) {
        const option = SPEECH_RATE_OPTIONS.find(item => item.value === rate)
            || SPEECH_RATE_OPTIONS.find(item => item.value === DEFAULT_SPEECH_RATE);
        return t(option.labelKey);
    }

    function optionMarkup(options) {
        return options.map(option => `<option value="${option.value}">${t(option.labelKey)}</option>`).join('');
    }

    function createPopup() {
        if (overlay) return;
        overlay = document.createElement('div');
        overlay.className = 'voice-plugin-overlay is-hidden';
        overlay.innerHTML = `
            <section class="voice-plugin-popup" role="dialog" aria-modal="false" aria-labelledby="voicePluginTitle">
                <header class="voice-plugin-header">
                    <div class="voice-plugin-title-wrap" data-voice-drag-handle role="button" tabindex="0" aria-label="${t('window.move')}" title="${t('window.drag')}">
                        <span class="voice-plugin-logo" aria-hidden="true">🎙️</span>
                        <div><h2 id="voicePluginTitle">${t('plugin.name')}</h2><p>${t('header.privacy')}</p></div>
                        <span class="voice-plugin-drag-indicator" aria-hidden="true">⠿</span>
                    </div>
                    <div class="voice-plugin-window-actions">
                        <button class="voice-plugin-icon-button" type="button" data-voice-action="toggle-tts" aria-label="${t('settings.ttsToggle')}" aria-pressed="true">🔊</button>
                        <button class="voice-plugin-icon-button" type="button" data-voice-action="close" aria-label="${t('common.close')}">✕</button>
                    </div>
                    <div class="voice-plugin-header-settings">
                        <button class="voice-plugin-auto-mic" type="button" data-voice-action="toggle-hands-free" aria-label="${t('settings.autoMicHelp')}" aria-pressed="false">
                            <span class="voice-plugin-auto-mic-label">${t('settings.autoMic')}</span><span class="voice-plugin-auto-mic-switch" aria-hidden="true"></span>
                        </button>
                        <label class="voice-plugin-voice">
                            <span>${t('settings.voice')}</span>
                            <select aria-label="${t('settings.voiceLabel')}">
                                <option value="">${t('settings.voiceLoading')}</option>
                            </select>
                        </label>
                        <label class="voice-plugin-speed">
                            <span>${t('settings.rate')}</span>
                            <select aria-label="${t('settings.rateLabel')}">
                                ${optionMarkup(SPEECH_RATE_OPTIONS)}
                            </select>
                        </label>
                        <label class="voice-plugin-silence">
                            <span>${t('settings.silence')}</span>
                            <select aria-label="${t('settings.silenceLabel')}">
                                ${optionMarkup(SILENCE_TIMEOUT_OPTIONS)}
                            </select>
                        </label>
                    </div>
                </header>
                <div class="voice-plugin-layout">
                    <aside class="voice-plugin-sidebar" aria-label="${t('conversation.history')}">
                        <button class="voice-plugin-new" type="button" data-voice-action="new">＋ ${t('conversation.new')}</button>
                        <div class="voice-plugin-conversation-list"></div>
                    </aside>
                    <main class="voice-plugin-main">
                        <div class="voice-plugin-view-controls">
                            <label class="voice-plugin-visualizer-layout">
                                <span>${t('settings.layout')}</span>
                                <select aria-label="${t('settings.layoutLabel')}">
                                    ${optionMarkup(VISUALIZER_LAYOUT_OPTIONS)}
                                </select>
                            </label>
                            <label class="voice-plugin-visualizer-style">
                                <span>${t('settings.display')}</span>
                                <select aria-label="${t('settings.styleLabel')}">
                                    ${optionMarkup(VISUALIZER_STYLE_OPTIONS)}
                                </select>
                            </label>
                        </div>
                        <section class="voice-plugin-stage" aria-label="${t('visualizer.status')}">
                            <div class="voice-plugin-stage-content">
                                <div class="voice-plugin-visualizer-wrap">
                                    <canvas class="voice-plugin-visualizer" aria-hidden="true"></canvas>
                                    <div class="voice-plugin-orb"><span>AI</span></div>
                                </div>
                                <div class="voice-plugin-status-copy">
                                    <strong class="voice-plugin-status" aria-live="polite">${t('status.ready')}</strong>
                                    <span class="voice-plugin-status-detail">${t('status.readyHelp')}</span>
                                </div>
                            </div>
                        </section>
                        <section class="voice-plugin-chat" aria-label="${t('conversation.chat')}">
                            <div class="voice-plugin-messages" role="log" aria-live="polite" aria-label="${t('conversation.content')}"></div>
                            <form class="voice-plugin-composer">
                                <button class="voice-plugin-mic" type="button" data-voice-action="record" aria-label="${t('input.startRecording')}">🎙️</button>
                                <textarea rows="1" maxlength="8000" placeholder="${t('input.placeholder')}" aria-label="${t('input.label')}"></textarea>
                                <button class="voice-plugin-send" type="submit" aria-label="${t('common.send')}">➤</button>
                            </form>
                            <p class="voice-plugin-note">${t('input.recordingHelp')}</p>
                        </section>
                    </main>
                </div>
            </section>`;
        document.body.appendChild(overlay);
        refs = {
            popup: overlay.querySelector('.voice-plugin-popup'),
            dragHandle: overlay.querySelector('[data-voice-drag-handle]'),
            list: overlay.querySelector('.voice-plugin-conversation-list'),
            messages: overlay.querySelector('.voice-plugin-messages'),
            form: overlay.querySelector('.voice-plugin-composer'),
            textarea: overlay.querySelector('textarea'),
            mic: overlay.querySelector('.voice-plugin-mic'),
            send: overlay.querySelector('.voice-plugin-send'),
            status: overlay.querySelector('.voice-plugin-status'),
            detail: overlay.querySelector('.voice-plugin-status-detail'),
            tts: overlay.querySelector('[data-voice-action="toggle-tts"]'),
            handsFree: overlay.querySelector('[data-voice-action="toggle-hands-free"]'),
            voice: overlay.querySelector('.voice-plugin-voice select'),
            speed: overlay.querySelector('.voice-plugin-speed select'),
            silence: overlay.querySelector('.voice-plugin-silence select'),
            visualizerLayout: overlay.querySelector('.voice-plugin-visualizer-layout select'),
            visualizerStyle: overlay.querySelector('.voice-plugin-visualizer-style select'),
            canvas: overlay.querySelector('.voice-plugin-visualizer'),
        };
        refs.popup.dataset.visualizerStyle = visualizerStyle;
        refs.popup.dataset.voiceLayout = visualizerLayout;
        overlay.addEventListener('click', handleClick);
        refs.dragHandle.addEventListener('pointerdown', startPanelDrag);
        refs.dragHandle.addEventListener('pointermove', movePanelDrag);
        refs.dragHandle.addEventListener('pointerup', finishPanelDrag);
        refs.dragHandle.addEventListener('pointercancel', finishPanelDrag);
        refs.dragHandle.addEventListener('keydown', movePanelWithKeyboard);
        window.addEventListener('resize', restorePanelPosition);
        refs.form.addEventListener('submit', event => {
            event.preventDefault();
            const text = refs.textarea.value.trim();
            if (!text || generating) return;
            refs.textarea.value = '';
            resizeTextarea();
            submitMessage(text, 'text');
        });
        refs.textarea.addEventListener('input', resizeTextarea);
        refs.textarea.addEventListener('keydown', event => {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                refs.form.requestSubmit();
            }
        });
        refs.speed.value = String(speechRate);
        refs.voice.addEventListener('change', () => {
            selectedVoiceKey = refs.voice.value;
            storeVoicePreference();
            const selectedName = refs.voice.selectedOptions[0]?.textContent || t('settings.voiceDefault');
            const mode = ['speaking', 'thinking'].includes(visualizerMode) ? visualizerMode : 'idle';
            setStatus(t('settings.changedVoice'), t('settings.changedVoiceHelp', { voice: selectedName }), mode);
        });
        refs.speed.addEventListener('change', () => {
            const selected = Number(refs.speed.value);
            if (!SPEECH_RATE_OPTIONS.some(option => option.value === selected)) {
                refs.speed.value = String(speechRate);
                return;
            }
            speechRate = selected;
            storeSpeechRate();
            const mode = ['speaking', 'thinking'].includes(visualizerMode) ? visualizerMode : 'idle';
            setStatus(t('settings.changedRate'), t('settings.changedRateHelp', { rate: speechRateLabel() }), mode);
        });
        refs.silence.value = String(silenceTimeoutSeconds);
        refs.silence.addEventListener('change', () => {
            const selected = Number(refs.silence.value);
            if (!SILENCE_TIMEOUT_VALUES.includes(selected)) {
                refs.silence.value = String(silenceTimeoutSeconds);
                return;
            }
            silenceTimeoutSeconds = selected;
            storeSilenceTimeout();
            const mode = ['listening', 'speaking', 'thinking'].includes(visualizerMode) ? visualizerMode : 'idle';
            setStatus(t('settings.changedSilence'), t('settings.changedSilenceHelp', { duration: silenceTimeoutLabel() }), mode);
        });
        refs.visualizerStyle.value = visualizerStyle;
        refs.visualizerStyle.addEventListener('change', () => {
            const selected = refs.visualizerStyle.value;
            if (!VISUALIZER_STYLE_OPTIONS.some(option => option.value === selected)) {
                refs.visualizerStyle.value = visualizerStyle;
                return;
            }
            visualizerStyle = selected;
            storeVisualizerStyle();
            refs.popup.dataset.visualizerStyle = visualizerStyle;
            visualizerStartedAt = performance.now();
            boundaryPulse = 0;
            const mode = ['listening', 'speaking', 'thinking'].includes(visualizerMode) ? visualizerMode : 'idle';
            setStatus(t('settings.changedDisplay'), t('settings.changedDisplayHelp', { style: visualizerStyleLabel() }), mode);
        });
        refs.visualizerLayout.value = visualizerLayout;
        refs.visualizerLayout.addEventListener('change', () => {
            const selected = refs.visualizerLayout.value;
            if (!VISUALIZER_LAYOUT_OPTIONS.some(option => option.value === selected)) {
                refs.visualizerLayout.value = visualizerLayout;
                return;
            }
            visualizerLayout = selected;
            storeVisualizerLayout();
            refs.popup.dataset.voiceLayout = visualizerLayout;
            const mode = ['listening', 'speaking', 'thinking'].includes(visualizerMode) ? visualizerMode : 'idle';
            setStatus(t('settings.changedLayout'), t('settings.changedLayoutHelp', { layout: visualizerLayoutLabel() }), mode);
        });
        document.addEventListener('keydown', handleGlobalKeydown);
        populateVoiceOptions();
        if ('speechSynthesis' in window && typeof window.speechSynthesis.addEventListener === 'function') {
            window.speechSynthesis.addEventListener('voiceschanged', populateVoiceOptions);
        }
        updateTtsButton();
        updateHandsFreeButton();
    }

    function handleGlobalKeydown(event) {
        if (event.key === 'Escape' && overlay && !overlay.classList.contains('is-hidden')) closePopup();
    }

    async function refreshPopupLocale() {
        if (!overlay) return;
        const wasVisible = !overlay.classList.contains('is-hidden');
        if (wasVisible) resetTurnLifecycle({ preserveMicrophone: false, markInterrupted: false });
        overlay.remove();
        overlay = null;
        refs = {};
        if (!wasVisible) return;
        createPopup();
        overlay.classList.remove('is-hidden');
        restorePanelPosition();
        renderConversationList();
        visualizerStartedAt = performance.now();
        if (!visualizerFrame) visualizerFrame = requestAnimationFrame(drawVisualizer);
        if (currentConversationId) await loadConversation(currentConversationId);
        else renderWelcome();
    }

    async function openPopup() {
        createPopup();
        overlay.classList.remove('is-hidden');
        restorePanelPosition();
        visualizerStartedAt = performance.now();
        if (!visualizerFrame) visualizerFrame = requestAnimationFrame(drawVisualizer);
        refs.textarea.focus();
        await loadConversations();
        if (currentConversationId) {
            await loadConversation(currentConversationId);
        } else {
            renderWelcome();
        }
    }

    function closePopup() {
        resetTurnLifecycle({ preserveMicrophone: false, markInterrupted: false });
        panelDragState = null;
        refs.popup?.classList.remove('is-dragging');
        overlay?.classList.add('is-hidden');
        if (visualizerFrame) cancelAnimationFrame(visualizerFrame);
        visualizerFrame = 0;
    }

    async function handleClick(event) {
        const actionTarget = event.target.closest('[data-voice-action]');
        if (!actionTarget) return;
        const action = actionTarget.dataset.voiceAction;
        if (action === 'close') closePopup();
        if (action === 'new') await createConversation();
        if (action === 'record') {
            if (activeRecordingSession?.recorder.state === 'recording') stopRecording(false, activeRecordingSession);
            else if (activeRecordingSession) return;
            else await startRecording();
        }
        if (action === 'toggle-tts') {
            ttsEnabled = !ttsEnabled;
            storeTtsPreference();
            let stoppedSpeech = null;
            if (!ttsEnabled) {
                const outputWasActive = assistantOutputIsActive();
                const assistantEventAt = performance.now();
                const audioStopStartedAt = performance.now();
                stopSpeech();
                if (outputWasActive) {
                    stoppedSpeech = {
                        reason: 'assistant-aborted',
                        assistantEventAt,
                        audioStopStartedAt,
                        echoMarginMs: MICROPHONE_ECHO_MARGIN_MS,
                    };
                }
            }
            updateTtsButton();
            if (!ttsEnabled) maybeStartAutomaticMicrophone(stoppedSpeech);
        }
        if (action === 'toggle-hands-free') {
            handsFreeEnabled = !handsFreeEnabled;
            storeHandsFreePreference();
            updateHandsFreeButton();
            if (!handsFreeEnabled) {
                cancelPendingMicrophoneTransition('hands-free-disabled');
                clearAutomaticMicrophoneTimer();
                clearMicrophonePrewarmTimer();
                microphoneResumePromise = null;
                microphoneResumeEpoch = -1;
                if (activeRecordingSession?.automatic) stopRecording(true, activeRecordingSession);
                else if (microphonePrewarmed || microphoneStreamPromise) releaseMicrophone();
                setStatus(t('status.autoMicOff'), t('status.autoMicOffHelp'), 'idle');
            } else if (generating || utterances.size > 0 || activeUtterances > 0) {
                refs.detail.textContent = t('status.autoMicPreparingHelp');
                scheduleAutomaticMicrophonePrewarm();
            } else {
                await startRecording(true);
            }
        }
        if (action === 'conversation') {
            await loadConversation(Number(actionTarget.dataset.id) || 0);
        }
    }

    async function api(action, options = {}) {
        const headers = new Headers(options.headers || {});
        if (options.body && !(options.body instanceof FormData)) {
            headers.set('Content-Type', 'application/json');
            options.body = JSON.stringify(options.body);
        }
        if ((options.method || 'GET') !== 'GET') headers.set('X-CSRF-Token', csrf());
        const response = await fetch(`api.php?action=${encodeURIComponent(action)}${options.query || ''}`, {
            credentials: 'same-origin',
            ...options,
            headers,
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(apiErrorMessage(action, response.status));
        return data;
    }

    function apiErrorMessage(action, status) {
        if (status === 401) return t('error.session');
        if (status === 403) return t('error.forbidden');
        if (status === 404) return t('error.notFound');
        if (status === 429) return t('error.rateLimit');
        if (action === 'plugin-voice-transcribe') return t('error.transcription');
        return t('error.server');
    }

    async function loadConversations() {
        try {
            const data = await api('plugin-voice-conversations');
            conversations = Array.isArray(data.conversations) ? data.conversations : [];
            if (currentConversationId && !conversations.some(item => Number(item.id) === currentConversationId)) {
                currentConversationId = 0;
            }
            if (!currentConversationId && conversations.length) currentConversationId = Number(conversations[0].id) || 0;
            renderConversationList();
        } catch (error) {
            showError(error.message);
        }
    }

    async function createConversation() {
        resetTurnLifecycle({ preserveMicrophone: false, markInterrupted: true });
        const loadRequestId = ++conversationLoadRequestId;
        try {
            const data = await api('plugin-voice-conversation', { method: 'POST', body: {} });
            if (loadRequestId !== conversationLoadRequestId) return;
            currentConversationId = Number(data.conversation?.id) || 0;
            conversations.unshift(data.conversation);
            renderConversationList();
            renderWelcome();
            refs.textarea.focus();
        } catch (error) {
            showError(error.message);
        }
    }

    async function loadConversation(id) {
        if (!id) return;
        resetTurnLifecycle({ preserveMicrophone: false, markInterrupted: true });
        const loadRequestId = ++conversationLoadRequestId;
        currentConversationId = id;
        renderConversationList();
        refs.messages.replaceChildren(messagePlaceholder(t('conversation.loading')));
        try {
            const data = await api('plugin-voice-conversation', { query: `&id=${encodeURIComponent(id)}` });
            if (loadRequestId !== conversationLoadRequestId || currentConversationId !== id) return;
            renderMessages(Array.isArray(data.messages) ? data.messages : []);
        } catch (error) {
            showError(error.message);
        }
    }

    function renderConversationList() {
        refs.list.replaceChildren();
        if (!conversations.length) {
            const empty = document.createElement('p');
            empty.className = 'voice-plugin-list-empty';
            empty.textContent = t('conversation.none');
            refs.list.appendChild(empty);
            return;
        }
        conversations.forEach(conversation => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'voice-plugin-conversation';
            if (Number(conversation.id) === currentConversationId) button.classList.add('is-active');
            button.dataset.voiceAction = 'conversation';
            button.dataset.id = String(conversation.id);
            const title = document.createElement('strong');
            title.textContent = conversation.title || t('conversation.new');
            const meta = document.createElement('span');
            meta.textContent = t('conversation.messageCount', { count: Number(conversation.message_count) || 0 });
            button.append(title, meta);
            refs.list.appendChild(button);
        });
    }

    function renderWelcome() {
        refs.messages.replaceChildren();
        const welcome = document.createElement('div');
        welcome.className = 'voice-plugin-welcome';
        const icon = document.createElement('span');
        icon.textContent = '✦';
        const title = document.createElement('strong');
        title.textContent = t('conversation.welcome');
        const copy = document.createElement('p');
        copy.textContent = t('conversation.welcomeHelp');
        welcome.append(icon, title, copy);
        refs.messages.appendChild(welcome);
        setStatus(t('status.ready'), t('status.readyHelp'), 'idle');
    }

    function renderMessages(messages) {
        refs.messages.replaceChildren();
        if (!messages.length) {
            renderWelcome();
            return;
        }
        messages.forEach(message => appendMessage(message.role, message.text, false));
        scrollMessages();
        setStatus(t('status.ready'), t('status.continue'), 'idle');
    }

    function appendMessage(role, text, streaming = false) {
        const row = document.createElement('article');
        row.className = `voice-plugin-message ${role === 'assistant' ? 'is-ai' : 'is-user'}`;
        if (streaming) row.classList.add('is-streaming');
        const label = document.createElement('span');
        label.className = 'voice-plugin-message-label';
        label.textContent = role === 'assistant' ? 'AI' : t('conversation.you');
        const bubble = document.createElement('div');
        bubble.className = 'voice-plugin-message-bubble';
        bubble.textContent = text;
        row.append(label, bubble);
        refs.messages.appendChild(row);
        scrollMessages();
        return { row, bubble };
    }

    function messagePlaceholder(text) {
        const element = document.createElement('p');
        element.className = 'voice-plugin-placeholder';
        element.textContent = text;
        return element;
    }

    async function submitMessage(text, source) {
        if (!text || generating) return;
        resetTurnLifecycle({ preserveMicrophone: false, markInterrupted: false });
        const turnEpoch = lifecycleEpoch;
        if (!currentConversationId) {
            try {
                const data = await api('plugin-voice-conversation', { method: 'POST', body: {} });
                if (turnEpoch !== lifecycleEpoch) return;
                currentConversationId = Number(data.conversation?.id) || 0;
                conversations.unshift(data.conversation);
                renderConversationList();
            } catch (error) {
                showError(error.message);
                return;
            }
        }

        const welcome = refs.messages.querySelector('.voice-plugin-welcome');
        if (welcome) welcome.remove();
        appendMessage('user', text);
        const assistant = appendMessage('assistant', '', true);
        const messageConversationId = currentConversationId;
        const requestId = ++generationRequestId;
        generating = true;
        let completed = false;
        const requestAbort = new AbortController();
        currentAbort = requestAbort;
        speechBuffer = '';
        updateControls();
        setStatus(t('status.thinking'), t('status.thinkingHelp'), 'thinking');

        try {
            const response = await fetch('api.php?action=plugin-voice-message', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
                body: JSON.stringify({ conversation_id: messageConversationId, text, source }),
                signal: requestAbort.signal,
            });
            if (!response.ok) {
                await response.json().catch(() => ({}));
                throw new Error(apiErrorMessage('plugin-voice-message', response.status));
            }
            await readEventStream(response, (event, data) => {
                if (requestId !== generationRequestId) return;
                if (event === 'ready') {
                    currentConversationId = Number(data.conversation?.id) || currentConversationId;
                }
                if (event === 'delta') {
                    const delta = String(data.delta || '');
                    assistant.bubble.textContent += delta;
                    queueSpeech(delta, false);
                    scrollMessages();
                }
                if (event === 'done') {
                    completed = true;
                    assistant.row.classList.remove('is-streaming');
                    queueSpeech('', true);
                    generating = false;
                    if (currentAbort === requestAbort) currentAbort = null;
                    updateControls();
                    scheduleAutomaticMicrophonePrewarm();
                    if (!ttsEnabled || utterances.size === 0) {
                        const assistantEventAt = performance.now();
                        const audioStopStartedAt = performance.now();
                        if (ttsEnabled && 'speechSynthesis' in window) window.speechSynthesis.cancel();
                        setStatus(t('status.ready'), t('status.completed'), 'idle');
                        maybeStartAutomaticMicrophone({
                            reason: ttsEnabled ? 'assistant-response-complete' : 'assistant-text-complete',
                            assistantEventAt,
                            audioStopStartedAt,
                            echoMarginMs: ttsEnabled ? MICROPHONE_ECHO_MARGIN_MS : 0,
                        });
                    }
                    runAfterMicrophoneResume(() => loadConversations(), lifecycleEpoch);
                }
                if (event === 'error') throw new Error(t('error.conversation'));
            });
            if (!completed) throw new Error(t('error.streamEnded'));
        } catch (error) {
            if (error.name !== 'AbortError' && requestId === generationRequestId) {
                assistant.row.classList.remove('is-streaming');
                assistant.row.classList.add('is-error');
                assistant.bubble.textContent = assistant.bubble.textContent || error.message;
                showError(error.message);
            }
        } finally {
            if (requestId === generationRequestId) {
                generating = false;
                if (currentAbort === requestAbort) currentAbort = null;
                updateControls();
                if (visualizerMode === 'thinking') setStatus(t('status.ready'), t('status.tryAgain'), 'idle');
            }
        }
    }

    async function readEventStream(response, onEvent) {
        if (!response.body) throw new Error(t('error.streamingUnsupported'));
        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        while (true) {
            const { value, done } = await reader.read();
            buffer += decoder.decode(value || new Uint8Array(), { stream: !done }).replace(/\r\n/g, '\n');
            let split;
            while ((split = buffer.indexOf('\n\n')) !== -1) {
                const block = buffer.slice(0, split);
                buffer = buffer.slice(split + 2);
                dispatchEventBlock(block, onEvent);
            }
            if (done) break;
        }
        if (buffer.trim()) dispatchEventBlock(buffer, onEvent);
    }

    function dispatchEventBlock(block, onEvent) {
        let event = 'message';
        const dataLines = [];
        block.split('\n').forEach(line => {
            if (line.startsWith('event:')) event = line.slice(6).trim();
            if (line.startsWith('data:')) dataLines.push(line.slice(5).trimStart());
        });
        if (!dataLines.length) return;
        const data = JSON.parse(dataLines.join('\n'));
        onEvent(event, data);
    }

    function assistantOutputIsActive() {
        return generating
            || utterances.size > 0
            || activeUtterances > 0
            || Boolean(window.speechSynthesis?.speaking || window.speechSynthesis?.pending);
    }

    async function prepareMicrophoneForRecording(recordingEpoch) {
        const requestedStream = await acquireMicrophoneStream();
        if (!requestedStream || recordingEpoch !== lifecycleEpoch) return null;
        await prepareVad();
        if (recordingEpoch !== lifecycleEpoch || requestedStream !== mediaStream) return null;
        return requestedStream;
    }

    async function startRecording(automatic = false, transition = null) {
        if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
            showError(t('error.recordingUnsupported'));
            return;
        }
        if (activeRecordingSession) return;
        let resumeTransition = transition;
        if (automatic) {
            if (!handsFreeEnabled || generating || utterances.size > 0 || activeUtterances > 0) return;
        } else {
            const outputWasActive = assistantOutputIsActive();
            const assistantEventAt = performance.now();
            const audioStopStartedAt = performance.now();
            stopCurrentOutput({ preserveMicrophone: true });
            if (!resumeTransition && outputWasActive) {
                resumeTransition = beginMicrophoneTransition(
                    'assistant-interrupted',
                    assistantEventAt,
                    audioStopStartedAt,
                    MICROPHONE_ECHO_MARGIN_MS
                );
            }
        }
        const recordingEpoch = lifecycleEpoch;
        if (resumeTransition && resumeTransition.epoch !== recordingEpoch) return;
        markMicrophoneResumeStarted(resumeTransition);
        try {
            const [requestedStream, echoMarginElapsed] = await Promise.all([
                prepareMicrophoneForRecording(recordingEpoch),
                waitForMicrophoneSafetyMargin(resumeTransition, recordingEpoch),
            ]);
            if (!requestedStream || !echoMarginElapsed || recordingEpoch !== lifecycleEpoch) return;
            if (automatic && !handsFreeEnabled) {
                releaseMicrophone();
                return;
            }
            const mime = selectRecordingMime();
            const recorder = mime ? new MediaRecorder(requestedStream, { mimeType: mime }) : new MediaRecorder(requestedStream);
            const startedAt = performance.now();
            const session = {
                id: ++recordingSessionSequence,
                epoch: recordingEpoch,
                conversationId: currentConversationId,
                recorder,
                stream: mediaStream,
                audioContext,
                analyser,
                vadFrame: 0,
                chunks: [],
                mime: recorder.mimeType || mime || 'audio/webm',
                automatic,
                startedAt,
                speechDetectionStartsAt: startedAt + VAD_ECHO_GUARD_MS,
                speechCandidateStartedAt: 0,
                lastSpeechAt: startedAt,
                speechDetected: false,
                discard: false,
                released: false,
            };
            activeRecordingSession = session;
            recorder.addEventListener('dataavailable', event => {
                if (event.data?.size && !session.discard && !session.released) session.chunks.push(event.data);
            });
            recorder.addEventListener('stop', () => handleRecordingStopped(session), { once: true });
            recorder.start(250);
            markMicrophoneInputReady(resumeTransition);
            microphonePrewarmed = false;
            refs.mic.classList.remove('is-preparing');
            refs.mic.classList.add('is-recording');
            refs.mic.textContent = '■';
            refs.mic.setAttribute('aria-label', t('input.stopRecording'));
            updateControls();
            setStatus(
                automatic ? t('status.autoListening') : t('status.listening'),
                automatic
                    ? t('status.autoListeningHelp', { duration: silenceTimeoutLabel() })
                    : t('status.listeningHelp'),
                'listening'
            );
            startVad(session).catch(error => {
                if (activeRecordingSession === session && session.epoch === lifecycleEpoch) showError(error.message);
            });
        } catch (error) {
            if (recordingEpoch !== lifecycleEpoch) return;
            if (pendingMicrophoneTransition === resumeTransition) cancelPendingMicrophoneTransition('microphone-start-error');
            releaseMicrophone(activeRecordingSession);
            if (automatic && error.name === 'NotAllowedError') {
                handsFreeEnabled = false;
                storeHandsFreePreference();
                updateHandsFreeButton();
            }
            showError(error.name === 'NotAllowedError' ? t('error.microphoneDenied') : error.message);
        }
    }

    function microphoneStreamIsLive() {
        return Boolean(mediaStream?.getAudioTracks().some(track => track.readyState === 'live'));
    }

    async function acquireMicrophoneStream() {
        if (microphoneStreamIsLive()) return mediaStream;
        if (microphoneStreamPromise) return microphoneStreamPromise;
        const requestSerial = ++microphoneRequestSerial;
        const acquisition = (async () => {
            const stream = await navigator.mediaDevices.getUserMedia({
                audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
                video: false,
            });
            if (requestSerial !== microphoneRequestSerial) {
                stream.getTracks().forEach(track => track.stop());
                return null;
            }
            mediaStream = stream;
            return stream;
        })();
        microphoneStreamPromise = acquisition;
        try {
            return await acquisition;
        } finally {
            if (microphoneStreamPromise === acquisition) microphoneStreamPromise = null;
        }
    }

    async function prewarmAutomaticMicrophone() {
        clearMicrophonePrewarmTimer();
        const prewarmEpoch = lifecycleEpoch;
        if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') return;
        if (!handsFreeEnabled || activeRecordingSession || !overlay || overlay.classList.contains('is-hidden')) return;
        if (utterances.size !== 1 || activeUtterances < 1) return;
        setMicrophonePreparing(true);
        setStatus(t('status.speaking'), t('status.preparingForSpeechEnd'), 'speaking');
        try {
            const stream = await acquireMicrophoneStream();
            if (!stream) return;
            if (prewarmEpoch !== lifecycleEpoch) return;
            if (!handsFreeEnabled || !overlay || overlay.classList.contains('is-hidden')) {
                releaseMicrophone();
                return;
            }
            if (activeRecordingSession) return;
            await prepareVad();
            if (prewarmEpoch !== lifecycleEpoch || !handsFreeEnabled || activeRecordingSession) return;
            microphonePrewarmed = true;
            if (utterances.size === 0 && !generating) {
                maybeStartAutomaticMicrophone();
            } else {
                setStatus(t('status.speaking'), t('status.microphoneReady'), 'speaking');
            }
        } catch (error) {
            if (prewarmEpoch !== lifecycleEpoch) return;
            releaseMicrophone();
            if (error.name === 'NotAllowedError') {
                handsFreeEnabled = false;
                storeHandsFreePreference();
                updateHandsFreeButton();
            }
            showError(error.name === 'NotAllowedError' ? t('error.microphoneDenied') : error.message);
        }
    }

    function setMicrophonePreparing(preparing) {
        if (!refs.mic || activeRecordingSession) return;
        refs.mic.classList.toggle('is-preparing', preparing);
        refs.mic.textContent = preparing ? '…' : '🎙️';
        refs.mic.setAttribute('aria-label', preparing ? t('input.preparingMic') : t('input.startRecording'));
    }

    function selectRecordingMime() {
        return [
            'audio/webm;codecs=opus',
            'audio/mp4',
            'audio/ogg;codecs=opus',
            'audio/webm',
        ].find(type => MediaRecorder.isTypeSupported(type)) || '';
    }

    async function prepareVad() {
        if (analyser && audioContext && audioContext.state !== 'closed') return;
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextClass) return;
        if (vadPreparationPromise) return vadPreparationPromise;
        const stream = mediaStream;
        const preparation = (async () => {
            const context = new AudioContextClass();
            try {
                await context.resume();
            } catch (error) {
                await context.close().catch(() => {});
                throw error;
            }
            if (!stream || mediaStream !== stream) {
                await context.close().catch(() => {});
                return;
            }
            const source = context.createMediaStreamSource(stream);
            const nextAnalyser = context.createAnalyser();
            nextAnalyser.fftSize = 1024;
            nextAnalyser.smoothingTimeConstant = 0.25;
            source.connect(nextAnalyser);
            audioContext = context;
            analyser = nextAnalyser;
        })();
        vadPreparationPromise = preparation;
        try {
            await preparation;
        } finally {
            if (vadPreparationPromise === preparation) vadPreparationPromise = null;
        }
    }

    async function startVad(session) {
        await prepareVad();
        if (!analyser || activeRecordingSession !== session || session.epoch !== lifecycleEpoch) return;
        session.audioContext = audioContext;
        session.analyser = analyser;
        const samples = new Float32Array(session.analyser.fftSize);
        let baseline = 0.008;
        const inspect = now => {
            if (activeRecordingSession !== session || session.epoch !== lifecycleEpoch) return;
            if (session.recorder.state !== 'recording' || !session.analyser || session.released) return;
            session.analyser.getFloatTimeDomainData(samples);
            let sum = 0;
            for (let i = 0; i < samples.length; i += 1) sum += samples[i] * samples[i];
            const rms = Math.sqrt(sum / samples.length);
            audioLevel += (Math.min(1, rms * 8) - audioLevel) * 0.35;
            const elapsed = now - session.startedAt;
            const threshold = Math.max(0.018, baseline * 2.8);
            if (!session.speechDetected && !session.speechCandidateStartedAt && elapsed < 1000 && rms <= threshold) {
                baseline = baseline * 0.92 + rms * 0.08;
            }
            if (now >= session.speechDetectionStartsAt && rms > threshold) {
                if (session.speechDetected) {
                    session.lastSpeechAt = now;
                    boundaryPulse = Math.min(1, boundaryPulse + 0.18);
                } else {
                    if (!session.speechCandidateStartedAt) session.speechCandidateStartedAt = now;
                    if (now - session.speechCandidateStartedAt >= VAD_SPEECH_CONFIRM_MS) {
                        session.speechDetected = true;
                        session.lastSpeechAt = now;
                        boundaryPulse = Math.min(1, boundaryPulse + 0.18);
                    }
                }
            } else if (!session.speechDetected) {
                session.speechCandidateStartedAt = 0;
            }
            if (session.speechDetected && elapsed >= SPEECH_END_SILENCE_MS && now - session.lastSpeechAt >= SPEECH_END_SILENCE_MS) {
                stopRecording(false, session);
                return;
            }
            const noSpeechTimeout = session.automatic ? silenceTimeoutSeconds * 1000 : 10000;
            if (elapsed > 30000 || (!session.speechDetected && elapsed > noSpeechTimeout)) {
                stopRecording(false, session);
                return;
            }
            session.vadFrame = requestAnimationFrame(inspect);
        };
        session.vadFrame = requestAnimationFrame(inspect);
    }

    function stopRecording(discard, session = activeRecordingSession) {
        if (!session || session !== activeRecordingSession) return;
        session.discard = session.discard || Boolean(discard);
        if (session.recorder.state !== 'recording') {
            if (discard) releaseMicrophone(session);
            return;
        }
        session.recorder.stop();
    }

    async function handleRecordingStopped(session) {
        const current = activeRecordingSession === session && session.epoch === lifecycleEpoch;
        const discard = session.discard || !current;
        const detected = session.speechDetected;
        const wasAutomatic = session.automatic;
        const blob = new Blob(session.chunks, { type: session.mime || 'audio/webm' });
        releaseMicrophone(session);
        if (discard) return;
        if (!detected && wasAutomatic) {
            setStatus(t('status.autoStopped'), t('status.autoStoppedHelp', { duration: silenceTimeoutLabel() }), 'idle');
            return;
        }
        if (!detected || blob.size < 200) {
            showError(t('error.noSpeech'));
            return;
        }
        setStatus(t('status.transcribing'), t('status.transcribingHelp'), 'thinking');
        const requestId = ++transcriptionRequestId;
        const requestAbort = new AbortController();
        if (transcriptionAbort) transcriptionAbort.abort();
        transcriptionAbort = requestAbort;
        try {
            const form = new FormData();
            form.append('audio', blob, recordingFilename(session.mime));
            const result = await api('plugin-voice-transcribe', { method: 'POST', body: form, signal: requestAbort.signal });
            if (requestId !== transcriptionRequestId || session.epoch !== lifecycleEpoch) return;
            if (currentConversationId !== session.conversationId) return;
            if (!result.text) throw new Error(t('error.transcription'));
            if (transcriptionAbort === requestAbort) transcriptionAbort = null;
            await submitMessage(result.text, 'voice');
        } catch (error) {
            if (error.name !== 'AbortError' && requestId === transcriptionRequestId && session.epoch === lifecycleEpoch) {
                showError(error.message);
            }
        } finally {
            if (transcriptionAbort === requestAbort) transcriptionAbort = null;
        }
    }

    function recordingFilename(mime) {
        if (mime.includes('mp4')) return 'recording.m4a';
        if (mime.includes('ogg')) return 'recording.ogg';
        return 'recording.webm';
    }

    function cleanupRecordingSession(session) {
        if (!session || session.released) return;
        session.released = true;
        if (session.vadFrame) cancelAnimationFrame(session.vadFrame);
        session.vadFrame = 0;
        session.stream?.getTracks().forEach(track => track.stop());
        if (session.audioContext && session.audioContext.state !== 'closed') {
            session.audioContext.close().catch(() => {});
        }
        session.chunks.length = 0;
    }

    function releaseMicrophone(session = activeRecordingSession) {
        const ownsCurrentState = !session || activeRecordingSession === session;
        if (session) cleanupRecordingSession(session);
        if (!ownsCurrentState) return;
        clearAutomaticMicrophoneTimer();
        clearMicrophonePrewarmTimer();
        microphoneRequestSerial += 1;
        microphoneStreamPromise = null;
        vadPreparationPromise = null;
        if (!session || mediaStream !== session.stream) mediaStream?.getTracks().forEach(track => track.stop());
        mediaStream = null;
        if ((!session || audioContext !== session.audioContext) && audioContext && audioContext.state !== 'closed') {
            audioContext.close().catch(() => {});
        }
        audioContext = null;
        analyser = null;
        activeRecordingSession = null;
        microphonePrewarmed = false;
        audioLevel = 0;
        if (refs.mic) {
            refs.mic.classList.remove('is-recording', 'is-preparing');
            refs.mic.textContent = '🎙️';
            refs.mic.setAttribute('aria-label', t('input.startRecording'));
            updateControls();
        }
    }

    function queueSpeech(delta, flush) {
        if (!ttsEnabled || !('speechSynthesis' in window)) return;
        speechBuffer += delta;
        let boundary;
        while ((boundary = findSentenceBoundary(speechBuffer)) !== -1) {
            const sentence = speechBuffer.slice(0, boundary + 1).trim();
            speechBuffer = speechBuffer.slice(boundary + 1);
            if (sentence) speak(sentence);
        }
        if (flush && speechBuffer.trim()) {
            speak(speechBuffer.trim());
            speechBuffer = '';
        }
    }

    function findSentenceBoundary(value) {
        const match = value.match(/[。！？!?\n]|\.(?:\s|$)/);
        if (match) return match.index + (match[0].startsWith('.') ? 0 : match[0].length - 1);
        return value.length > 140 ? 120 : -1;
    }

    function estimateSpeechDurationMs(text, rate) {
        const weightedDuration = Array.from(text).reduce((total, character) => {
            if (/\s/.test(character)) return total;
            if (/[。、！？!?.,:：;；]/.test(character)) return total + 220;
            if (/[\u3040-\u30ff\u3400-\u9fff]/.test(character)) return total + 150;
            return total + 75;
        }, 0);
        return Math.max(800, Math.min(60000, weightedDuration / Math.max(0.5, rate || 1)));
    }

    function estimateSpeechRemainingMs(meta) {
        if (!meta?.startedAt) return 0;
        if (meta.boundaryCharIndex > 0 && meta.boundaryElapsedMs > 200) {
            const millisecondsPerCharacter = meta.boundaryElapsedMs / meta.boundaryCharIndex;
            return Math.max(0, (meta.text.length - meta.boundaryCharIndex) * millisecondsPerCharacter);
        }
        const elapsed = performance.now() - meta.startedAt;
        return Math.max(0, estimateSpeechDurationMs(meta.text, meta.rate) - elapsed);
    }

    function scheduleAutomaticMicrophonePrewarm() {
        clearMicrophonePrewarmTimer();
        const meta = currentSpeechMeta;
        if (!handsFreeEnabled || !meta || utterances.size !== 1 || activeUtterances < 1) return;
        if (activeRecordingSession || microphonePrewarmed || microphoneStreamPromise) return;
        if (!overlay || overlay.classList.contains('is-hidden')) return;
        const scheduledEpoch = lifecycleEpoch;
        const remaining = estimateSpeechRemainingMs(meta);
        const delay = Math.max(0, remaining - MICROPHONE_PREWARM_MS);
        microphonePrewarmTimer = window.setTimeout(() => {
            microphonePrewarmTimer = 0;
            if (scheduledEpoch !== lifecycleEpoch) return;
            prewarmAutomaticMicrophone();
        }, delay);
    }

    function speak(text) {
        const utterance = new SpeechSynthesisUtterance(text);
        utterance.lang = currentSpeechLocale();
        utterance.rate = speechRate;
        utterance.pitch = 1;
        const voices = window.speechSynthesis.getVoices();
        const language = utterance.lang.toLowerCase().split('-')[0];
        const voice = selectedSpeechVoice(voices)
            || voices.find(item => item.lang?.toLowerCase().startsWith(language));
        if (voice) {
            utterance.voice = voice;
            utterance.lang = voice.lang || utterance.lang;
        }
        const meta = {
            utterance,
            epoch: lifecycleEpoch,
            text,
            rate: utterance.rate,
            startedAt: 0,
            boundaryCharIndex: 0,
            boundaryElapsedMs: 0,
            started: false,
        };
        utterances.add(utterance);
        utterance.onstart = () => {
            if (meta.epoch !== lifecycleEpoch || !utterances.has(utterance)) return;
            meta.started = true;
            activeUtterances += 1;
            meta.startedAt = performance.now();
            currentSpeechMeta = meta;
            boundaryPulse = 1;
            setStatus(t('status.speaking'), t('status.speakingHelp'), 'speaking');
            scheduleAutomaticMicrophonePrewarm();
        };
        utterance.onboundary = event => {
            boundaryPulse = 1;
            if (meta.epoch !== lifecycleEpoch || currentSpeechMeta !== meta) return;
            meta.boundaryCharIndex = Math.max(meta.boundaryCharIndex, Number(event.charIndex) || 0);
            const eventElapsed = Number(event.elapsedTime);
            meta.boundaryElapsedMs = eventElapsed > 0
                ? eventElapsed * 1000
                : performance.now() - meta.startedAt;
            scheduleAutomaticMicrophonePrewarm();
        };
        const finish = event => {
            const assistantEventAt = performance.now();
            if (!utterances.delete(utterance)) return;
            if (currentSpeechMeta === meta) currentSpeechMeta = null;
            clearMicrophonePrewarmTimer();
            if (meta.started) activeUtterances = Math.max(0, activeUtterances - 1);
            if (meta.epoch !== lifecycleEpoch) return;
            if (utterances.size === 0 && !generating) {
                const audioStopStartedAt = performance.now();
                if ('speechSynthesis' in window) window.speechSynthesis.cancel();
                setStatus(t('status.ready'), t('status.continue'), 'idle');
                maybeStartAutomaticMicrophone({
                    reason: event?.type === 'error' ? 'assistant-aborted' : 'assistant-ended',
                    assistantEventAt,
                    audioStopStartedAt,
                    echoMarginMs: MICROPHONE_ECHO_MARGIN_MS,
                });
            }
        };
        utterance.onend = finish;
        utterance.onerror = finish;
        window.speechSynthesis.speak(utterance);
    }

    function stopSpeech() {
        if ('speechSynthesis' in window) window.speechSynthesis.cancel();
        clearMicrophonePrewarmTimer();
        utterances.clear();
        activeUtterances = 0;
        currentSpeechMeta = null;
        speechBuffer = '';
        boundaryPulse = 0;
        if (!generating) setStatus(t('status.ready'), t('status.ttsStopped'), 'idle');
    }

    function resetTurnLifecycle({ preserveMicrophone = false, markInterrupted = true } = {}) {
        cancelPendingMicrophoneTransition('turn-reset');
        lifecycleEpoch += 1;
        clearAutomaticMicrophoneTimer();
        clearMicrophonePrewarmTimer();
        microphoneResumePromise = null;
        microphoneResumeEpoch = -1;
        generationRequestId += 1;
        if (currentAbort) currentAbort.abort();
        currentAbort = null;
        transcriptionRequestId += 1;
        if (transcriptionAbort) transcriptionAbort.abort();
        transcriptionAbort = null;
        generating = false;
        stopSpeech();
        if (activeRecordingSession) {
            const session = activeRecordingSession;
            session.discard = true;
            if (session.recorder.state === 'recording') session.recorder.stop();
            releaseMicrophone(session);
        } else if (!preserveMicrophone) {
            releaseMicrophone();
        }
        if (markInterrupted) {
            refs.messages?.querySelectorAll('.is-streaming').forEach(row => {
                row.classList.remove('is-streaming');
                row.classList.add('is-interrupted');
                row.dataset.interruptedLabel = t('conversation.interrupted');
            });
        }
        updateControls();
        return lifecycleEpoch;
    }

    function stopCurrentOutput({ preserveMicrophone = true } = {}) {
        resetTurnLifecycle({ preserveMicrophone, markInterrupted: true });
    }

    function setStatus(title, detail, mode) {
        if (!refs.status) return;
        refs.status.textContent = title;
        refs.detail.textContent = detail;
        visualizerMode = mode;
        refs.popup.dataset.voiceMode = mode;
    }

    function showError(message) {
        setStatus(t('status.needsAttention'), message || t('error.generic'), 'error');
    }

    function updateControls() {
        if (!refs.send) return;
        refs.send.disabled = generating;
        refs.textarea.disabled = generating;
        refs.mic.disabled = false;
    }

    function updateTtsButton() {
        if (!refs.tts) return;
        const supported = 'speechSynthesis' in window;
        if (!supported) ttsEnabled = false;
        refs.tts.textContent = ttsEnabled ? '🔊' : '🔇';
        refs.tts.setAttribute('aria-pressed', ttsEnabled ? 'true' : 'false');
        refs.tts.title = supported
            ? (ttsEnabled ? t('settings.ttsOff') : t('settings.ttsOn'))
            : t('settings.ttsUnsupported');
        refs.tts.disabled = !supported;
    }

    function updateHandsFreeButton() {
        if (!refs.handsFree) return;
        refs.handsFree.setAttribute('aria-pressed', handsFreeEnabled ? 'true' : 'false');
        refs.handsFree.setAttribute('aria-label', handsFreeEnabled
            ? t('settings.autoMicOff')
            : t('settings.autoMicHelp'));
        refs.handsFree.title = handsFreeEnabled
            ? t('settings.autoMicOff')
            : t('settings.autoMicHelp');
    }

    function clearAutomaticMicrophoneTimer() {
        if (automaticMicrophoneTimer) window.clearTimeout(automaticMicrophoneTimer);
        automaticMicrophoneTimer = 0;
        if (automaticMicrophoneTimerResolve) automaticMicrophoneTimerResolve(false);
        automaticMicrophoneTimerResolve = null;
    }

    function clearMicrophonePrewarmTimer() {
        if (microphonePrewarmTimer) window.clearTimeout(microphonePrewarmTimer);
        microphonePrewarmTimer = 0;
    }

    function waitForMicrophoneSafetyMargin(transition, scheduledEpoch) {
        if (!transition || transition.echoMarginMs <= 0) return Promise.resolve(true);
        const remaining = Math.max(0, transition.echoMarginMs - (performance.now() - transition.assistantEventAt));
        if (remaining <= 0) return Promise.resolve(scheduledEpoch === lifecycleEpoch);
        clearAutomaticMicrophoneTimer();
        return new Promise(resolve => {
            automaticMicrophoneTimerResolve = resolve;
            automaticMicrophoneTimer = window.setTimeout(() => {
                automaticMicrophoneTimer = 0;
                automaticMicrophoneTimerResolve = null;
                resolve(scheduledEpoch === lifecycleEpoch && pendingMicrophoneTransition === transition);
            }, remaining);
        });
    }

    function maybeStartAutomaticMicrophone(event = null) {
        if (microphoneResumePromise && microphoneResumeEpoch === lifecycleEpoch) return microphoneResumePromise;
        if (!handsFreeEnabled || generating || utterances.size > 0 || activeUtterances > 0 || activeRecordingSession) return;
        if (!overlay || overlay.classList.contains('is-hidden')) return;
        clearAutomaticMicrophoneTimer();
        const now = performance.now();
        const transition = beginMicrophoneTransition(
            event?.reason || 'assistant-ready',
            Number.isFinite(event?.assistantEventAt) ? event.assistantEventAt : now,
            Number.isFinite(event?.audioStopStartedAt) ? event.audioStopStartedAt : now,
            Number.isFinite(event?.echoMarginMs) ? event.echoMarginMs : MICROPHONE_ECHO_MARGIN_MS
        );
        const resumeEpoch = lifecycleEpoch;
        const resume = startRecording(true, transition);
        microphoneResumeEpoch = resumeEpoch;
        microphoneResumePromise = resume;
        resume.finally(() => {
            if (microphoneResumePromise === resume) {
                microphoneResumePromise = null;
                microphoneResumeEpoch = -1;
            }
        });
        return resume;
    }

    function runAfterMicrophoneResume(task, taskEpoch) {
        const resume = microphoneResumeEpoch === taskEpoch && microphoneResumePromise
            ? microphoneResumePromise.catch(() => {})
            : Promise.resolve();
        resume.finally(() => {
            window.setTimeout(() => {
                if (taskEpoch !== lifecycleEpoch) return;
                Promise.resolve(task()).catch(() => {});
            }, 0);
        });
    }

    function resizeTextarea() {
        refs.textarea.style.height = 'auto';
        refs.textarea.style.height = `${Math.min(120, refs.textarea.scrollHeight)}px`;
    }

    function scrollMessages() {
        requestAnimationFrame(() => { refs.messages.scrollTop = refs.messages.scrollHeight; });
    }

    function visualizerRgba(color, alpha) {
        return `rgba(${color[0]}, ${color[1]}, ${color[2]}, ${Math.max(0, Math.min(1, alpha))})`;
    }

    function visualizerRhythm(index, elapsed) {
        return (Math.sin(elapsed * 7 + index * 1.61) + Math.sin(elapsed * 3.4 - index * 0.73) + 2) / 4;
    }

    function drawRingVisualizer(context, geometry, config, elapsed) {
        const { cx, cy, extent, ratio, safeOuterRadius, visualScale } = geometry;
        const innerRadius = Math.min(safeOuterRadius - 2 * ratio, extent * 0.27 + 2 * ratio);
        const availableLength = Math.max(2 * ratio, safeOuterRadius - innerRadius);
        const barCount = 40;
        context.lineCap = 'round';
        for (let index = 0; index < barCount; index += 1) {
            const angle = (Math.PI * 2 * index / barCount) - Math.PI / 2;
            const rhythm = visualizerRhythm(index, elapsed);
            const thinkingSweep = visualizerMode === 'thinking' ? Math.max(0, Math.cos(angle - elapsed * 2.5)) * 0.32 : 0;
            const amplitude = Math.min(1, config.level * (0.35 + rhythm * 0.65) + thinkingSweep);
            const length = Math.min(availableLength, (3 + amplitude * 17) * ratio * visualScale);
            context.beginPath();
            context.moveTo(cx + Math.cos(angle) * innerRadius, cy + Math.sin(angle) * innerRadius);
            context.lineTo(cx + Math.cos(angle) * (innerRadius + length), cy + Math.sin(angle) * (innerRadius + length));
            context.strokeStyle = visualizerRgba(config.color, 0.3 + amplitude * 0.68);
            context.lineWidth = Math.max(2, 2.5 * ratio * Math.min(1.5, visualScale));
            context.stroke();
        }
    }

    function drawWaveVisualizer(context, geometry, config, elapsed) {
        const { cx, cy, extent, ratio, safeOuterRadius, visualScale } = geometry;
        const pointCount = 64;
        const baseRadius = Math.min(safeOuterRadius - 3 * ratio, extent * 0.29);
        const availableWave = Math.max(1.5 * ratio, safeOuterRadius - baseRadius - ratio);
        const points = [];
        for (let index = 0; index < pointCount; index += 1) {
            const angle = Math.PI * 2 * index / pointCount;
            const rhythm = visualizerRhythm(index, elapsed);
            const wave = Math.sin(angle * 5 - elapsed * 5.2) * 0.42 + Math.sin(angle * 3 + elapsed * 3.1) * 0.22;
            const amplitude = Math.min(1, config.level * (0.45 + rhythm * 0.55) + Math.abs(wave) * 0.24);
            const radius = Math.min(safeOuterRadius, baseRadius + availableWave * amplitude * (0.62 + wave));
            points.push([cx + Math.cos(angle) * radius, cy + Math.sin(angle) * radius]);
        }
        [
            { width: 5.5 * ratio * Math.min(1.4, visualScale), alpha: 0.11 },
            { width: 1.8 * ratio * Math.min(1.4, visualScale), alpha: 0.82 },
        ].forEach(layer => {
            context.beginPath();
            points.forEach(([x, y], index) => {
                if (index === 0) context.moveTo(x, y);
                else context.lineTo(x, y);
            });
            context.closePath();
            context.strokeStyle = visualizerRgba(config.color, layer.alpha);
            context.lineWidth = layer.width;
            context.lineJoin = 'round';
            context.stroke();
        });
    }

    function drawOrbVisualizer(context, geometry, config, elapsed) {
        const { cx, cy, ratio, safeOuterRadius } = geometry;
        const pulse = 0.88 + Math.sin(elapsed * 3.2) * 0.05 + Math.min(0.07, config.level * 0.08);
        const radius = Math.max(4 * ratio, safeOuterRadius * pulse);
        const offset = Math.max(0, Math.min(2.5 * ratio, safeOuterRadius - radius));
        const x = cx + Math.cos(elapsed * 1.7) * offset;
        const y = cy + Math.sin(elapsed * 1.4) * offset;
        const gradient = context.createRadialGradient(x, y, radius * 0.08, x, y, radius);
        gradient.addColorStop(0, visualizerRgba(config.color, 0.42 + config.level * 0.25));
        gradient.addColorStop(0.48, visualizerRgba(config.color, 0.2 + config.level * 0.2));
        gradient.addColorStop(1, visualizerRgba(config.color, 0));
        context.fillStyle = gradient;
        context.beginPath();
        context.arc(x, y, radius, 0, Math.PI * 2);
        context.fill();
    }

    function drawPulseVisualizer(context, geometry, config, elapsed) {
        const { cx, cy, ratio, safeOuterRadius, visualScale } = geometry;
        const innerRadius = Math.min(safeOuterRadius * 0.5, 18 * ratio * visualScale);
        context.lineCap = 'round';
        for (let index = 0; index < 3; index += 1) {
            const progress = (elapsed * (0.34 + config.level * 0.25) + index / 3) % 1;
            const radius = innerRadius + (safeOuterRadius - innerRadius) * progress;
            context.beginPath();
            context.arc(cx, cy, radius, 0, Math.PI * 2);
            context.strokeStyle = visualizerRgba(config.color, (1 - progress) * (0.2 + config.level * 0.62));
            context.lineWidth = Math.max(1.5 * ratio, (2.8 - progress) * ratio * Math.min(1.4, visualScale));
            context.stroke();
        }
    }

    function drawSpectrumVisualizer(context, geometry, config, elapsed) {
        const { cx, cy, extent, ratio, safeOuterRadius, visualScale } = geometry;
        const barCount = 17;
        const gap = Math.max(2.5 * ratio, extent * 0.035);
        const totalWidth = gap * (barCount - 1);
        const maxHeight = safeOuterRadius * 1.86;
        context.lineCap = 'round';
        for (let index = 0; index < barCount; index += 1) {
            const rhythm = visualizerRhythm(index, elapsed);
            const centerWeight = 1 - Math.abs(index - (barCount - 1) / 2) / (barCount / 2);
            const amplitude = Math.min(1, config.level * (0.4 + rhythm * 0.75) + centerWeight * 0.14);
            const height = Math.min(maxHeight, (5 + amplitude * 37) * ratio * visualScale);
            const x = cx - totalWidth / 2 + index * gap;
            context.beginPath();
            context.moveTo(x, cy - height / 2);
            context.lineTo(x, cy + height / 2);
            context.strokeStyle = visualizerRgba(config.color, 0.26 + amplitude * 0.68);
            context.lineWidth = 2.2 * ratio * Math.min(1.4, visualScale);
            context.stroke();
        }
    }

    function drawVisualizer(now) {
        if (!overlay || overlay.classList.contains('is-hidden')) {
            visualizerFrame = 0;
            return;
        }
        const canvas = refs.canvas;
        const rect = canvas.getBoundingClientRect();
        const ratio = Math.min(2, window.devicePixelRatio || 1);
        const width = Math.max(1, Math.round(rect.width * ratio));
        const height = Math.max(1, Math.round(rect.height * ratio));
        if (canvas.width !== width || canvas.height !== height) {
            canvas.width = width;
            canvas.height = height;
        }
        const context = canvas.getContext('2d');
        context.clearRect(0, 0, width, height);
        const extent = Math.min(width, height);
        const geometry = {
            cx: width / 2,
            cy: height / 2,
            extent,
            ratio,
            visualScale: Math.max(1, Math.min(2.3, extent / (78 * ratio))),
            safeOuterRadius: Math.max(4 * ratio, extent / 2 - 6 * ratio),
        };
        const elapsed = (now - visualizerStartedAt) / 1000;
        boundaryPulse *= 0.91;
        const modes = {
            idle: { level: 0.08, color: [111, 126, 119] },
            listening: { level: Math.max(0.12, audioLevel), color: [49, 170, 123] },
            thinking: { level: 0.22, color: [95, 103, 214] },
            speaking: { level: 0.34 + boundaryPulse * 0.55, color: [133, 92, 214] },
            error: { level: 0.12, color: [211, 83, 83] },
        };
        const config = modes[visualizerMode] || modes.idle;
        const drawers = {
            ring: drawRingVisualizer,
            wave: drawWaveVisualizer,
            orb: drawOrbVisualizer,
            pulse: drawPulseVisualizer,
            spectrum: drawSpectrumVisualizer,
        };
        context.save();
        (drawers[visualizerStyle] || drawRingVisualizer)(context, geometry, config, elapsed);
        context.restore();
        visualizerFrame = requestAnimationFrame(drawVisualizer);
    }

    if (window.OpenConceptPlugins?.register) {
        window.OpenConceptPlugins.register(PLUGIN_ID, openPopup);
    }
    window.addEventListener('openconcept:locale-change', () => {
        refreshPopupLocale().catch(error => showError(error.message));
    });
})();
