# SlayMeet Piper TTS (server)

Teena speaks using **self-hosted Piper** — not a background service. Each reply runs `piper` once via PHP `proc_open` and returns WAV to the browser.

## One-time install

```bash
cd /path/to/slaymeet
bash deploy/install-piper-tts.sh
php scripts/verify-piper-tts.php
```

Windows:

```powershell
powershell -ExecutionPolicy Bypass -File deploy/install-piper-tts.ps1
php scripts/verify-piper-tts.php
```

## Required `.env` keys

```env
SLAYMEET_TTS_ENGINE=piper
SLAYMEET_PIPER_HOME=storage/piper
SLAYMEET_PIPER_VOICE=en_US-amy-medium
GEMINI_API_KEY=your-key-here
SLAYMEET_BOT_SECRET=long-random-string
```

## Requirements

- Linux x86_64 or arm64 (VPS)
- `curl`, `tar`
- PHP `proc_open` **not** in `disable_functions`
- `www-data` can execute `storage/piper/piper/piper` and read `storage/piper/voices/*.onnx`
- `GEMINI_API_KEY` in secrets (brain only; voice is Piper)

## Troubleshooting

| Symptom | Fix |
|--------|-----|
| Chat works, no voice | Run `php deploy/verify-piper-tts.php` on server |
| `proc_open` disabled | Edit `php.ini` / pool config, remove from `disable_functions`, reload php-fpm |
| `Piper model not found` | Re-run `install-piper-tts.sh` |
| 500 on `/api/slaymeet/agent_tts.php` | Check `storage/logs` / PHP error log |

## Hindi voice (optional)

```bash
SLAYMEET_PIPER_VOICE=hi_IN-priyamvada-medium bash deploy/install-piper-tts.sh
```

English meetings should keep `en_US-amy-medium` (default).
