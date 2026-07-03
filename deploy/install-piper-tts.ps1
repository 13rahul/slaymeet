# Self-hosted Piper TTS for UltraMeet (Windows dev).
# powershell -ExecutionPolicy Bypass -File deploy\install-piper-tts.ps1
$ErrorActionPreference = "Stop"

$AppRoot = if ($env:SLAYLY_ROOT) { $env:SLAYLY_ROOT } else { Split-Path $PSScriptRoot -Parent }

$PiperHome = Join-Path $AppRoot "storage\piper"
$VoicesDir = Join-Path $PiperHome "voices"
$Voice = if ($env:SLAYMEET_PIPER_VOICE) { $env:SLAYMEET_PIPER_VOICE } else { "en_US-amy-medium" }
$PiperVersion = "2023.11.14-2"

New-Item -ItemType Directory -Force -Path $PiperHome, $VoicesDir | Out-Null

$PiperExe = Join-Path $PiperHome "piper\piper.exe"
if (-not (Test-Path $PiperExe)) {
    $ZipUrl = "https://github.com/rhasspy/piper/releases/download/$PiperVersion/piper_windows_amd64.zip"
    $TmpZip = Join-Path $env:TEMP "piper.zip"
    Write-Host "Downloading Piper..."
    Invoke-WebRequest -Uri $ZipUrl -OutFile $TmpZip
    Expand-Archive -Path $TmpZip -DestinationPath $PiperHome -Force
    Remove-Item $TmpZip -Force
}

$Onnx = Join-Path $VoicesDir "$Voice.onnx"
$Json = Join-Path $VoicesDir "$Voice.onnx.json"
if (-not (Test-Path $Onnx)) {
    $VoiceMap = @{
        "hi_IN-priyamvada-medium" = "hi/hi_IN/priyamvada/medium"
        "hi_IN-pratham-medium"    = "hi/hi_IN/pratham/medium"
        "en_US-amy-medium"        = "en/en_US/amy/medium"
    }
    $Rel = $VoiceMap[$Voice]
    if (-not $Rel) { $Rel = "en/en_US/amy/medium"; $Voice = "en_US-amy-medium"; $Onnx = Join-Path $VoicesDir "$Voice.onnx"; $Json = Join-Path $VoicesDir "$Voice.onnx.json" }
    $Hf = "https://huggingface.co/rhasspy/piper-voices/resolve/main/$Rel"
    Write-Host "Downloading voice $Voice..."
    Invoke-WebRequest -Uri "$Hf/$Voice.onnx" -OutFile $Onnx
    Invoke-WebRequest -Uri "$Hf/$Voice.onnx.json" -OutFile $Json
}

Write-Host "OK. Set in .env:"
Write-Host "  SLAYMEET_TTS_ENGINE=piper"
Write-Host "  SLAYMEET_PIPER_HOME=$PiperHome"
Write-Host "  SLAYMEET_PIPER_BIN=$PiperExe"
Write-Host "  SLAYMEET_PIPER_VOICE=$Voice"
