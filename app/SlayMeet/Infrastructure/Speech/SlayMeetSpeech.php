<?php
declare(strict_types=1);

/**
 * UltraMeet TTS router — self-hosted Piper by default; Gemini optional fallback only.
 */
final class SlayMeetSpeech
{
    /**
     * @return array{wav: string, sample_rate: int, engine: string, model: string, voice: string}
     */
    public static function synthesize(string $text, string $voice = ''): array
    {
        $engine = strtolower(trim((string) (getenv('SLAYMEET_TTS_ENGINE') ?: 'piper')));

        if (in_array($engine, ['off', 'none', 'disabled'], true)) {
            throw RuntimeException('UltraMeet voice is disabled (SLAYMEET_TTS_ENGINE=off)');
        }

        if ($engine === 'gemini') {
            return self::synthesizeGemini($text, $voice);
        }

        require_once __DIR__ . '/SlayMeetPiperSpeech.php';
        try {
            $piperVoice = $voice !== '' ? $voice : SlayMeetPiperSpeech::defaultVoiceKey();

            return SlayMeetPiperSpeech::synthesize($text, $piperVoice);
        } catch (Throwable $e) {
            // Gemini voice fallback disabled — test Piper only (re-enable for prod if needed).
            // if (self::geminiFallbackEnabled()) {
            //     error_log('[SlayMeetSpeech] Piper failed, Gemini fallback: ' . $e->getMessage());
            //     return self::synthesizeGemini($text, $voice);
            // }
            throw $e;
        }
    }

    public static function engineLabel(): string
    {
        return strtolower(trim((string) (getenv('SLAYMEET_TTS_ENGINE') ?: 'piper')));
    }

    public static function geminiFallbackEnabled(): bool
    {
        $v = getenv('SLAYMEET_TTS_GEMINI_FALLBACK');
        if ($v === false || $v === '') {
            return false;
        }

        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return array{wav: string, sample_rate: int, engine: string, model: string, voice: string}
     */
    private static function synthesizeGemini(string $text, string $voice): array
    {
        require_once __DIR__ . '/SlayMeetGeminiSpeech.php';
        $geminiVoice = $voice !== '' ? $voice : SlayMeetGeminiSpeech::DEFAULT_VOICE;
        $result = SlayMeetGeminiSpeech::synthesize($text, $geminiVoice);
        $wav = SlayMeetGeminiSpeech::pcmToWav($result['pcm'], $result['sample_rate']);

        return [
            'wav' => $wav,
            'sample_rate' => (int) $result['sample_rate'],
            'engine' => 'gemini',
            'model' => (string) ($result['model'] ?? 'gemini-tts'),
            'voice' => $geminiVoice,
        ];
    }
}
