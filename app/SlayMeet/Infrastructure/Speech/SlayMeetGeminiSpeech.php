<?php
declare(strict_types=1);

/**
 * Gemini-only speech for SlayMeet (TTS). Mirrors Google ADK speech_config patterns:
 * responseModalities AUDIO + prebuilt voice (e.g. Kore, Aoede).
 */
final class SlayMeetGeminiSpeech
{
    /** Warm professional female voice (Gemini prebuilt). */
    public const DEFAULT_VOICE = 'Kore';

    /** @var list<string> */
    private const TTS_MODELS = [
        'gemini-2.5-flash-tts',
        'gemini-2.5-flash-preview-tts',
    ];

    /**
     * @return array{pcm: string, sample_rate: int, mime: string, model: string}
     */
    public static function synthesize(string $text, string $voice = self::DEFAULT_VOICE): array
    {
        require_once __DIR__ . '/../../../includes/gemini_helper.php';

        $text = trim($text);
        if ($text === '') {
            throw new InvalidArgumentException('Empty TTS text');
        }
        if (mb_strlen($text) > 5000) {
            $text = mb_substr($text, 0, 5000);
        }

        $apiKey = getGeminiKey();
        if ($apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY is not configured');
        }

        $voice = preg_replace('/[^A-Za-z0-9_]/', '', $voice) ?: self::DEFAULT_VOICE;
        $prompt = 'Say clearly in a warm, professional tone: ' . $text;

        $payload = [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $prompt]]],
            ],
            'generationConfig' => [
                'responseModalities' => ['AUDIO'],
                'speechConfig' => [
                    'voiceConfig' => [
                        'prebuiltVoiceConfig' => [
                            'voiceName' => $voice,
                        ],
                    ],
                ],
            ],
        ];

        $lastErr = 'Gemini TTS failed';
        foreach (self::TTS_MODELS as $model) {
            try {
                $raw = self::postGenerate($apiKey, $model, $payload);
                $pcm = self::extractPcmFromResponse($raw);
                if ($pcm !== '') {
                    return [
                        'pcm' => $pcm,
                        'sample_rate' => 24000,
                        'mime' => 'audio/wav',
                        'model' => $model,
                    ];
                }
            } catch (Throwable $e) {
                $lastErr = $e->getMessage();
                error_log('[SlayMeetGeminiSpeech] ' . $model . ': ' . $lastErr);
            }
        }

        throw RuntimeException($lastErr);
    }

    public static function pcmToWav(string $pcm, int $sampleRate = 24000, int $channels = 1, int $bitsPerSample = 16): string
    {
        $byteRate = (int) ($sampleRate * $channels * $bitsPerSample / 8);
        $blockAlign = (int) ($channels * $bitsPerSample / 8);
        $dataSize = strlen($pcm);
        // No spaces in format string (PHP treats space as invalid format code).
        $header = pack(
            'a4Va4a4VvvVVvva4V',
            'RIFF',
            36 + $dataSize,
            'WAVE',
            'fmt ',
            16,
            1,
            $channels,
            $sampleRate,
            $byteRate,
            $blockAlign,
            $bitsPerSample,
            'data',
            $dataSize
        );

        return $header . $pcm;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function postGenerate(string $apiKey, string $model, array $payload): array
    {
        $modelPath = str_replace('models/', '', $model);
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
            . rawurlencode($modelPath)
            . ':generateContent?key=' . rawurlencode($apiKey);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 90,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false || $err !== '') {
            throw RuntimeException('Gemini TTS HTTP error: ' . ($err ?: 'empty response'));
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw RuntimeException('Gemini TTS invalid JSON (HTTP ' . $code . ')');
        }
        if ($code >= 400) {
            $msg = $decoded['error']['message'] ?? ('HTTP ' . $code);
            throw RuntimeException((string) $msg);
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $response
     */
    private static function extractPcmFromResponse(array $response): string
    {
        $candidates = $response['candidates'] ?? [];
        if (!is_array($candidates)) {
            return '';
        }
        foreach ($candidates as $candidate) {
            $parts = $candidate['content']['parts'] ?? [];
            if (!is_array($parts)) {
                continue;
            }
            foreach ($parts as $part) {
                $inline = $part['inlineData'] ?? $part['inline_data'] ?? null;
                if (!is_array($inline)) {
                    continue;
                }
                $data = $inline['data'] ?? '';
                if (!is_string($data) || $data === '') {
                    continue;
                }
                $decoded = base64_decode($data, true);
                if ($decoded !== false && $decoded !== '') {
                    return $decoded;
                }
            }
        }

        return '';
    }
}
