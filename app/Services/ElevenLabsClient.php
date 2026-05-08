<?php

namespace App\Services;

use App\Facades\Markdown;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

class ElevenLabsClient
{
    protected ClientInterface $client;

    public function __construct(?ClientInterface $client = null)
    {
        $this->client = $client ?? new Client([
            'base_uri' => 'https://api.elevenlabs.io/v1/',
            'timeout' => 30,
        ]);
    }

    /**
     * @throws GuzzleException
     */
    public function synthesize(string $text, string $voiceId): string
    {
        $apiKey = config('apis.elevenlabs.api_key');
        if (!is_string($apiKey) || trim($apiKey) === '') {
            throw new RuntimeException('ELEVENLABS_API_KEY is not configured.');
        }

        $cleanText = $this->toPlainText($text);
        if ($cleanText === '') {
            throw new RuntimeException('Cannot synthesize empty text.');
        }

        $response = $this->client->request('POST', "text-to-speech/{$voiceId}", [
            'headers' => [
                'Accept' => 'audio/mpeg',
                'Content-Type' => 'application/json',
                'xi-api-key' => $apiKey,
            ],
            'json' => [
                'text' => $cleanText,
                'model_id' => config('apis.elevenlabs.model', 'eleven_multilingual_v2'),
                'voice_settings' => [
                    'stability' => 0.45,
                    'similarity_boost' => 0.75,
                ],
            ],
        ]);

        if ($response->getStatusCode() < 200 || $response->getStatusCode() > 299) {
            throw new RuntimeException('ElevenLabs synthesis failed.');
        }

        return (string) $response->getBody();
    }

    protected function toPlainText(string $text): string
    {
        $parsed = Markdown::parse($text);
        $plain = strip_tags($parsed);

        return trim((string) preg_replace('/\s+/u', ' ', $plain));
    }
}
