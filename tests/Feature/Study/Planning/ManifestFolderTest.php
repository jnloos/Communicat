<?php

namespace Tests\Feature\Study\Planning;

use App\Study\Planning\Manifest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/** app/Study/manifests/build.sh: the folder of parts becomes the one manifest the planner reads. */
class ManifestFolderTest extends TestCase
{
    public function test_the_probe_folder_builds_a_manifest_the_planner_accepts(): void
    {
        $folder = $this->copyOfProbe();

        Process::run([base_path('app/Study/manifests/build.sh'), $folder])->throw();

        $manifest = Manifest::fromJson(file_get_contents("{$folder}/manifest.json"));
        $this->assertSame('probegptsix', $manifest->study());
        $this->assertCount(count(glob("{$folder}/scenarios/s*.json")), $manifest->scenarios());
    }

    public function test_a_gap_in_the_scenario_files_is_refused(): void
    {
        $folder = $this->copyOfProbe();
        File::move("{$folder}/scenarios/s01.json", "{$folder}/scenarios/s02.json");

        $result = Process::run([base_path('app/Study/manifests/build.sh'), $folder]);

        $this->assertFalse($result->successful());
        $this->assertStringContainsString('expected s01.json', $result->errorOutput());
        $this->assertFileDoesNotExist("{$folder}/manifest.json");
    }

    private function copyOfProbe(): string
    {
        $folder = sys_get_temp_dir().'/'.uniqid('manifest-folder-');
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($folder));
        File::copyDirectory(base_path('app/Study/manifests/probegptsix'), $folder);
        File::delete("{$folder}/manifest.json");

        return $folder;
    }
}
