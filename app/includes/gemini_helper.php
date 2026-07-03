<?php

require_once __DIR__ . '/../config/config.php';

function getGeminiKey(): string
{
    if (defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '') {
        return trim((string) GEMINI_API_KEY);
    }
    $env = getenv('GEMINI_API_KEY') ?: getenv('GOOGLE_API_KEY') ?: '';
    return trim((string) $env);
}

function slaymeet_gemini_model_lite(): string
{
    $m = trim((string) (getenv('GEMINI_MODEL_LITE') ?: 'gemini-2.5-flash-lite'));
    return $m !== '' ? $m : 'gemini-2.5-flash-lite';
}

function callGeminiRobust(string $prompt, bool $jsonMode = false, ?string $model = null): string
{
    $apiKey = getGeminiKey();
    if ($apiKey === '') {
        throw new RuntimeException('GEMINI_API_KEY is not configured.');
    }

    $modelName = str_replace('models/', '', $model ?: slaymeet_gemini_model_lite());
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$modelName}:generateContent?key={$apiKey}";

    $requestData = [
        'contents' => [
            [
                'role' => 'user',
                'parts' => [['text' => $prompt]],
            ],
        ],
        'generationConfig' => [
            'temperature' => 0.7,
            'maxOutputTokens' => 8192,
        ],
    ];

    if ($jsonMode) {
        $requestData['generationConfig']['responseMimeType'] = 'application/json';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($requestData),
        CURLOPT_TIMEOUT => 120,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Gemini request failed: ' . $err);
    }

    $decoded = json_decode($response, true);
    if ($httpCode >= 400) {
        $msg = $decoded['error']['message'] ?? ('HTTP ' . $httpCode);
        throw new RuntimeException('Gemini API error: ' . $msg);
    }

    $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if ($text === '') {
        throw new RuntimeException('Gemini returned empty response.');
    }

    return $text;
}
