<?php

namespace App\Http\Controllers;

use App\Services\Clients\ElevenLabsClient;
use App\Support\VoiceCatalog;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Throwable;

class VoicePreviewController extends Controller
{
    /** Short German sample so every voice says the same line. */
    private const SAMPLE_TEXT = 'Hallo, so klinge ich in einer Diskussion. Ich freue mich auf den Austausch.';

    public function show(string $voiceId): Response
    {
        // Only catalog voices may be synthesized — prevents arbitrary API spend.
        abort_if(VoiceCatalog::labelFor($voiceId) === null, 404);

        $disk = Storage::disk('local');
        $path = "voice/previews/{$voiceId}.mp3";

        if (! $disk->exists($path)) {
            try {
                $audio = app(ElevenLabsClient::class)->synthesize(self::SAMPLE_TEXT, $voiceId);
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
