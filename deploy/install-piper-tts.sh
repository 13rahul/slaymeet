#!/bin/bash
# Self-hosted Piper TTS for UltraMeet / Teena (no cloud TTS APIs).
# Run on VPS: bash deploy/install-piper-tts.sh
set -euo pipefail

APP_ROOT="${SLAYLY_ROOT:-/var/www/slayly}"
PIPER_HOME="${APP_ROOT}/storage/piper"
VOICES_DIR="${PIPER_HOME}/voices"
VOICE="${SLAYMEET_PIPER_VOICE:-en_US-amy-medium}"
PIPER_VERSION="${PIPER_VERSION:-2023.11.14-2}"

echo "==> Slayly Piper TTS setup"
echo "    APP_ROOT=${APP_ROOT}"
echo "    PIPER_HOME=${PIPER_HOME}"
echo "    VOICE=${VOICE}"

mkdir -p "${PIPER_HOME}" "${VOICES_DIR}"

ARCH="$(uname -m)"
case "${ARCH}" in
  x86_64|amd64) PIPER_ARCH="x86_64" ;;
  aarch64|arm64) PIPER_ARCH="aarch64" ;;
  armv7l|armv7) PIPER_ARCH="armv7l" ;;
  *)
    echo "ERROR: Unsupported CPU architecture: ${ARCH}"
    exit 1
    ;;
esac

PIPER_TAR="piper_linux_${PIPER_ARCH}.tar.gz"
PIPER_URL="https://github.com/rhasspy/piper/releases/download/${PIPER_VERSION}/${PIPER_TAR}"

if [[ ! -x "${PIPER_HOME}/piper/piper" ]]; then
  echo "==> Downloading Piper ${PIPER_VERSION} (${PIPER_ARCH})..."
  TMP="$(mktemp -d)"
  curl -fsSL "${PIPER_URL}" -o "${TMP}/${PIPER_TAR}"
  tar -xzf "${TMP}/${PIPER_TAR}" -C "${TMP}"
  rm -rf "${PIPER_HOME}/piper"
  mv "${TMP}/piper" "${PIPER_HOME}/piper"
  chmod +x "${PIPER_HOME}/piper/piper" || true
  rm -rf "${TMP}"
fi

if [[ ! -x "${PIPER_HOME}/piper/piper" ]]; then
  echo "ERROR: Piper binary missing after install"
  exit 1
fi

ONNX="${VOICES_DIR}/${VOICE}.onnx"
JSON="${VOICES_DIR}/${VOICE}.onnx.json"

if [[ ! -f "${ONNX}" ]]; then
  echo "==> Downloading voice model ${VOICE}..."
  HF_BASE="https://huggingface.co/rhasspy/piper-voices/resolve/main"
  case "${VOICE}" in
    hi_IN-priyamvada-medium)
      REL="hi/hi_IN/priyamvada/medium" ;;
    hi_IN-pratham-medium)
      REL="hi/hi_IN/pratham/medium" ;;
    en_US-amy-medium)
      REL="en/en_US/amy/medium" ;;
    en_US-lessac-medium)
      REL="en/en_US/lessac/medium" ;;
    en_GB-alba-medium)
      REL="en/en_GB/alba/medium" ;;
    *)
      echo "WARN: Unknown voice id ${VOICE}; using en_US-amy-medium"
      REL="en/en_US/amy/medium"
      VOICE="en_US-amy-medium"
      ONNX="${VOICES_DIR}/${VOICE}.onnx"
      JSON="${VOICES_DIR}/${VOICE}.onnx.json"
      ;;
  esac
  curl -fsSL "${HF_BASE}/${REL}/${VOICE}.onnx" -o "${ONNX}"
  curl -fsSL "${HF_BASE}/${REL}/${VOICE}.onnx.json" -o "${JSON}"
fi

echo "==> Permissions (PHP-FPM runs as www-data)..."
PIPER_BIN="${PIPER_HOME}/piper/piper"
chmod +x "${PIPER_BIN}" 2>/dev/null || true
chmod -R a+rX "${PIPER_HOME}" 2>/dev/null || true
if id www-data &>/dev/null; then
  chown -R www-data:www-data "${PIPER_HOME}" 2>/dev/null || true
elif id apache &>/dev/null; then
  chown -R apache:apache "${PIPER_HOME}" 2>/dev/null || true
fi

echo "==> Smoke test (CLI)..."
echo "Hello from Piper on UltraMeet." | "${PIPER_BIN}" \
  --model "${ONNX}" \
  --output_file "${PIPER_HOME}/smoke-test.wav"
rm -f "${PIPER_HOME}/smoke-test.wav"

SECRETS="${APP_ROOT}/app/includes/.secrets/.env"
echo "==> Writing Piper env keys to ${SECRETS} (if missing)..."
touch "${SECRETS}"
set_kv() {
  local k="$1" v="$2"
  if grep -q "^${k}=" "${SECRETS}" 2>/dev/null; then
    return 0
  fi
  if [[ -s "${SECRETS}" ]] && [[ "$(tail -c 1 "${SECRETS}" | wc -l)" -eq 0 ]]; then
    echo >> "${SECRETS}"
  fi
  echo "${k}=${v}" >> "${SECRETS}"
}
set_kv "SLAYMEET_TTS_ENGINE" "piper"
set_kv "SLAYMEET_PIPER_HOME" "${PIPER_HOME}"
set_kv "SLAYMEET_PIPER_BIN" "${PIPER_BIN}"
set_kv "SLAYMEET_PIPER_VOICE" "${VOICE}"
set_kv "SLAYMEET_TTS_GEMINI_FALLBACK" "false"
if id www-data &>/dev/null && [[ -f "${SECRETS}" ]]; then
  chown www-data:www-data "${SECRETS}" 2>/dev/null || true
  chmod 640 "${SECRETS}" 2>/dev/null || true
fi

if command -v php >/dev/null 2>&1 && [[ -f "${APP_ROOT}/deploy/verify-piper-tts.php" ]]; then
  echo "==> PHP synthesis test..."
  SLAYLY_ROOT="${APP_ROOT}" php "${APP_ROOT}/deploy/verify-piper-tts.php" || {
    echo "WARN: PHP Piper verify failed — check proc_open and www-data permissions"
    exit 1
  }
else
  echo "TIP: Run: cd ${APP_ROOT} && php deploy/verify-piper-tts.php"
fi

echo ""
echo "OK — Piper TTS ready for UltraMeet (on-demand per request, no daemon required)."
echo "  SLAYMEET_PIPER_HOME=${PIPER_HOME}"
echo "  SLAYMEET_PIPER_BIN=${PIPER_BIN}"
echo "  SLAYMEET_PIPER_VOICE=${VOICE}"
