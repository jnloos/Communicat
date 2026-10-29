<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The prompt texts of one project's calls, packed together: prompts of a run
 * share system prompt, personas and most of the history, so one zstd frame over
 * all of them is far smaller than one per row (needs ext-zstd). prompt_logs keeps the row (tokens,
 * response, status) and points here through prompt_archive_id.
 */
class PromptArchive extends Model
{
    // 19: smallest result at a still short pack time; higher levels only cost time (docs/studie/2026-10-29-prompt-archiv-zstd.md).
    private const LEVEL = 19;

    protected $fillable = ['project_id', 'payload'];

    /** Tests only: pretend ext-zstd is (not) loaded; null = ask the runtime. */
    public static ?bool $zstdOverride = null;

    public static function available(): bool
    {
        return self::$zstdOverride ?? function_exists('zstd_compress');
    }

    /** @param  array<int, string>  $texts  prompt_log id => prompt text */
    public static function packing(int $projectId, array $texts): self
    {
        $json = json_encode($texts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return new self(['project_id' => $projectId, 'payload' => zstd_compress($json, self::LEVEL)]);
    }

    /** The text of one call; unpacked once per instance, so a turn's calls cost one decompression. */
    public function textOf(int $promptLogId): string
    {
        return $this->texts()[$promptLogId] ?? '';
    }

    /** @return array<int, string> */
    private function texts(): array
    {
        return once(function (): array {
            // Binary column: a driver that returns a BLOB as a stream (PostgreSQL) would need stream_get_contents() here.
            $json = self::available() ? @zstd_uncompress((string) $this->payload) : false;
            $texts = $json === false ? null : json_decode($json, true);

            if (! is_array($texts)) {
                report(new \RuntimeException("Prompt archive {$this->id} unreadable".(self::available() ? '' : ' (ext-zstd missing)')));

                return [];
            }

            return $texts;
        });
    }
}
