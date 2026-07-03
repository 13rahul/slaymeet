<?php
/**
 * Incoming 1:1 SlayMeet ring UI + poll + cross-tab sync.
 * Requires SITE_URL (config). Include once per page (e.g. dashboard sidebar, slaymeet.php).
 */
if (!defined('SITE_URL')) {
    return;
}
if (empty($_SESSION['user_id'])) {
    return;
}
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars(SITE_URL, ENT_QUOTES, 'UTF-8'); ?>/assets/css/pages/slaymeet-call-ui.css?v=<?php echo defined('ASSET_VERSION') ? ASSET_VERSION : '1'; ?>">

<div id="slaymeet-call-overlay" class="slaymeet-call-overlay slaymeet-call-overlay--incoming" role="dialog" aria-labelledby="call-caller-name" aria-modal="true" onclick="if (window.slaylyTapToRing) window.slaylyTapToRing();">
    <div class="slaymeet-call-screen" onclick="event.stopPropagation();">
        <img class="slaymeet-call-banner-avatar" id="call-banner-avatar" src="<?php echo SITE_URL; ?>/assets/img/default-avatar.svg" alt="" aria-hidden="true">
        <div class="slaymeet-call-top">
            <span class="slaymeet-call-peer-name" id="call-caller-name">Incoming call</span>
            <span class="slaymeet-call-timer" id="call-ring-timer">00:00</span>
        </div>
        <div class="slaymeet-call-stage">
            <img class="slaymeet-call-avatar" id="call-caller-avatar" src="<?php echo SITE_URL; ?>/assets/img/default-avatar.svg" alt="">
            <div class="slaymeet-call-avatar slaymeet-call-avatar--initials" id="call-caller-initials" style="display:none;"></div>
            <p class="slaymeet-call-status">Incoming video call</p>
        </div>
        <div class="slaymeet-call-actions slaymeet-call-actions--incoming">
            <button type="button" class="slaymeet-call-btn slaymeet-call-btn--decline" onclick="event.stopPropagation(); answerCall('rejected')">
                <span class="slaymeet-call-btn-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.68 13.31a16 16 0 0 0 3.41 2.6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7 2 2 0 0 1 1.72 2v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.42 19.42 0 0 1-3.33-2.67m-2.67-3.34a19.79 19.79 0 0 1-3.07-8.63A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91"></path><line x1="23" y1="1" x2="1" y2="23"></line></svg>
                </span>
                Decline
            </button>
            <button type="button" class="slaymeet-call-btn slaymeet-call-btn--accept" onclick="event.stopPropagation(); answerCall('accepted')">
                <span class="slaymeet-call-btn-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                </span>
                Accept
            </button>
        </div>
        <button type="button" class="slaymeet-call-expand-btn" id="slaymeet-call-expand-btn" onclick="event.stopPropagation(); if (window.slaylyExpandIncomingCall) window.slaylyExpandIncomingCall();">Expand</button>
        <p class="slaymeet-call-audio-hint" id="call-audio-hint">No ringtone? Tap anywhere once — your browser may block audio until you interact.</p>
    </div>
</div>

<div id="slayly-notify-permission-popup" role="dialog" aria-labelledby="slayly-notify-title" aria-describedby="slayly-notify-body">
    <div class="slayly-notify-popup-card">
        <button type="button" class="slayly-notify-popup-close" id="slayly-notify-popup-close" aria-label="Dismiss">&times;</button>
        <h3 id="slayly-notify-title">Enable notifications</h3>
        <p id="slayly-notify-body" class="slayly-notify-popup-body"></p>
        <div class="slayly-notify-popup-actions">
            <button type="button" class="slayly-notify-btn slayly-notify-btn-secondary" id="slayly-notify-later-btn">Not now</button>
            <button type="button" class="slayly-notify-btn slayly-notify-btn-primary" id="slayly-notify-enable-btn">Enable notifications</button>
        </div>
    </div>
</div>

<?php
$__slaylyParsed = parse_url((string) SITE_URL);
$__slaylyRawPath = isset($__slaylyParsed['path']) ? (string) $__slaylyParsed['path'] : '';
$__slaylyRawPath = rtrim($__slaylyRawPath, '/');
if ($__slaylyRawPath === '' || $__slaylyRawPath === '/') {
    $__slaylyPathPrefix = '';
} else {
    $__slaylyPathPrefix = $__slaylyRawPath;
}
$__slaylySwScope = ($__slaylyPathPrefix === '') ? '/' : $__slaylyPathPrefix . '/';
?>
<script>
(function () {
    let currentActiveCall = null;
    let ringtoneAudio = null;
    /** Cleared after auth/fingerprint 401 — avoids repeated polls until full page reload */
    let slaymeetPollStopped401 = false;
    let slaymeetPollIntervalId = null;
    const currentUserId = <?php echo (int) ($_SESSION['user_id'] ?? 0); ?>;
    /** True only after a successful silent play() unlock (autoplay gesture). */
    let slaylyRingPrimed = false;
    let slaylyCallSwReady = false;
    let ringRetryTimer = null;
    let slaylyTitleFlashTimer = null;
    let slaylyOriginalTitle = null;
    let slaylyRingUiTimer = null;
    let slaylyRingUiStartedAt = 0;
    let slaylyCallUiExpanded = false;
    let slaylyIsCallLeader = false;
    let slaylyLeaderHeartbeatTimer = null;
    let slaylyLeaderLockRelease = null;
    const SLAYLY_TAB_ID = 't_' + Math.random().toString(36).slice(2, 11);
    const SLAYLY_LEADER_KEY = 'slayly_incoming_call_leader_v2';

    function slaylyIsUltraChatPage() {
        var p = (window.location.pathname || '').toLowerCase();
        return p.indexOf('chat.php') !== -1 || p.indexOf('/ultrachat') !== -1
            || p.indexOf('/teamchat') !== -1 || p.indexOf('/chat') !== -1;
    }

    function slaylyShouldUseBannerMode() {
        if (slaylyCallUiExpanded) return false;
        if (document.hidden) return false;
        return !slaylyIsUltraChatPage();
    }

    function slaylyApplyCallUiMode() {
        var overlay = document.getElementById('slaymeet-call-overlay');
        if (!overlay) return;
        if (slaylyShouldUseBannerMode()) {
            overlay.classList.add('slaymeet-call-overlay--banner');
        } else {
            overlay.classList.remove('slaymeet-call-overlay--banner');
        }
    }

    window.slaylyExpandIncomingCall = function () {
        slaylyCallUiExpanded = true;
        slaylyApplyCallUiMode();
    };

    function slaylyClaimLeaderHeartbeat() {
        try {
            localStorage.setItem(SLAYLY_LEADER_KEY, JSON.stringify({ tab: SLAYLY_TAB_ID, ts: Date.now() }));
        } catch (e) { /* ignore */ }
    }

    function slaylyReleaseLeader() {
        slaylyIsCallLeader = false;
        if (slaylyLeaderHeartbeatTimer) {
            clearInterval(slaylyLeaderHeartbeatTimer);
            slaylyLeaderHeartbeatTimer = null;
        }
        if (slaylyLeaderLockRelease) {
            try { slaylyLeaderLockRelease(); } catch (e) { /* ignore */ }
            slaylyLeaderLockRelease = null;
        }
        try {
            var raw = localStorage.getItem(SLAYLY_LEADER_KEY);
            if (raw) {
                var d = JSON.parse(raw);
                if (d && d.tab === SLAYLY_TAB_ID) {
                    localStorage.removeItem(SLAYLY_LEADER_KEY);
                }
            }
        } catch (e) { /* ignore */ }
    }

    function slaylyTryBecomeCallLeader() {
        return new Promise(function (resolve) {
            if (slaylyIsCallLeader) {
                resolve(true);
                return;
            }
            if (navigator.locks && typeof navigator.locks.request === 'function') {
                navigator.locks.request('slayly-incoming-call', { ifAvailable: true }, function (lock) {
                    if (!lock) {
                        resolve(slaylyTryBecomeCallLeaderLs());
                        return;
                    }
                    slaylyIsCallLeader = true;
                    slaylyClaimLeaderHeartbeat();
                    slaylyLeaderHeartbeatTimer = setInterval(slaylyClaimLeaderHeartbeat, 1000);
                    slaylyLeaderLockRelease = function () {
                        /* lock releases when returned promise resolves */
                    };
                    var lockDone;
                    var hold = new Promise(function (r) { lockDone = r; });
                    slaylyLeaderLockRelease = lockDone;
                    resolve(true);
                    return hold;
                }).catch(function () {
                    resolve(slaylyTryBecomeCallLeaderLs());
                });
                return;
            }
            resolve(slaylyTryBecomeCallLeaderLs());
        });
    }

    function slaylyTryBecomeCallLeaderLs() {
        try {
            var now = Date.now();
            var raw = localStorage.getItem(SLAYLY_LEADER_KEY);
            var d = raw ? JSON.parse(raw) : null;
            if (d && d.tab && d.tab !== SLAYLY_TAB_ID && (now - (d.ts || 0)) < 3000) {
                return false;
            }
            slaylyIsCallLeader = true;
            slaylyClaimLeaderHeartbeat();
            if (!slaylyLeaderHeartbeatTimer) {
                slaylyLeaderHeartbeatTimer = setInterval(slaylyClaimLeaderHeartbeat, 1000);
            }
            return true;
        } catch (e) {
            slaylyIsCallLeader = true;
            return true;
        }
    }

    function slaylyResolveAvatarUrl(pic) {
        if (!pic || !String(pic).trim()) {
            return SLAYLY_CALL_BASE + '/assets/img/default-avatar.svg';
        }
        var p = String(pic).trim();
        if (p.indexOf('http://') === 0 || p.indexOf('https://') === 0 || p.indexOf('//') === 0) {
            return p;
        }
        return SLAYLY_CALL_BASE + (p.charAt(0) === '/' ? p : '/' + p);
    }

    function slaylyFormatRingElapsed(ms) {
        var s = Math.max(0, Math.floor(ms / 1000));
        var m = Math.floor(s / 60);
        var r = s % 60;
        return (m < 10 ? '0' : '') + m + ':' + (r < 10 ? '0' : '') + r;
    }

    function slaylyStartRingUiTimer() {
        slaylyRingUiStartedAt = Date.now();
        if (slaylyRingUiTimer) clearInterval(slaylyRingUiTimer);
        var tick = function () {
            var el = document.getElementById('call-ring-timer');
            if (el) {
                el.textContent = slaylyFormatRingElapsed(Date.now() - slaylyRingUiStartedAt);
            }
        };
        tick();
        slaylyRingUiTimer = setInterval(tick, 1000);
    }

    function slaylyStopRingUiTimer() {
        if (slaylyRingUiTimer) {
            clearInterval(slaylyRingUiTimer);
            slaylyRingUiTimer = null;
        }
    }

    function slaylyShowIncomingOverlay() {
        var overlay = document.getElementById('slaymeet-call-overlay');
        if (overlay) overlay.classList.add('is-visible');
        slaylyStartRingUiTimer();
    }

    function slaylyHideIncomingOverlay() {
        var overlay = document.getElementById('slaymeet-call-overlay');
        if (overlay) overlay.classList.remove('is-visible');
        slaylyStopRingUiTimer();
    }

    function slaylyInitials(name) {
        name = String(name || '').trim();
        if (!name) return '?';
        var parts = name.split(/\s+/).filter(Boolean);
        if (parts.length >= 2) return (parts[0].charAt(0) + parts[1].charAt(0)).toUpperCase();
        return name.slice(0, 2).toUpperCase();
    }

    function slaylySetIncomingAvatar(row) {
        var avatarEl = document.getElementById('call-caller-avatar');
        var initialsEl = document.getElementById('call-caller-initials');
        var bannerEl = document.getElementById('call-banner-avatar');
        var name = row.caller_name || 'Caller';
        var raw = String(row.caller_avatar || '').trim();
        if (raw) {
            // Real photo of the person who is calling.
            var url = slaylyResolveAvatarUrl(raw);
            if (avatarEl) {
                avatarEl.src = url;
                avatarEl.alt = name;
                avatarEl.style.display = '';
                avatarEl.onerror = function () {
                    avatarEl.style.display = 'none';
                    if (initialsEl) { initialsEl.textContent = slaylyInitials(name); initialsEl.style.display = ''; }
                };
            }
            if (initialsEl) { initialsEl.style.display = 'none'; }
            if (bannerEl) { bannerEl.src = url; bannerEl.alt = ''; }
        } else {
            // No photo — show the caller's initials (e.g. "RA"), not a blank placeholder.
            if (avatarEl) { avatarEl.style.display = 'none'; }
            if (initialsEl) { initialsEl.textContent = slaylyInitials(name); initialsEl.style.display = ''; }
            if (bannerEl) { bannerEl.src = SLAYLY_CALL_BASE + '/assets/img/default-avatar.svg'; }
        }
    }

    /**
     * Never use SITE_URL hostname in the browser: users open 127.0.0.1 vs localhost vs production host.
     * SW, ringtone MP3, and fetch must use THIS origin + the same path prefix as SITE_URL.
     */
    var SLAYLY_PATH_PREFIX = <?php echo json_encode($__slaylyPathPrefix); ?>;
    var SLAYLY_CALL_BASE = window.location.origin + (SLAYLY_PATH_PREFIX ? SLAYLY_PATH_PREFIX : '');
    var SLAYLY_SW_URL = SLAYLY_CALL_BASE + '/sw-slayly-calls.js';
    var SLAYLY_SW_SCOPE = <?php echo json_encode($__slaylySwScope); ?>;
    var SLAYLY_RING_URL = SLAYLY_CALL_BASE + '/assets/Ringtone/incoming-ring.mp3';
    const slayMeetCallBc = typeof BroadcastChannel !== 'undefined'
        ? new BroadcastChannel('slayly-slaymeet-incoming-v1')
        : null;

    const SLAYLY_NOTIFY_PROMPT_SNOOZE_KEY = 'slayly_notify_prompt_snooze_until';
    const SLAYLY_NOTIFY_PROMPT_DELAY_MS = 1400;

    window.slaylyPrimeCallMedia = primeSlaylyCallMedia;

    /** Runs on user gesture (tap) — browsers allow audio.play() here even when autoplay is blocked. */
    window.slaylyTapToRing = function () {
        try {
            const a = ensureRingtoneObject();
            a.load();
            a.volume = 1;
            a.play().then(function () {
                slaylyRingPrimed = true;
                stopRingRetryLoop();
            }).catch(function (e) {
                console.warn('[Slayly] Could not play ringtone', e);
            });
        } catch (e) { /* ignore */ }
    };

    function ensureRingtoneObject() {
        if (!ringtoneAudio) {
            ringtoneAudio = new Audio(SLAYLY_RING_URL);
            ringtoneAudio.loop = true;
            ringtoneAudio.preload = 'auto';
            ringtoneAudio.addEventListener('error', function () {
                console.warn('[Slayly] Ringtone file failed to load:', SLAYLY_RING_URL);
            });
        }
        return ringtoneAudio;
    }

    function slaylyStartIncomingTitleFlash() {
        if (slaylyTitleFlashTimer) return;
        slaylyOriginalTitle = document.title;
        var flip = true;
        slaylyTitleFlashTimer = setInterval(function () {
            document.title = flip ? ('📞 Incoming call — ' + slaylyOriginalTitle) : slaylyOriginalTitle;
            flip = !flip;
        }, 850);
    }

    function slaylyStopIncomingTitleFlash() {
        if (slaylyTitleFlashTimer) {
            clearInterval(slaylyTitleFlashTimer);
            slaylyTitleFlashTimer = null;
        }
        if (slaylyOriginalTitle != null) {
            document.title = slaylyOriginalTitle;
        }
        slaylyOriginalTitle = null;
    }

    function primeSlaylyCallMedia() {
        if (slaylyRingPrimed) return;
        try {
            const a = ensureRingtoneObject();
            a.load();
            const prevVol = a.volume;
            /* Muted autoplay is allowed on most browsers after a user gesture; unlocks real ringtone playback. */
            a.muted = true;
            a.volume = 1;
            a.play().then(function () {
                slaylyRingPrimed = true;
                a.pause();
                a.currentTime = 0;
                a.muted = false;
                a.volume = prevVol;
            }).catch(function () {
                a.muted = false;
                a.volume = prevVol;
                /* No user gesture yet — keep slaylyRingPrimed false so we retry on next tap. */
            });
        } catch (e) { /* ignore */ }
    }

    function slaylyNotifyPromptSnoozed() {
        try {
            var t = parseInt(localStorage.getItem(SLAYLY_NOTIFY_PROMPT_SNOOZE_KEY) || '0', 10);
            return t > Date.now();
        } catch (e) {
            return false;
        }
    }

    function slaylySnoozeNotifyPrompt() {
        try {
            localStorage.setItem(SLAYLY_NOTIFY_PROMPT_SNOOZE_KEY, String(Date.now() + 7 * 24 * 60 * 60 * 1000));
        } catch (e) { /* ignore */ }
        slaylyHideNotifyPermissionPopup();
    }

    function slaylyHideNotifyPermissionPopup() {
        var el = document.getElementById('slayly-notify-permission-popup');
        if (el) el.style.display = 'none';
    }

    function slaylyRefreshNotifyPermissionPopupContent() {
        var body = document.getElementById('slayly-notify-body');
        var enableBtn = document.getElementById('slayly-notify-enable-btn');
        var title = document.getElementById('slayly-notify-title');
        if (!body || !enableBtn) return;
        if (typeof Notification === 'undefined') return;
        if (Notification.permission === 'denied') {
            if (title) title.textContent = 'Notifications are off';
            body.textContent = 'This site cannot show call alerts while you use other apps. Turn notifications back on in your browser site settings (usually the lock or tune icon next to the address bar), then reload this page.';
            enableBtn.style.display = 'none';
        } else {
            if (title) title.textContent = 'Enable notifications';
            body.textContent = 'Allow notifications so you get alerted for incoming SlayMeet calls and Team Chat messages when Slayly is in the background or another tab.';
            enableBtn.style.display = '';
        }
    }

    function slaylyShowNotifyPermissionPopupIfNeeded(force) {
        if (typeof Notification === 'undefined') return;
        if (typeof window.isSecureContext !== 'undefined' && !window.isSecureContext) return;
        if (Notification.permission === 'granted') {
            slaylyHideNotifyPermissionPopup();
            return;
        }
        if (Notification.permission === 'denied') return;
        if (!force && slaylyNotifyPromptSnoozed()) return;
        var el = document.getElementById('slayly-notify-permission-popup');
        if (!el) return;
        slaylyRefreshNotifyPermissionPopupContent();
        el.style.display = 'flex';
    }

    function slaylyInitNotifyPermissionPopup() {
        var enableBtn = document.getElementById('slayly-notify-enable-btn');
        var laterBtn = document.getElementById('slayly-notify-later-btn');
        var closeBtn = document.getElementById('slayly-notify-popup-close');
        if (enableBtn) {
            enableBtn.addEventListener('click', function () {
                if (typeof Notification === 'undefined') return;
                Notification.requestPermission().then(function (p) {
                    if (p === 'granted') {
                        try { localStorage.removeItem(SLAYLY_NOTIFY_PROMPT_SNOOZE_KEY); } catch (e2) { /* ignore */ }
                        slaylyHideNotifyPermissionPopup();
                    } else if (p === 'denied') {
                        slaylyRefreshNotifyPermissionPopupContent();
                    }
                }).catch(function () {});
            });
        }
        if (laterBtn) laterBtn.addEventListener('click', slaylySnoozeNotifyPrompt);
        if (closeBtn) closeBtn.addEventListener('click', slaylySnoozeNotifyPrompt);
        setTimeout(slaylyShowNotifyPermissionPopupIfNeeded, SLAYLY_NOTIFY_PROMPT_DELAY_MS);
    }

    window.addEventListener('focus', function () {
        if (typeof Notification !== 'undefined' && Notification.permission === 'granted') {
            try { localStorage.removeItem(SLAYLY_NOTIFY_PROMPT_SNOOZE_KEY); } catch (e) { /* ignore */ }
            slaylyHideNotifyPermissionPopup();
        }
    });

    ['pointerdown', 'keydown', 'touchstart'].forEach(function (ev) {
        document.addEventListener(ev, primeSlaylyCallMedia, { capture: true, passive: true });
    });

    async function registerSlaylyCallWorker() {
        if (!('serviceWorker' in navigator)) return;
        if (slaylyCallSwReady) return;
        try {
            const reg = await navigator.serviceWorker.register(SLAYLY_SW_URL, { scope: SLAYLY_SW_SCOPE });
            slaylyCallSwReady = !!reg;
            navigator.serviceWorker.addEventListener('message', function () {});
        } catch (e) {
            console.warn('[Slayly] Call service worker not registered:', e);
        }
    }

    /**
     * Deliver to the call service worker. Do not depend on navigator.serviceWorker.controller — it is often null
     * right after registration even when reg.active exists; Join/Decline actions only run from the SW path.
     */
    function postIncomingCallToSw(payload) {
        if (!('serviceWorker' in navigator)) {
            return Promise.resolve(false);
        }
        const msg = { type: 'SLAYMEET_INCOMING', payload: payload, baseUrl: SLAYLY_CALL_BASE };
        function tryPost(reg) {
            if (!reg || !reg.active) return false;
            try {
                reg.active.postMessage(msg);
                return true;
            } catch (e) {
                console.warn('[Slayly] Call SW postMessage failed', e);
                return false;
            }
        }
        return navigator.serviceWorker.ready.then(function (reg) {
            if (tryPost(reg)) return true;
            return new Promise(function (resolve) {
                var attempts = 0;
                var id = setInterval(function () {
                    attempts += 1;
                    navigator.serviceWorker.ready.then(function (r2) {
                        if (tryPost(r2)) {
                            clearInterval(id);
                            resolve(true);
                        } else if (attempts >= 25) {
                            clearInterval(id);
                            resolve(false);
                        }
                    });
                }, 120);
            });
        }).catch(function () {
            return false;
        });
    }

    function slaylyFallbackPageNotification(payload) {
        try {
            var icon = slaylyResolveAvatarUrl(payload.caller_avatar || '');
            new Notification('Incoming SlayMeet', {
                body: (payload.caller_name || 'Someone') + ' is calling',
                tag: 'slaymeet-call-' + String(payload.id),
                icon: icon,
                requireInteraction: true
            });
        } catch (e) { /* ignore */ }
    }

    /**
     * OS notification whenever permission is granted (including focused tab). Join/Decline require the service worker;
     * without SW we fall back to a plain Notification (no action buttons).
     */
    function showIncomingCallNotifications(payload) {
        if (typeof Notification === 'undefined' || Notification.permission !== 'granted') return;
        function deliver() {
            return registerSlaylyCallWorker().then(function () {
                if (!('serviceWorker' in navigator)) {
                    slaylyFallbackPageNotification(payload);
                    return;
                }
                return postIncomingCallToSw(payload).then(function (ok) {
                    if (!ok) slaylyFallbackPageNotification(payload);
                });
            }).catch(function () {
                slaylyFallbackPageNotification(payload);
            });
        }
        deliver();
        /* Second attempt: first SW post often races install/activate after cold load. */
        setTimeout(function () {
            if (currentActiveCall && String(currentActiveCall.id) === String(payload.id)) {
                deliver();
            }
        }, 500);
    }

    /** Ensure SW has payload fields it requires (id, room_token) or incoming UI is misleading. */
    function normalizeSlaymeetIncomingRow(row) {
        if (!row || row.id == null) return null;
        const callerId = parseInt(row.caller_id || 0, 10);
        if (currentUserId > 0 && callerId > 0 && callerId === currentUserId) {
            // Never ring for own initiated call payloads (self-DM / stale rows / sync noise).
            return null;
        }
        var tok = row.room_token != null ? row.room_token : row.roomToken;
        if (tok == null || String(tok).trim() === '') {
            console.warn('[Slayly] Incoming call row missing room_token', row);
            return null;
        }
        return {
            id: row.id,
            room_token: String(tok),
            caller_name: row.caller_name || row.callerName || 'Team Member',
            caller_avatar: row.caller_avatar || row.callerAvatar || '',
            caller_id: row.caller_id,
            receiver_id: row.receiver_id,
            status: row.status
        };
    }

    function stopRingRetryLoop() {
        if (ringRetryTimer) {
            clearInterval(ringRetryTimer);
            ringRetryTimer = null;
        }
    }

    function startRingRetryLoop() {
        stopRingRetryLoop();
        ringRetryTimer = setInterval(function () {
            if (!currentActiveCall) {
                stopRingRetryLoop();
                return;
            }
            playRingtone();
        }, 2200);
    }

    function handleSlaymeetIncoming(row, fromBroadcast) {
        row = normalizeSlaymeetIncomingRow(row);
        if (!row) return;

        if (currentActiveCall && String(currentActiveCall.id) === String(row.id)) {
            if (slaylyIsCallLeader) {
                playRingtone();
                startRingRetryLoop();
            }
            return;
        }

        currentActiveCall = row;

        registerSlaylyCallWorker().then(function () {
            if (document.hidden) {
                showIncomingCallNotifications(row);
            }
        });

        if (document.hidden) {
            return;
        }

        if (fromBroadcast) {
            if (!slaylyIsCallLeader) {
                return;
            }
        } else {
            slaylyTryBecomeCallLeader().then(function (isLeader) {
                if (!isLeader) {
                    if (slayMeetCallBc) {
                        try {
                            slayMeetCallBc.postMessage({ type: 'incoming', row: row });
                        } catch (e) { /* ignore */ }
                    }
                    return;
                }
                slaylyPresentIncomingCallUi(row, fromBroadcast);
            });
            return;
        }

        slaylyPresentIncomingCallUi(row, fromBroadcast);
    }

    function slaylyPresentIncomingCallUi(row, fromBroadcast) {
        if (!currentActiveCall || String(currentActiveCall.id) !== String(row.id)) {
            return;
        }
        const nameEl = document.getElementById('call-caller-name');
        if (nameEl) nameEl.textContent = row.caller_name || 'Team Member';
        slaylySetIncomingAvatar(row);
        slaylyApplyCallUiMode();
        slaylyShowIncomingOverlay();
        slaylyStartIncomingTitleFlash();
        if (typeof Notification !== 'undefined' && Notification.permission === 'default') {
            slaylyShowNotifyPermissionPopupIfNeeded(true);
        }
        try {
            if (navigator.vibrate) {
                navigator.vibrate([250, 120, 250, 120, 400]);
            }
        } catch (e) { /* ignore */ }
        primeSlaylyCallMedia();
        playRingtone();
        startRingRetryLoop();
        if (slayMeetCallBc && !fromBroadcast) {
            try {
                slayMeetCallBc.postMessage({ type: 'incoming', row: row });
            } catch (e) { /* ignore */ }
        }
    }

    window.addEventListener('slaymeet-incoming', function (ev) {
        if (ev && ev.detail) handleSlaymeetIncoming(ev.detail, false);
    });

    if (slayMeetCallBc) {
        slayMeetCallBc.onmessage = function (ev) {
            if (!ev.data) return;
            if (ev.data.type === 'incoming' && ev.data.row) {
                var normalized = normalizeSlaymeetIncomingRow(ev.data.row);
                if (!normalized) return;
                currentActiveCall = normalized;
                if (slaylyIsCallLeader) {
                    slaylyPresentIncomingCallUi(normalized, true);
                }
            } else if (ev.data.type === 'cleared' && ev.data.callId != null) {
                if (currentActiveCall && String(currentActiveCall.id) === String(ev.data.callId)) {
                    stopRingtone();
                    slaylyHideIncomingOverlay();
                    currentActiveCall = null;
                }
            }
        };
    }

    function playRingtone() {
        try {
            const a = ensureRingtoneObject();
            a.muted = false;
            a.volume = 1;
            a.play().then(function () {
                stopRingRetryLoop();
            }).catch(function (e) {
                console.warn('[Slayly] Ringtone waiting for tap (browser autoplay) or retrying…', e);
            });
        } catch (e) {
            console.warn('[Slayly] Ringtone error', e);
        }
    }

    function stopRingtone() {
        stopRingRetryLoop();
        slaylyStopIncomingTitleFlash();
        if (ringtoneAudio) {
            ringtoneAudio.pause();
            ringtoneAudio.currentTime = 0;
        }
    }

    window.answerCall = async function (status) {
        if (!currentActiveCall) return;
        var callSnapshot = {
            id: currentActiveCall.id,
            room_token: String(currentActiveCall.room_token),
            caller_name: currentActiveCall.caller_name,
            caller_avatar: currentActiveCall.caller_avatar
        };

        stopRingtone();
        slaylyHideIncomingOverlay();
        slaylyCallUiExpanded = false;

        try {
            var fd = new FormData();
            fd.append('call_id', String(callSnapshot.id));
            fd.append('status', status);
            var res = await fetch(SLAYLY_CALL_BASE + '/api/slaymeet/answer_call.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: fd
            });
            var payload = null;
            try {
                payload = await res.json();
            } catch (je) {
                payload = null;
            }
            var ok = res.ok && payload && payload.success === true;

            if (!ok) {
                currentActiveCall = {
                    id: callSnapshot.id,
                    room_token: callSnapshot.room_token,
                    caller_name: callSnapshot.caller_name,
                    caller_avatar: callSnapshot.caller_avatar
                };
                slaylyShowIncomingOverlay();
                slaylyStartIncomingTitleFlash();
                playRingtone();
                startRingRetryLoop();
                var errMsg = (payload && payload.error) ? payload.error : 'Could not update the call. Try again.';
                if (window.slayNotify && typeof window.slayNotify.error === 'function') {
                    window.slayNotify.error(errMsg, 'SlayMeet');
                }
                return;
            }

            var clearedId = callSnapshot.id;
            currentActiveCall = null;
            slaylyReleaseLeader();
            if (slayMeetCallBc) {
                try {
                    slayMeetCallBc.postMessage({ type: 'cleared', callId: clearedId });
                } catch (e) { /* ignore */ }
            }

            if (status === 'accepted') {
                // Same-window: take the callee straight into the meeting in this
                // window (no second tab), matching the caller's same-window flow.
                var roomUrl = SLAYLY_CALL_BASE + '/ultrameet?room=' + encodeURIComponent(callSnapshot.room_token) + '&dm=1';
                window.location.assign(roomUrl);
            }
        } catch (e) {
            console.error(e);
            currentActiveCall = {
                id: callSnapshot.id,
                room_token: callSnapshot.room_token,
                caller_name: callSnapshot.caller_name,
                caller_avatar: callSnapshot.caller_avatar
            };
            slaylyShowIncomingOverlay();
            slaylyStartIncomingTitleFlash();
            playRingtone();
            startRingRetryLoop();
            if (window.slayNotify && typeof window.slayNotify.error === 'function') {
                window.slayNotify.error('Network error. Check your connection and try again.', 'SlayMeet');
            }
        }
    };

    function dismissIncomingBecauseCallEnded(kind) {
        stopRingtone();
        slaylyHideIncomingOverlay();
        slaylyStopIncomingTitleFlash();
        slaylyCallUiExpanded = false;
        slaylyReleaseLeader();
        var cid = currentActiveCall ? currentActiveCall.id : null;
        currentActiveCall = null;
        if (slayMeetCallBc && cid != null) {
            try {
                slayMeetCallBc.postMessage({ type: 'cleared', callId: cid });
            } catch (e) { /* ignore */ }
        }
        if (kind === 'caller_cancelled' && window.slayNotify && typeof window.slayNotify.success === 'function') {
            window.slayNotify.success('The caller cancelled this call.', 'SlayMeet');
        }
        /* kind === 'silent' → no toast (accepted elsewhere or stale state) */
    }

    async function pollSlaymeetOnce() {
        try {
            if (slaymeetPollStopped401) return;
            if (currentActiveCall) {
                var resState = await fetch(
                    SLAYLY_CALL_BASE + '/api/slaymeet/get_call_status.php?call_id=' + encodeURIComponent(String(currentActiveCall.id)),
                    {
                        credentials: 'same-origin',
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                    }
                );
                if (resState.status === 401) {
                    slaymeetPollStopped401 = true;
                    return;
                }
                var j = null;
                try {
                    j = await resState.json();
                } catch (je) {
                    j = null;
                }
                if (j && j.success && j.status && j.status !== 'ringing') {
                    var k = 'silent';
                    if (j.status === 'rejected') {
                        k = 'caller_cancelled';
                    } else if (j.status === 'ended') {
                        k = 'silent';
                    }
                    dismissIncomingBecauseCallEnded(k);
                }
                return;
            }
            const res = await fetch(SLAYLY_CALL_BASE + '/api/slaymeet/poll_calls.php', {
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            if (res.status === 401) {
                slaymeetPollStopped401 = true;
                if (slaymeetPollIntervalId != null) {
                    clearInterval(slaymeetPollIntervalId);
                    slaymeetPollIntervalId = null;
                }
                return;
            }
            if (!res.ok) {
                return;
            }
            const data = await res.json();
            if (data.success && data.incoming) {
                handleSlaymeetIncoming(data.incoming, false);
            }
        } catch (e) {
            console.warn('[Slayly] poll_calls failed', e);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            registerSlaylyCallWorker();
            slaylyInitNotifyPermissionPopup();
        });
    } else {
        registerSlaylyCallWorker();
        slaylyInitNotifyPermissionPopup();
    }

    document.addEventListener('visibilitychange', function () {
        pollSlaymeetOnce();
    });
    window.addEventListener('focus', function () {
        pollSlaymeetOnce();
    });
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) pollSlaymeetOnce();
    });

    slaymeetPollIntervalId = setInterval(pollSlaymeetOnce, 2000);
    pollSlaymeetOnce();

    /** Chat SSE sends `event: reconnect` before closing; poll immediately so calls are not missed across reconnect. */
    window.slaylyPollIncomingSlaymeet = pollSlaymeetOnce;
})();
</script>
