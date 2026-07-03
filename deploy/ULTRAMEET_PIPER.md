# UltraMeet Piper TTS (server)

Teena / Ultra Looper AI speaks using **self-hosted Piper** — not a background service. Each reply runs `piper` once via PHP `proc_open` and returns WAV to the browser.

## One-time install on VPS

```bash
cd /var/www/slayly
SLAYLY_ROOT=/var/www/slayly bash deploy/install-piper-tts.sh
php deploy/verify-piper-tts.php
php deploy/vps-health-check.php   # should show piper_tts_configured: pass
```

Or use the full deploy script from your PC:

```powershell
powershell -ExecutionPolicy Bypass -File deploy\deploy-full-to-vps.ps1
```

## Required `.env` keys (auto-added by install script if missing)

```env
SLAYMEET_TTS_ENGINE=piper
SLAYMEET_PIPER_HOME=/var/www/slayly/storage/piper
SLAYMEET_PIPER_BIN=/var/www/slayly/storage/piper/piper/piper
SLAYMEET_PIPER_VOICE=en_US-amy-medium
SLAYMEET_TTS_GEMINI_FALLBACK=false
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
