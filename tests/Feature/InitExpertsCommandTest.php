<?php

namespace Tests\Feature;

use App\Models\Expert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class InitExpertsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_init_experts_persists_voice_id_from_json(): void
    {
        $path = storage_path('framework/testing-init-experts.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            [
                'name' => 'Voice Test Expert',
                'avatar_url' => '',
                'job' => 'QA',
                'voice_id' => 'elevenlabs-test-voice-id',
                'description' => 'Short description.',
                'profile' => 'Profile text.',
                'core_beliefs' => ['Belief one.'],
                'knowledge_limits' => ['Limit one.'],
                'style' => 'Style text.',
                'tags' => [],
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            $code = Artisan::call('init:experts', ['--file' => $path]);

            $this->assertSame(0, $code);
            $this->assertSame(
                'elevenlabs-test-voice-id',
                Expert::where('name', 'Voice Test Expert')->firstOrFail()->voice_id
            );
        } finally {
            File::delete($path);
        }
    }

    public function test_init_experts_without_voice_id_key_preserves_existing_voice_id(): void
    {
        Expert::factory()->create([
            'name' => 'Preserve Voice Expert',
            'voice_id' => 'existing-voice',
            'job' => 'Old job',
            'description' => 'Old description.',
        ]);

        $path = storage_path('framework/testing-init-experts-preserve.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            [
                'name' => 'Preserve Voice Expert',
                'avatar_url' => '',
                'job' => 'New job',
                'description' => 'New description.',
                'profile' => 'New profile.',
                'core_beliefs' => ['New belief.'],
                'knowledge_limits' => ['New limit.'],
                'style' => 'New style.',
                'tags' => [],
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            $code = Artisan::call('init:experts', ['--file' => $path]);

            $this->assertSame(0, $code);
            $expert = Expert::where('name', 'Preserve Voice Expert')->firstOrFail();
            $this->assertSame('existing-voice', $expert->voice_id);
            $this->assertSame('New job', $expert->job);
        } finally {
            File::delete($path);
        }
    }
}
