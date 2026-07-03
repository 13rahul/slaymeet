# LiveKit on VPS (SlayMeet SFU)

Self-hosted LiveKit — **no LiveKit Cloud subscription**. Keys are yours; define them in `livekit.yaml` and the app `.env`.

## App `.env` (web host)

```env
LIVEKIT_API_KEY=slaymeet_api_key
LIVEKIT_API_SECRET=<long-random-secret>
LIVEKIT_URL=wss://ultralooper.com/livekit/
```

`LIVEKIT_API_SECRET` must match the value under `keys:` in `livekit.yaml`. If the secret is empty, SlayMeet falls back to mesh P2P.

Rotate leaked secrets: `deploy/vps-rotate-livekit.sh` (updates `/etc/livekit/livekit.yaml` + app `.env`).

## Nginx (TLS / WSS)

Public clients connect over **WSS** only. Nginx terminates TLS and proxies to LiveKit on localhost:

```nginx
location /livekit/ {
    proxy_pass http://127.0.0.1:7880/;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_read_timeout 86400;
}
```

Reload: `sudo nginx -t && sudo systemctl reload nginx`

## Security checklist

1. **Do not expose `:7880` publicly** — bind LiveKit HTTP/WebSocket to `127.0.0.1` when possible; only nginx should be on `443`.
2. **Use strong random secrets** — never deploy the example secret from `.env.example`.
3. **Production URL must be `wss://`** — `join_room.php` upgrades accidental `ws://` in prod `.env`.
4. **JWT room name** — tokens use `public_token` (unique per meeting), not display `room_name`.
5. **JWT TTL** — 6 hours; re-join mints a fresh token.

## Firewall

WebRTC media requires UDP. Typical rules:

```bash
sudo ufw allow 7881/tcp    # RTC TCP (if not localhost-only)
sudo ufw allow 50000:60000/udp
```

Also configure TURN (`SLAYMEET_TURN_*` in app `.env`) for strict corporate NATs.

## Docker (optional)

```bash
cd deploy/livekit
docker compose up -d
```

Uses `network_mode: host` — see `livekit.yaml` for ports (`7880` HTTP/WS, `7881` RTC TCP, UDP `50000–60000`).

## Local dev

Run LiveKit locally (docker or binary) and set:

```env
LIVEKIT_URL=ws://127.0.0.1:7880
```

Without LiveKit running locally, SlayMeet uses mesh P2P automatically.
