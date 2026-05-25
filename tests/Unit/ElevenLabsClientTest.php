<?php

namespace Tests\Unit;

use App\Services\Clients\ElevenLabsClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class ElevenLabsClientTest extends TestCase
{
    public function test_it_strips_markdown_before_synthesis(): void
    {
        config()->set('apis.elevenlabs.api_key', 'test-key');
        config()->set('apis.elevenlabs.model', 'eleven_multilingual_v2');

        $history = [];
        $historyMiddleware = Middleware::history($history);
        $mock = new MockHandler([new Response(200, [], 'audio-bytes')]);
        $stack = HandlerStack::create($mock);
        $stack->push($historyMiddleware);

        $client = new Client([
            'handler' => $stack,
            'base_uri' => 'https://api.elevenlabs.io/v1/',
        ]);

        $service = new ElevenLabsClient($client);

        $result = $service->synthesize("**Hello** _world_ [Link](https://example.com)", 'voice-id');

        $this->assertSame('audio-bytes', $result);
        $this->assertCount(1, $history);

        $requestPayload = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Hello world Link', $requestPayload['text']);
        $this->assertSame('eleven_multilingual_v2', $requestPayload['model_id']);
    }
}
