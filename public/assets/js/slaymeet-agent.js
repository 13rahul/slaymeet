/**
 * SlayMeet live agent — Gemini brain + self-hosted Piper TTS (or optional Gemini voice).
 */
(function (global) {
    'use strict';

    /** Built-in + configurable wake name (default Teena — easier for speech recognition than Slayly). */
    const HELP_WAKE_PATTERNS = [
        /\bhelp\s+us\b/i,
        /\bhelp\s+me\b/i,
        /\bwe\s+need\s+help\b/i,
        /\bneed\s+help\b/i,
        /\bhelp\b/i,
    ];

    const WAKE_PATTERNS = [
        /\bhey\s+teena\b/i,
        /\bok\s+teena\b/i,
        /\bhi\s+teena\b/i,
        /\bteena\b/i,
        /\bslayly\b/i,
        /\bhey\s+slayly\b/i,
        /\bok\s+slayly\b/i,
        /\b@slayly\b/i,
        /\bassistant\b/i,
        /\bmeeting\s+assistant\b/i,
        /\bai\s+assistant\b/i,
    ].concat(HELP_WAKE_PATTERNS);

    /** Common Chrome speech-to-text mis-hearings of Slayly / Teena. */
    const WAKE_STT_ALIASES = [
        'slayly', 'slay lee', 'slaley', 'slailey', 'slaily', 'display', 'slowly',
        'stanley', 'sleigh', 'slate lee', 'teena', 'tina', 'teen ah', 'hee na',
    ];

    const DIRECT_PATTERNS = [
        /\?$/,
        /\bcan you\b/i,
        /\bcould you\b/i,
        /\bwhat is\b/i,
        /\bhow do\b/i,
        /\bplease\b/i,
        /\bsummarize\b/i,
        /\baction items?\b/i,
        /\btake\s+notes?\b/i,
        /\bhello\b/i,
        /\bhi\b/i,
    ];

    class SlayMeetAgent {
        constructor(cfg) {
            this.cfg = cfg;
            this.history = [];
            this.transcript = [];
            this.pendingUtterance = null;
            this.debounceTimer = null;
            this.isSpeaking = false;
            this.isThinking = false;
            this.lastSpeaker = '';
            this.debounceMs = 900;
            this.captionBuffer = new Map();
            this.captionFlushTimers = new Map();
            this._greeted = false;
            this._lastIngestKey = '';
            this._lastIngestAt = 0;
            this._lastSpokenText = '';
            this._conversationalUntil = 0;
            this._pttUntil = 0;
            this.cfg.speakEnabled = cfg.speakEnabled !== false;
            this.cfg.greetingEnabled = cfg.greetingEnabled !== false;
            this.cfg.conversationalCaptions = cfg.conversationalCaptions !== false;
            this.cfg.listenMode = cfg.listenMode === 'always' ? 'always' : 'wake';
            this.cfg.assistantWakeName = String(cfg.assistantWakeName || 'Teena').trim() || 'Teena';
            this.cfg.assistantDisplayName = String(cfg.assistantDisplayName || 'Ultra Looper AI Assistant').trim() || 'Ultra Looper AI Assistant';
            this.cfg.hostSpeakerName = String(cfg.hostSpeakerName || '').trim();
            this._wakePatterns = this._buildWakePatterns();
        }

        _extendConversational(ms = 120000) {
            this._conversationalUntil = Date.now() + ms;
        }

        /** Tap/hold "Ask AI" — no wake word needed for this window. */
        enablePushToTalk(ms = 45000) {
            this._pttUntil = Date.now() + ms;
            this._extendConversational(ms);
        }

        _buildWakePatterns() {
            const name = this.cfg.assistantWakeName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            const extra = name.length
                ? [
                      new RegExp(`\\bhey\\s+${name}\\b`, 'i'),
                      new RegExp(`\\bok\\s+${name}\\b`, 'i'),
                      new RegExp(`\\bhi\\s+${name}\\b`, 'i'),
                      new RegExp(`\\b${name}\\b`, 'i'),
                  ]
                : [];
            return WAKE_PATTERNS.concat(extra);
        }

        _normalizeWakeText(text) {
            return String(text || '')
                .toLowerCase()
                .replace(/[^\w\s]/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();
        }

        _hasWakeWord(text) {
            const norm = this._normalizeWakeText(text);
            if (!norm) return false;
            if (this._wakePatterns.some((re) => re.test(norm))) return true;
            const tokens = norm.split(' ');
            for (const alias of WAKE_STT_ALIASES) {
                if (norm.includes(alias)) return true;
            }
            const wake = this._normalizeWakeText(this.cfg.assistantWakeName);
            if (wake && (norm.includes(wake) || tokens.includes(wake))) return true;
            return false;
        }

        _isHostSpeaker(speaker) {
            const host = this.cfg.hostSpeakerName;
            if (!host) return true;
            return String(speaker || '').trim().toLowerCase() === host.toLowerCase();
        }

        _buildGreeting() {
            const display = this.cfg.assistantDisplayName || 'Ultra Looper AI Assistant';
            let hostFirst = 'there';
            let humanCount = 1;
            if (typeof this.cfg.getGreetingContext === 'function') {
                try {
                    const ctx = this.cfg.getGreetingContext() || {};
                    hostFirst = String(ctx.hostFirstName || '').trim() || hostFirst;
                    humanCount = Math.max(1, parseInt(ctx.humanCount, 10) || 1);
                } catch (_) {}
            } else if (this.cfg.hostSpeakerName) {
                hostFirst = String(this.cfg.hostSpeakerName).trim().split(/\s+/)[0] || hostFirst;
            }
            if (humanCount <= 1) {
                return `Hello ${hostFirst}, I'm the ${display}. I'm here to help — say help me or ask in chat if you need anything.`;
            }
            return `Hi team, I'm the ${display}. I'm here to help — say help me or ask in chat if you need anything.`;
        }

        _isEchoOfAgentSpeech(text) {
            const last = String(this._lastSpokenText || '').trim().toLowerCase();
            const cur = String(text || '').trim().toLowerCase();
            if (!last || !cur || last.length < 12) return false;
            if (cur === last) return true;
            if (last.includes(cur) && cur.length > last.length * 0.35) return true;
            if (cur.includes(last) && last.length > cur.length * 0.35) return true;
            return false;
        }

        async start() {
            const voiceNote = this.cfg.speakEnabled ? 'voice + chat/captions' : 'chat/captions only (no voice)';
            console.log('[SlayMeetAgent] Active —', voiceNote);
            if (this.cfg.speakEnabled) {
                await this._ensureBotAudioReady();
            }
            if (!this.cfg.greetingEnabled || this._greeted) {
                return;
            }
            this._greeted = true;
            const display = this.cfg.assistantDisplayName;
            const hello = this._buildGreeting();
            this._lastSpokenText = hello;
            this.transcript.push({
                ts: new Date().toISOString(),
                speaker: display,
                text: hello,
                source: 'agent',
            });
            if (this.cfg.addChatLine) {
                this.cfg.addChatLine(display, hello, false);
            }
            if (this.cfg.sendSignal) {
                await this.cfg.sendSignal('system', {
                    type: 'chat',
                    from_name: display,
                    message: hello,
                });
                await this.cfg.sendSignal('system', {
                    type: 'mediastate',
                    sub: 'mic',
                    enabled: true,
                });
            }
            if (this.cfg.speakEnabled) {
                try {
                    await this.speak(hello);
                } catch (speakErr) {
                    console.warn('[SlayMeetAgent] Greeting voice failed (check Piper TTS on server)', speakErr);
                    if (this.cfg.onAudioUnlockNeeded) this.cfg.onAudioUnlockNeeded();
                }
            }
        }

        /** Replay last line after user taps Enable Audio (browser autoplay unlock). */
        async retryPendingSpeech() {
            if (!this.cfg.speakEnabled || !this._lastSpokenText || this.isSpeaking || this.isThinking) {
                return;
            }
            const line = this._lastSpokenText;
            this._lastSpokenText = '';
            try {
                await this.speak(line);
            } catch (e) {
                this._lastSpokenText = line;
                if (this.cfg.onAudioUnlockNeeded) this.cfg.onAudioUnlockNeeded();
                throw e;
            }
        }

        async _ensureBotAudioReady() {
            if (global.botAudioContext && global.botAudioContext.state === 'suspended') {
                try {
                    await global.botAudioContext.resume();
                } catch (e) {
                    console.warn('[SlayMeetAgent] AudioContext resume', e);
                }
            }
        }

        ingest(speaker, text, source) {
            const clean = String(text || '').trim();
            if (!clean || clean.length < 2) return;
            if (/^(slayly(\s+ai)?(\s+assistant)?|ultra\s*looper(\s+ai(\s+assistant)?)?|teena)$/i.test(speaker)) return;
            if (this._isEchoOfAgentSpeech(clean)) return;

            const dedupeKey = `${speaker}|${source}|${clean}`;
            const now = Date.now();
            if (dedupeKey === this._lastIngestKey && now - this._lastIngestAt < 2500) {
                return;
            }
            this._lastIngestKey = dedupeKey;
            this._lastIngestAt = now;

            if (source === 'caption') {
                this._bufferCaption(speaker, clean);
                return;
            }

            this._commitUtterance(speaker, clean, source);
        }

        _bufferCaption(speaker, fragment) {
            const prev = this.captionBuffer.get(speaker) || '';
            const merged = prev ? `${prev} ${fragment}`.trim() : fragment;
            this.captionBuffer.set(speaker, merged);

            if (this.captionFlushTimers.has(speaker)) {
                clearTimeout(this.captionFlushTimers.get(speaker));
            }
            this.captionFlushTimers.set(
                speaker,
                setTimeout(() => {
                    const full = (this.captionBuffer.get(speaker) || '').trim();
                    this.captionBuffer.delete(speaker);
                    this.captionFlushTimers.delete(speaker);
                    if (full) {
                        this._commitUtterance(speaker, full, 'caption');
                    }
                }, 1200)
            );
        }

        _commitUtterance(speaker, clean, source) {
            const ts = new Date().toISOString();
            this.transcript.push({ ts, speaker, text: clean, source });
            if (this.transcript.length > 500) {
                this.transcript.shift();
            }
            if (global.ingestMeetingTranscript) {
                global.ingestMeetingTranscript(speaker, clean, source);
            }

            if (!this._shouldRespond(clean, source, speaker)) {
                return;
            }

            const utterance = `${speaker} (${source}): ${clean}`;
            if (this.isThinking) {
                this.pendingUtterance = utterance;
                return;
            }
            if (this.isSpeaking) {
                this.pendingUtterance = utterance;
                return;
            }

            this.lastSpeaker = speaker;
            this._scheduleResponse(utterance);
        }

        _shouldRespond(text, source, speaker = '') {
            const lower = text.toLowerCase();
            if (this._hasWakeWord(text)) {
                this._extendConversational();
                return true;
            }
            if (DIRECT_PATTERNS.some((re) => re.test(lower))) return true;
            if (lower.includes('?') && lower.length > 6) return true;

            if (source === 'chat') {
                if (!this._isHostSpeaker(speaker)) {
                    return this._hasWakeWord(text) || DIRECT_PATTERNS.some((re) => re.test(lower));
                }
                return true;
            }

            if (source === 'caption') {
                if (this._pttUntil > Date.now()) {
                    return text.length >= 4;
                }
                if (this.cfg.listenMode === 'always') {
                    return text.length >= 12
                        && (lower.includes('?') || DIRECT_PATTERNS.some((re) => re.test(lower)));
                }
                if (DIRECT_PATTERNS.some((re) => re.test(lower))) return true;
                if (this.cfg.conversationalCaptions && this._conversationalUntil > Date.now()) {
                    return text.length >= 8;
                }
            }
            return false;
        }

        _scheduleResponse(utterance) {
            this.pendingUtterance = utterance;
            if (this.debounceTimer) clearTimeout(this.debounceTimer);
            this.debounceTimer = setTimeout(() => this._processPending(), this.debounceMs);
        }

        async _processPending() {
            if (!this.pendingUtterance || this.isThinking || this.isSpeaking) return;
            const utterance = this.pendingUtterance;
            this.pendingUtterance = null;
            this.isThinking = true;

            try {
                const reply = await this._askServer(utterance);
                if (!reply) return;

                this.history.push({ role: 'user', text: utterance });
                this.history.push({ role: 'model', text: reply });
                if (this.history.length > 40) {
                    this.history = this.history.slice(-40);
                }

                const display = this.cfg.assistantDisplayName;
                this.transcript.push({
                    ts: new Date().toISOString(),
                    speaker: display,
                    text: reply,
                    source: 'agent',
                });

                if (this.cfg.addChatLine) {
                    this.cfg.addChatLine(display, reply, false);
                }
                if (this.cfg.sendSignal) {
                    await this.cfg.sendSignal('system', {
                        type: 'chat',
                        from_name: display,
                        message: reply,
                    });
                    await this.cfg.sendSignal('system', {
                        type: 'mediastate',
                        sub: 'mic',
                        enabled: true,
                    });
                }
                try {
                    await this.speak(reply);
                } catch (speakErr) {
                    console.warn('[SlayMeetAgent] Voice failed — reply is in meeting chat', speakErr);
                    if (this.cfg.onAudioUnlockNeeded) this.cfg.onAudioUnlockNeeded();
                    if (this.cfg.sendSignal) {
                        await this.cfg.sendSignal('system', {
                            type: 'chat',
                            from_name: display,
                            message: '(Voice unavailable — check GEMINI_API_KEY on server.) ' + reply,
                        });
                    }
                }
            } catch (err) {
                console.error('[SlayMeetAgent]', err);
                if (this.cfg.addChatLine) {
                    this.cfg.addChatLine(this.cfg.assistantDisplayName, 'Sorry, I had trouble thinking. Try again.', false);
                }
            } finally {
                this.isThinking = false;
                this._extendConversational();
                if (this.pendingUtterance && !this.isSpeaking) {
                    const next = this.pendingUtterance;
                    this.pendingUtterance = null;
                    this._scheduleResponse(next);
                }
            }
        }

        meetingContext() {
            return this.transcript
                .slice(-24)
                .map((r) => `[${r.ts}] ${r.speaker}: ${r.text}`)
                .join('\n');
        }

        async _askServer(utterance) {
            const res = await fetch(`${this.cfg.siteUrl}/api/slaymeet/agent_respond.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    bot_token: this.cfg.botToken,
                    message: utterance,
                    meeting_context: this.meetingContext(),
                    history: this.history,
                }),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.success) {
                throw new Error(data.message || 'Agent brain request failed');
            }
            return String(data.reply || '').trim();
        }

        async speak(text) {
            if (!text || !this.cfg.speakEnabled) return;
            await this._ensureBotAudioReady();
            global.botIsSpeaking = true;
            this.isSpeaking = true;

            const chunks = this._chunkForSpeech(text);
            for (const chunk of chunks) {
                if (global.botIsSpeaking === false) break;
                await this._speakChunkGemini(chunk);
            }

            this.isSpeaking = false;
            global.botIsSpeaking = false;
            if (this.pendingUtterance && !this.isThinking) {
                const next = this.pendingUtterance;
                this.pendingUtterance = null;
                this._scheduleResponse(next);
            }
        }

        _chunkForSpeech(text) {
            const parts = [];
            let buf = '';
            for (const sentence of text.split(/(?<=[.!?])\s+/)) {
                const s = sentence.trim();
                if (!s) continue;
                if ((buf + ' ' + s).length > 220) {
                    if (buf) parts.push(buf);
                    buf = s;
                } else {
                    buf = buf ? `${buf} ${s}` : s;
                }
            }
            if (buf) parts.push(buf);
            return parts.length ? parts : [text];
        }

        async _speakChunkGemini(chunk) {
            const res = await fetch(`${this.cfg.siteUrl}/api/slaymeet/agent_tts.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    bot_token: this.cfg.botToken,
                    text: chunk,
                    voice: this.cfg.assistantWakeVoice || 'en_US-amy-medium',
                }),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.success || !data.audio_base64) {
                console.warn('[SlayMeetAgent] TTS failed (' + (data.engine || 'unknown') + ')', data.message || res.status);
                return this._speakChunkBrowserFallback(chunk);
            }
            const binary = atob(data.audio_base64);
            const bytes = new Uint8Array(binary.length);
            for (let i = 0; i < binary.length; i++) {
                bytes[i] = binary.charCodeAt(i);
            }
            await this._playAudioBytes(bytes.buffer);
        }

        async _speakChunkBrowserFallback(chunk) {
            console.warn('[SlayMeetAgent] Piper TTS failed — voice skipped (Gemini fallback disabled for testing)');
            return Promise.resolve();
        }

        _playAudioBytes(arrayBuffer) {
            return new Promise((resolve) => {
                const ctx = global.botAudioContext;
                const dest = global.botAudioDestination;
                if (!ctx || !dest) {
                    const blob = new Blob([arrayBuffer], { type: 'audio/wav' });
                    const url = URL.createObjectURL(blob);
                    const audio = new Audio(url);
                    audio.onended = () => {
                        URL.revokeObjectURL(url);
                        resolve();
                    };
                    audio.onerror = () => {
                        URL.revokeObjectURL(url);
                        resolve();
                    };
                    audio.play().catch(() => {
                        if (this.cfg.onAudioUnlockNeeded) this.cfg.onAudioUnlockNeeded();
                        resolve();
                    });
                    return;
                }
                ctx.decodeAudioData(
                    arrayBuffer.slice(0),
                    (buffer) => {
                        const source = ctx.createBufferSource();
                        source.buffer = buffer;
                        source.connect(dest);
                        source.onended = () => resolve();
                        try {
                            source.start(0);
                        } catch (_) {
                            resolve();
                        }
                    },
                    () => resolve()
                );
            });
        }

        transcriptText() {
            return this.transcript
                .map((r) => `[${r.ts}] ${r.speaker}: ${r.text}`)
                .join('\n');
        }

        buildSavePayload(summaryText) {
            return {
                company_id: this.cfg.companyId,
                user_id: this.cfg.userId,
                room_name: this.cfg.roomToken,
                transcript: this.transcriptText(),
                summary: summaryText || 'Meeting notes from Ultra Looper AI Assistant.',
            };
        }

        saveTranscriptBeacon() {
            const transcriptText = this.transcriptText();
            if (!this.cfg.companyId || !this.cfg.userId || !transcriptText.trim()) {
                return;
            }
            const daemon = global.SlayMeetAgentDaemonToken || 'slayly_agent_daemon_2026_secure';
            const payload = this.buildSavePayload(
                'Meeting ended — full transcript attached. Open Slaydocs → Workplace → Transcripts.'
            );
            payload.daemon_token = daemon;
            const blob = new Blob([JSON.stringify(payload)], { type: 'application/json' });
            const ok = navigator.sendBeacon(
                `${this.cfg.siteUrl}/api/slaymeet/save_transcript.php`,
                blob
            );
            if (!ok) {
                return this.saveTranscript();
            }
        }

        async saveTranscript() {
            if (!this.cfg.companyId || !this.cfg.userId) return;
            const transcriptText = this.transcriptText();
            if (!transcriptText.trim()) return;

            let summaryText = 'Meeting summary from Ultra Looper AI Assistant.';
            try {
                const res = await this._askServer(
                    'Summarize this meeting for a Slaydoc recap. Use markdown bullets for decisions and action items.\n\n' +
                        transcriptText.slice(-12000)
                );
                if (res) summaryText = res;
            } catch (e) {
                console.warn('[SlayMeetAgent] Summary failed', e);
            }

            const daemon = global.SlayMeetAgentDaemonToken || 'slayly_agent_daemon_2026_secure';
            const res = await fetch(`${this.cfg.siteUrl}/api/slaymeet/save_transcript.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Authorization: `Bearer ${daemon}`,
                },
                body: JSON.stringify(this.buildSavePayload(summaryText)),
                keepalive: true,
            });
            const data = await res.json().catch(() => ({}));
            if (data.success) {
                console.log('[SlayMeetAgent] Transcript saved', data.document_id);
                if (this.cfg.notifyOk) {
                    this.cfg.notifyOk('Transcript saved to Workplace → Transcripts');
                }
            } else {
                console.warn('[SlayMeetAgent] Transcript save failed', data.message);
            }
        }
    }

    global.SlayMeetAgent = SlayMeetAgent;
    global.UltraMeetAgent = SlayMeetAgent;
})(typeof window !== 'undefined' ? window : globalThis);
