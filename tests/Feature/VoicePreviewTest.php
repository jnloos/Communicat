<?php
namespace Tests\Feature;
use App\Services\Clients\ElevenLabsClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
class VoicePreviewTest extends TestCase {
    use RefreshDatabase;
    public function test_unknown_voice_returns_404(): void {
        $this->actingAs(User::factory()->create());
        $this->get(route('voices.preview', 'not-a-real-voice'))->assertNotFound();
    }
    public function test_catalog_voice_synthesizes_and_serves_mp3(): void {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $this->mock(ElevenLabsClient::class, fn($m) => $m->shouldReceive('synthesize')->once()->andReturn('FAKEMP3'));
        $id = config('voices.female.0.id');
        $res = $this->get(route('voices.preview', $id));
        $res->assertOk()->assertHeader('Content-Type', 'audio/mpeg');
        Storage::disk('local')->assertExists("voice/previews/{$id}.mp3");
    }
    public function test_guest_is_redirected(): void {
        $this->get(route('voices.preview', config('voices.female.0.id')))->assertRedirect();
    }
}
