<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Support\CodeProvenance;
use App\Discussion\Values\ModelConfig;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CodeProvenanceTest extends TestCase
{
    /** A checkout without .git, holding the given prompt views. */
    private function checkout(array $views): string
    {
        $root = sys_get_temp_dir().'/provenance-'.uniqid();
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($root));

        foreach ($views as $path => $content) {
            File::ensureDirectoryExists(dirname("{$root}/resources/views/prompts/{$path}"));
            File::put("{$root}/resources/views/prompts/{$path}", $content);
        }

        return $root;
    }

    private function model(): ModelConfig
    {
        return ModelConfig::fromConfig(config('ai.default_model'));
    }

    public function test_the_prompt_hash_changes_when_a_prompt_file_changes(): void
    {
        $before = (new CodeProvenance($this->checkout(['speak.blade.php' => 'A', 'think/x.blade.php' => 'B'])))->toArray($this->model());
        $same = (new CodeProvenance($this->checkout(['think/x.blade.php' => 'B', 'speak.blade.php' => 'A'])))->toArray($this->model());
        $after = (new CodeProvenance($this->checkout(['speak.blade.php' => 'A', 'think/x.blade.php' => 'C'])))->toArray($this->model());

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $before['prompt_views']);
        $this->assertSame($before['prompt_views'], $same['prompt_views']);
        $this->assertNotSame($before['prompt_views'], $after['prompt_views']);
    }

    public function test_a_renamed_prompt_file_changes_the_hash(): void
    {
        $before = (new CodeProvenance($this->checkout(['a.blade.php' => 'A'])))->toArray($this->model());
        $after = (new CodeProvenance($this->checkout(['b.blade.php' => 'A'])))->toArray($this->model());

        $this->assertNotSame($before['prompt_views'], $after['prompt_views']);
    }

    public function test_without_git_commit_and_dirty_are_null(): void
    {
        $provenance = (new CodeProvenance($this->checkout(['a.blade.php' => 'A'])))->toArray($this->model());

        $this->assertNull($provenance['commit']);
        $this->assertNull($provenance['dirty']);
    }

    public function test_this_checkout_names_its_commit_sdk_version_and_endpoint(): void
    {
        config(['ai.providers.openai.url' => 'https://example.test/v1']);

        $provenance = (new CodeProvenance(base_path()))->toArray(ModelConfig::fromConfig('openai-gpt-5-mini'));

        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $provenance['commit']);
        $this->assertIsBool($provenance['dirty']);
        $this->assertNotEmpty($provenance['laravel_ai']);
        $this->assertSame('https://example.test/v1', $provenance['endpoint']);
    }

    public function test_a_provider_without_a_configured_url_has_no_endpoint(): void
    {
        config(['ai.providers.openai.url' => null]);

        $provenance = (new CodeProvenance($this->checkout([])))->toArray(ModelConfig::fromConfig('openai-gpt-5-mini'));

        $this->assertNull($provenance['endpoint']);
    }
}
