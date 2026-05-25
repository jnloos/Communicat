<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Services\Clients\ElevenLabsClient;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Throwable;

class MessageAudioController extends Controller
{
    public function show(Message $message): Response
    {
        abort_unless(Gate::allows('access-project', $message->project), 403);
        abort_unless($message->isExpert(), 404);

        $disk    = Storage::disk('local');
        $voiceId = $message->expert?->voice_id ?: config('apis.elevenlabs.default_voice');

        // Key the cache on voice + content, not just the message id: a reused id
        // (e.g. after migrate:fresh) or changed content can never serve stale,
        // mismatched audio.
        $fingerprint = substr(sha1($voiceId . '|' . $message->content), 0, 16);
        $path        = "voice/messages/{$message->id}-{$fingerprint}.mp3";

        if (!$disk->exists($path)) {
            try {
                $audio = app(ElevenLabsClient::class)->synthesize($message->content, (string) $voiceId);
            } catch (Throwable) {
                abort(502, 'Audio synthesis failed.');
            }

            $disk->put($path, $audio);
        }

        return response($disk->get($path), 200, [
            'Content-Type' => 'audio/mpeg',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
