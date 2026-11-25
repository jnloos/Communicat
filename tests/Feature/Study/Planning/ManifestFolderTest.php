<?php

namespace Tests\Feature\Study\Planning;

use App\Services\Study\Planning\InvalidManifest;
use App\Services\Study\Planning\Manifest;
use App\Services\Study\Planning\ManifestFolder;
use Closure;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** resources/study/manifests: a study as a folder of parts, joined into the one manifest the planner reads. */
class ManifestFolderTest extends TestCase
{
    /** The probe's wave was applied from this manifest (study_waves.manifest_sha256, 24.11.2026). */
    private const PROBE_SHA256 = 'aa5be35ed96115c883530d99d3717b56c501f73245595b5929f26d448ae42f9c';

    public function test_the_probe_folder_assembles_to_the_manifest_its_wave_was_applied_with(): void
    {
        $manifest = Manifest::fromJson(ManifestFolder::assemble(ManifestFolder::path('probegptsix')));

        $this->assertSame(self::PROBE_SHA256, $manifest->sha256());
    }

    public function test_it_lists_the_folders_that_hold_a_study(): void
    {
        $this->assertContains('main', ManifestFolder::names());
        $this->assertContains('probegptsix', ManifestFolder::names());
    }

    /** @return array<string, array{Closure(string): void, string}> */
    public static function brokenFolders(): array
    {
        return [
            'gap' => [fn (string $dir) => File::move("{$dir}/scenarios/s01.json", "{$dir}/scenarios/s02.json"), 'expected s01.json'],
            'id' => [fn (string $dir) => File::put("{$dir}/scenarios/s01.json", '{"id": 3}'), '"id" is 3, the file name says 1'],
            'overlap' => [fn (string $dir) => File::put("{$dir}/names.json", '{"study": "x"}'), 'both define: study'],
            'missing part' => [fn (string $dir) => File::delete("{$dir}/names.json"), 'Missing names.json'],
            'broken json' => [fn (string $dir) => File::put("{$dir}/study.json", '{'), 'study.json: not valid JSON'],
        ];
    }

    #[DataProvider('brokenFolders')]
    public function test_a_broken_folder_says_what_is_wrong(Closure $break, string $expected): void
    {
        $folder = $this->copyOfProbe();
        $break($folder);

        try {
            ManifestFolder::assemble($folder);
            $this->fail('Assembled a broken folder.');
        } catch (InvalidManifest $exception) {
            $this->assertStringContainsString($expected, implode("\n", $exception->problems));
        }
    }

    public function test_study_manifest_writes_the_file_and_lists_the_scenarios(): void
    {
        $folder = $this->copyOfProbe();

        $this->artisan('study:manifest', ['folder' => $folder])
            ->expectsOutputToContain('master seed 20261124')
            ->assertSuccessful();

        $this->assertSame(self::PROBE_SHA256, Manifest::fromJson(File::get("{$folder}/manifest.json"))->sha256());
    }

    private function copyOfProbe(): string
    {
        $folder = sys_get_temp_dir().'/'.uniqid('manifest-folder-');
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($folder));
        File::copyDirectory(ManifestFolder::path('probegptsix'), $folder);
        File::delete("{$folder}/manifest.json");

        return $folder;
    }
}
