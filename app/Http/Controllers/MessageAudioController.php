<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Services\ElevenLabsClient;
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

        $disk = Storage::disk('local');
        $path = "voice/messages/{$message->id}.mp3";

        if (!$disk->exists($path)) {
            $voiceId = $message->expert?->voice_id ?: config('apis.elevenlabs.default_voice');

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
