<?php
declare(strict_types=1);
/**
 * Verify Piper TTS for UltraMeet on the server (CLI).
 *   php deploy/verify-piper-tts.php
 */
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/includes/SlayMeetPiperSpeech.php';
require_once __DIR__ . '/../app/includes/SlayMeetSpeech.php';

$failures = 0;
$ok = static function (string $msg): void {
    echo "  OK: {$msg}\n";
};
$fail = static function (string $msg) use (&$failures): void {
    echo "  FAIL: {$msg}\n";
    $failures++;
};

echo "==> UltraMeet Piper TTS verify\n";
echo 'Engine: ' . SlayMeetSpeech::engineLabel() . "\n";

$disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
if (in_array('proc_open', $disabled, true) || !function_exists('proc_open')) {
    $fail('PHP proc_open is disabled — enable it for Piper TTS');
} else {
    $ok('PHP proc_open available');
}

$piperBin = trim((string) (getenv('SLAYMEET_PIPER_BIN') ?: ''));
if ($piperBin === '') {
    $piperHome = trim((string) (getenv('SLAYMEET_PIPER_HOME') ?: ''));
    if ($piperHome === '') {
        $piperHome = dirname(__DIR__) . '/storage/piper';
    }
    $piperBin = $piperHome . '/piper/piper';
}
if (!is_executable($piperBin)) {
    $fail('Piper binary not executable: ' . $piperBin);
} else {
    $ok('Piper binary: ' . $piperBin);
}

if (!SlayMeetPiperSpeech::isConfigured()) {
    $fail('Piper model/voice not found — run: bash deploy/install-piper-tts.sh');
} else {
    $ok('Voice model: ' . SlayMeetPiperSpeech::defaultVoiceKey());
}

$sample = 'Hello, I am Teena. UltraMeet voice check.';
try {
    $t0 = microtime(true);
    $result = SlayMeetSpeech::synthesize($sample);
    $ms = (int) round((microtime(true) - $t0) * 1000);
    $bytes = strlen($result['wav']);
    if ($bytes < 200) {
        $fail("Synthesis returned tiny WAV ({$bytes} bytes)");
    } else {
        $ok("Synthesis {$result['engine']}/{$result['model']} {$bytes} bytes in {$ms}ms");
    }
    $out = dirname(__DIR__) . '/storage/piper/verify-smoke.wav';
    file_put_contents($out, $result['wav']);
    @chmod($out, 0644);
    echo "  Wrote: {$out}\n";
} catch (Throwable $e) {
    $fail('Synthesis: ' . $e->getMessage());
}

exit($failures > 0 ? 1 : 0);
