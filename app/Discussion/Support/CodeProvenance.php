<?php

namespace App\Discussion\Support;

use App\Discussion\Values\ModelConfig;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Which code and which prompt texts a run started with, frozen into
 * run_config.provenance so a run can be traced to them after the code moves on.
 * Commit, dirty flag and prompt hash are read once per process and checkout
 * root: a worker opens many runs, and the code it runs does not change under it.
 * `dirty` also counts untracked, not-ignored files, on purpose: such a file can
 * change behaviour as much as an edited one.
 */
class CodeProvenance
{
    /** @var array<string, array{commit: ?string, dirty: ?bool, prompt_views: string}> root => what was read */
    private static array $read = [];

    private readonly string $root;

    public function __construct(?string $root = null)
    {
        $this->root = $root ?? base_path();
    }

    /** @return array{commit: ?string, dirty: ?bool, laravel_ai: ?string, endpoint: ?string, prompt_views: string} */
    public function toArray(ModelConfig $model): array
    {
        $code = self::$read[$this->root] ??= $this->readCode();

        return [
            'commit' => $code['commit'],
            'dirty' => $code['dirty'],
            'laravel_ai' => InstalledVersions::getPrettyVersion('laravel/ai'),
            // The provider's configured base URL; null where the SDK's own default applies.
            'endpoint' => (config('ai.providers')[$model->provider] ?? [])['url'] ?? null,
            'prompt_views' => $code['prompt_views'],
        ];
    }

    /** @return array{commit: ?string, dirty: ?bool, prompt_views: string} */
    private function readCode(): array
    {
        // No git (a deployed copy without .git, or no binary): the run still starts.
        // Whether a wave may start on an unknown commit is the planner's preflight, not the core's.
        $commit = $this->git('rev-parse', 'HEAD');

        return [
            'commit' => $commit,
            'dirty' => $commit === null ? null : $this->git('status', '--porcelain') !== '',
            'prompt_views' => $this->promptViewsHash(),
        ];
    }

    private function git(string ...$arguments): ?string
    {
        $result = Process::run(['git', '-C', $this->root, ...$arguments]);

        return $result->successful() ? trim($result->output()) : null;
    }

    /** One hash over every prompt view, path and content, sorted by path: a rename counts as a change too. */
    private function promptViewsHash(): string
    {
        $directory = $this->root.'/resources/views/prompts';
        $files = File::isDirectory($directory) ? File::allFiles($directory) : [];

        $hash = hash_init('sha256');
        foreach (collect($files)->sortBy(fn (SplFileInfo $file) => $file->getRelativePathname()) as $file) {
            hash_update($hash, $file->getRelativePathname()."\0".$file->getContents()."\0");
        }

        return hash_final($hash);
    }
}
