<?php
declare(strict_types=1);

/**
 * Self-hosted Piper TTS for UltraMeet / Teena (no Google/Microsoft/Zoho APIs).
 *
 * Install: bash deploy/install-piper-tts.sh
 */
final class SlayMeetPiperSpeech
{
    /** Default voice — clear English female (use for English meeting replies; Hindi models mis-read English). */
    public const DEFAULT_VOICE = 'en_US-amy-medium';

    /**
     * @return array{wav: string, sample_rate: int, engine: string, model: string, voice: string}
     */
    public static function synthesize(string $text, string $voice = self::DEFAULT_VOICE): array
    {
        $text = trim($text);
        if ($text === '') {
            throw InvalidArgumentException('Empty TTS text');
        }
        if (mb_strlen($text) > 5000) {
            $text = mb_substr($text, 0, 5000);
        }

        $binary = self::resolveBinary();
        $modelPath = self::resolveModelPath($voice);
        if (!is_file($modelPath)) {
            throw RuntimeException(
                'Piper model not found: ' . $modelPath . '. Run: bash deploy/install-piper-tts.sh'
            );
        }

        $tmpOut = tempnam(sys_get_temp_dir(), 'slaymeet_piper_');
        if ($tmpOut === false) {
            throw RuntimeException('Could not create temp file for Piper output');
        }
        $wavPath = $tmpOut . '.wav';
        @unlink($tmpOut);

        $lengthScale = self::lengthScale();
        $cmd = escapeshellarg($binary)
            . ' --model ' . escapeshellarg($modelPath)
            . ' --output_file ' . escapeshellarg($wavPath);
        if ($lengthScale > 0 && abs($lengthScale - 1.0) > 0.01) {
            $cmd .= ' --length_scale ' . escapeshellarg((string) $lengthScale);
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw RuntimeException('Failed to start Piper process');
        }

        fwrite($pipes[0], $text);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        if ($exitCode !== 0 || !is_file($wavPath) || filesize($wavPath) < 200) {
            @unlink($wavPath);
            throw RuntimeException(
                'Piper synthesis failed (exit ' . $exitCode . '): ' . trim((string) $stderr)
            );
        }

        $wav = file_get_contents($wavPath);
        @unlink($wavPath);
        if ($wav === false || $wav === '') {
            throw RuntimeException('Piper produced empty WAV output');
        }

        return [
            'wav' => $wav,
            'sample_rate' => self::readWavSampleRate($wav),
            'engine' => 'piper',
            'model' => basename($modelPath),
            'voice' => self::normalizeVoiceKey($voice),
        ];
    }

    public static function isConfigured(): bool
    {
        try {
            $bin = self::resolveBinary();
            $model = self::resolveModelPath(self::defaultVoiceKey());

            return is_executable($bin) && is_file($model);
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function defaultVoiceKey(): string
    {
        $v = trim((string) (getenv('SLAYMEET_PIPER_VOICE') ?: ''));

        return $v !== '' ? self::normalizeVoiceKey($v) : self::DEFAULT_VOICE;
    }

    private static function normalizeVoiceKey(string $voice): string
    {
        $voice = trim($voice);
        if ($voice === '' || strcasecmp($voice, 'Kore') === 0) {
            return self::defaultVoiceKey();
        }

        return preg_replace('/[^A-Za-z0-9._-]/', '', $voice) ?: self::DEFAULT_VOICE;
    }

    private static function lengthScale(): float
    {
        $v = getenv('SLAYMEET_PIPER_LENGTH_SCALE');
        if ($v === false || $v === '') {
            return 1.0;
        }

        return max(0.5, min(2.0, (float) $v));
    }

    private static function projectRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    private static function piperHome(): string
    {
        $custom = trim((string) (getenv('SLAYMEET_PIPER_HOME') ?: ''));
        if ($custom !== '') {
            return rtrim($custom, '/\\');
        }

        return self::projectRoot() . '/storage/piper';
    }

    private static function resolveBinary(): string
    {
        $explicit = trim((string) (getenv('SLAYMEET_PIPER_BIN') ?: ''));
        if ($explicit !== '' && is_executable($explicit)) {
            return $explicit;
        }

        $home = self::piperHome();
        $candidates = [];
        if (PHP_OS_FAMILY === 'Windows') {
            $candidates[] = $home . '/piper/piper.exe';
            $candidates[] = $home . '/piper.exe';
        } else {
            $candidates[] = $home . '/piper/piper';
            $candidates[] = $home . '/piper';
        }

        foreach ($candidates as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        throw RuntimeException(
            'Piper binary not found under ' . $home . '. Run: bash deploy/install-piper-tts.sh'
        );
    }

    private static function resolveModelPath(string $voice): string
    {
        $voice = self::normalizeVoiceKey($voice);
        $explicit = trim((string) (getenv('SLAYMEET_PIPER_MODEL') ?: ''));
        if ($explicit !== '') {
            if (is_file($explicit)) {
                return $explicit;
            }
            if (is_file($explicit . '.onnx')) {
                return $explicit . '.onnx';
            }
        }

        $voicesDir = trim((string) (getenv('SLAYMEET_PIPER_MODELS_DIR') ?: ''));
        if ($voicesDir === '') {
            $voicesDir = self::piperHome() . '/voices';
        }

        $candidates = [
            $voicesDir . '/' . $voice . '.onnx',
            $voicesDir . '/' . $voice,
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return $voicesDir . '/' . $voice . '.onnx';
    }

    private static function readWavSampleRate(string $wav): int
    {
        if (strlen($wav) >= 28) {
            $rate = unpack('V', substr($wav, 24, 4));
            if (is_array($rate) && ($rate[1] ?? 0) > 0) {
                return (int) $rate[1];
            }
        }

        return 22050;
    }
}
