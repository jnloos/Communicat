<?php

namespace App\Http\Controllers;

use App\Services\Discord\SignatureVerifier;
use App\Services\Study\Progress\StatusText;
use App\Services\Study\Progress\StudySnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Discord's HTTP interactions: PING and /status, nothing that changes the study. Not behind the
 * web middleware (no session, no CSRF): the signature is the only protection, checked first.
 */
class DiscordInteractionController
{
    private const PING = 1;

    private const APPLICATION_COMMAND = 2;

    private const CHANNEL_MESSAGE = 4;

    private const EPHEMERAL = 64;

    public function __invoke(Request $request, SignatureVerifier $verifier, StatusText $statusText): Response|JsonResponse
    {
        // The raw text: signing covers it byte for byte, a re-encoded JSON would not match.
        if (! $verifier->valid($request->getContent(), $request->header('X-Signature-Ed25519'), $request->header('X-Signature-Timestamp'))) {
            return response('', 401);
        }

        $type = $request->json('type');

        if ($type === self::PING) {
            return response()->json(['type' => self::PING]);
        }

        abort_unless($type === self::APPLICATION_COMMAND && $request->json('data.name') === 'status', 400);

        // A bot invited to another server must not be able to read the study's numbers.
        $channel = config('study.discord.channel_id');

        if (! $channel || $request->json('channel_id') !== $channel) {
            return $this->message(__('study.watch.discord.wrong_channel'), self::EPHEMERAL);
        }

        return $this->message($statusText->summary(StudySnapshot::read()));
    }

    private function message(string $content, ?int $flags = null): JsonResponse
    {
        return response()->json(['type' => self::CHANNEL_MESSAGE, 'data' => array_filter([
            'content' => $content,
            'flags' => $flags,
            'allowed_mentions' => ['parse' => []],
        ])]);
    }
}
