/**
 * SlayMeet — self-hosted LiveKit SFU bridge (v1.15 client).
 * Hooks into slaymeet.php tile/bind helpers via configure().
 */
(function (global) {
    'use strict';

    const LK = global.LivekitClient;
    if (!LK) {
        global.SlayMeetLiveKit = {
            isActive: () => false,
            configure: () => {},
            connect: async () => { throw new Error('LiveKit client not loaded'); },
            publishLocalStream: async () => {},
            disconnect: async () => {},
            setMicEnabled: async () => {},
            setCameraEnabled: async () => {},
            setScreenShareEnabled: async () => false,
            switchMicrophone: async () => {},
            switchCamera: async () => {}
        };
        return;
    }

    const { Room, RoomEvent, Track } = LK;

    let room = null;
    let active = false;
    let hooks = {};
    const remoteStreams = new Map();
    const remoteScreenTracks = new Map();

    function parseUserId(participant) {
        const id = parseInt(String(participant?.identity || ''), 10);
        return Number.isFinite(id) && id > 0 ? id : 0;
    }

    function getHooks() {
        return hooks;
    }

    function streamForUser(userId) {
        if (!remoteStreams.has(userId)) {
            remoteStreams.set(userId, new MediaStream());
        }
        return remoteStreams.get(userId);
    }

    function replaceStreamTrack(stream, track) {
        if (!stream || !track) return;
        const kind = track.kind;
        stream.getTracks()
            .filter((t) => t.kind === kind && t.id !== track.id)
            .forEach((t) => {
                try { stream.removeTrack(t); } catch (_) {}
            });
        if (!stream.getTracks().some((t) => t.id === track.id)) {
            stream.addTrack(track);
        }
    }

    function buildDisplayStream(userId) {
        const base = streamForUser(userId);
        const screen = remoteScreenTracks.get(userId);
        const out = new MediaStream();
        base.getAudioTracks().forEach((t) => out.addTrack(t));
        if (screen && screen.readyState !== 'ended') {
            out.addTrack(screen);
        } else {
            base.getVideoTracks().forEach((t) => out.addTrack(t));
        }
        return out;
    }

    function notifyRemote(userId, participant) {
        const h = getHooks();
        if (!h.bindRemote) return;
        const name = participant?.name || participant?.metadata || `User ${userId}`;
        h.bindRemote(userId, buildDisplayStream(userId), name);
        if (h.updateConnectionState) h.updateConnectionState(userId, 'connected');
    }

    function clearRemote(userId) {
        remoteStreams.delete(userId);
        remoteScreenTracks.delete(userId);
        const h = getHooks();
        if (h.removeRemote) h.removeRemote(userId);
    }

    function handleTrackSubscribed(track, publication, participant) {
        const userId = parseUserId(participant);
        if (!userId) return;
        const mediaTrack = track.mediaStreamTrack;
        if (!mediaTrack) return;

        if (publication?.source === Track.Source.ScreenShare || publication?.source === Track.Source.ScreenShareAudio) {
            if (publication.source === Track.Source.ScreenShare) {
                remoteScreenTracks.set(userId, mediaTrack);
                const h = getHooks();
                if (h.onScreenShare) h.onScreenShare(userId, true);
            }
        } else {
            replaceStreamTrack(streamForUser(userId), mediaTrack);
        }
        notifyRemote(userId, participant);
    }

    function handleTrackUnsubscribed(track, publication, participant) {
        const userId = parseUserId(participant);
        if (!userId) return;
        const stream = remoteStreams.get(userId);
        const mediaTrack = track.mediaStreamTrack;
        if (stream && mediaTrack) {
            try { stream.removeTrack(mediaTrack); } catch (_) {}
        }
        if (publication?.source === Track.Source.ScreenShare) {
            remoteScreenTracks.delete(userId);
            const h = getHooks();
            if (h.onScreenShare) h.onScreenShare(userId, false);
        }
        const remaining = stream ? stream.getTracks().length : 0;
        const hasScreen = remoteScreenTracks.has(userId);
        if (!remaining && !hasScreen) {
            clearRemote(userId);
            return;
        }
        notifyRemote(userId, participant);
    }

    function wireRoomEvents(r) {
        r.on(RoomEvent.TrackSubscribed, handleTrackSubscribed);
        r.on(RoomEvent.TrackUnsubscribed, handleTrackUnsubscribed);
        r.on(RoomEvent.ParticipantDisconnected, (participant) => {
            const userId = parseUserId(participant);
            if (userId) clearRemote(userId);
        });
        r.on(RoomEvent.ActiveSpeakersChanged, (speakers) => {
            const h = getHooks();
            if (!h.setSpeaking) return;
            const activeIds = new Set(
                (speakers || []).map((p) => parseUserId(p)).filter(Boolean)
            );
            remoteStreams.forEach((_stream, uid) => {
                h.setSpeaking(uid, activeIds.has(uid));
            });
        });
        r.on(RoomEvent.Disconnected, () => {
            active = false;
        });
        r.on(RoomEvent.LocalTrackUnpublished, (publication) => {
            if (publication?.source !== Track.Source.ScreenShare) return;
            const h = getHooks();
            if (h.onLocalScreenShareEnd) h.onLocalScreenShareEnd();
        });
    }

    function ingestExistingParticipants(r) {
        r.remoteParticipants.forEach((participant) => {
            participant.trackPublications.forEach((pub) => {
                if (pub.track && pub.isSubscribed) {
                    handleTrackSubscribed(pub.track, pub, participant);
                }
            });
        });
    }

    global.SlayMeetLiveKit = {
        configure(nextHooks) {
            hooks = Object.assign({}, hooks, nextHooks || {});
        },

        isActive() {
            return active && !!room;
        },

        getRoom() {
            return room;
        },

        async connect(url, token) {
            if (!url || !token) throw new Error('LiveKit URL and token required');
            if (room) {
                try { await room.disconnect(); } catch (_) {}
                room = null;
            }
            const r = new Room({ adaptiveStream: true, dynacast: true });
            wireRoomEvents(r);
            await r.connect(url, token, { autoSubscribe: true });
            room = r;
            active = true;
            ingestExistingParticipants(r);
            return r;
        },

        async publishLocalStream(localStream) {
            if (!room || !localStream) return;
            const lp = room.localParticipant;
            for (const track of localStream.getAudioTracks()) {
                await lp.publishTrack(track, { source: Track.Source.Microphone });
            }
            for (const track of localStream.getVideoTracks()) {
                await lp.publishTrack(track, { source: Track.Source.Camera });
            }
        },

        async disconnect() {
            active = false;
            remoteStreams.clear();
            remoteScreenTracks.clear();
            if (!room) return;
            try {
                await room.disconnect();
            } catch (_) {}
            room = null;
        },

        async setMicEnabled(enabled) {
            if (!room) return;
            await room.localParticipant.setMicrophoneEnabled(!!enabled);
        },

        async setCameraEnabled(enabled) {
            if (!room) return;
            await room.localParticipant.setCameraEnabled(!!enabled);
        },

        async setScreenShareEnabled(enabled) {
            if (!room) return;
            return room.localParticipant.setScreenShareEnabled(!!enabled);
        },

        async switchMicrophone(deviceId) {
            if (!room) return;
            await room.switchActiveDevice('audioinput', deviceId || '');
        },

        async switchCamera(deviceId) {
            if (!room) return;
            await room.switchActiveDevice('videoinput', deviceId || '');
        }
    };
})(typeof window !== 'undefined' ? window : globalThis);
