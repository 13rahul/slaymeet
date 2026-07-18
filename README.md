SlayMeet
========

Self-hosted video meetings with **Teena**, an in-room AI assistant (wake word, Gemini brain, Piper TTS). Built on [LiveKit](https://livekit.io) (Apache-2.0). SlayMeet is not affiliated with LiveKit Inc.

Showcase: [ultralooper.com](https://ultralooper.com)

## Quick start (Docker)

```bash
cp .env.example .env
# Set GEMINI_API_KEY and SLAYMEET_BOT_SECRET in .env

docker compose up --build
```

1. Open http://localhost:8080/login  
2. Sign in: `admin@localhost` / `password` (change after first login)  
3. Open http://localhost:8080/meet — instant room is created  
4. Click **Ask AI** and say **"Hey Teena"**

## Environment

| Variable | Required | Description |
|----------|----------|-------------|
| `LIVEKIT_URL` | Yes | Browser WebSocket URL (e.g. `ws://localhost:7880`) |
| `LIVEKIT_API_KEY` | Yes | Must match `deploy/livekit/livekit.yaml` |
| `LIVEKIT_API_SECRET` | Yes | JWT signing secret |
| `SLAYMEET_BOT_SECRET` | Yes | HMAC secret for AI bot tokens |
| `GEMINI_API_KEY` | For Teena brain | Google Gemini API key |
| `SLAYMEET_TTS_ENGINE` | No | `piper` (default) or `gemini` |
| `SLAYMEET_PIPER_*` | For local TTS | See Piper TTS notes in `deploy/ULTRAMEET_PIPER.md` |

## Teena (AI agent)

- **Brain:** Gemini (`agent_respond.php`) — bring your own API key  
- **Voice:** Piper TTS by default (`agent_tts.php`) — self-hosted, no cloud TTS required  
- **Wake word:** "Teena" (configurable in `slaymeet-agent.js`)

Install Piper:

```bash
bash deploy/install-piper-tts.sh   # Linux
# or
powershell deploy/install-piper-tts.ps1   # Windows
php scripts/verify-piper-tts.php
```

## Project layout

```
app/SlayMeet/          # Domain + APIs + Speech (canonical code)
public/api/slaymeet/   # Thin stubs → app/SlayMeet/Http/Api/
public/meet.php        # Meeting UI
deploy/livekit/        # LiveKit server config
database/schema.sql    # MySQL schema
```

## API

All endpoints under `/api/slaymeet/` — room lifecycle, signaling, calls, Teena (`invite_agent`, `agent_respond`, `agent_tts`).

## Related

SlayMeet is the open-source meeting engine. The commercial Workplace Suite, CRM, and product showcase live at [ultralooper.com](https://ultralooper.com).

## License

Apache-2.0. Copyright 2026 Fundaking Media OPC Pvt Ltd. See [LICENSE](LICENSE) and [NOTICE](NOTICE).

## Security

Report issues per [SECURITY.md](SECURITY.md). Rotate `SLAYMEET_BOT_SECRET` and default admin password before production.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).
