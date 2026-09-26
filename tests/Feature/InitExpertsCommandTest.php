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

    public function test_init_experts_creates_and_updates_by_name(): void
    {
        Expert::factory()->create([
            'name' => 'Existing Expert',
            'role' => 'Old role',
            'description' => 'Old description.',
        ]);

        $path = storage_path('framework/testing-init-experts.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            [
                'name' => 'Existing Expert',
                'avatar_url' => '',
                'role' => 'New role',
                'description' => 'New description.',
            ],
            [
                'name' => 'New Expert',
                'avatar_url' => '',
                'role' => 'QA',
                'description' => 'Short description.',
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            $code = Artisan::call('init:experts', ['--file' => $path]);

            $this->assertSame(0, $code);
            $this->assertSame('New role', Expert::where('name', 'Existing Expert')->firstOrFail()->role);
            $this->assertSame('Short description.', Expert::where('name', 'New Expert')->firstOrFail()->description);
        } finally {
            File::delete($path);
        }
    }
}
