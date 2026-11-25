<?php

namespace App\Models;

use App\Services\Study\Planning\Manifest;
use App\Services\Tags\TagNames;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Thesis study, see docs/study.md.
 *
 * One planned wave of a study: its scenario range and the canonical copy of
 * the manifest it was planned from. The copies are what lets a later wave prove it changed nothing
 * an earlier one planned, and study:check recompute every wave as it was.
 */
class StudyWave extends Model
{
    protected $fillable = [
        'study', 'number', 'first_scenario', 'last_scenario',
        'manifest', 'manifest_sha256', 'core_sha256', 'user_id', 'project_group_id', 'applied_at', 'failed_reason', 'failed_at',
    ];

    protected $casts = ['applied_at' => 'datetime', 'failed_at' => 'datetime'];

    public static function record(Manifest $manifest, int $number, int $from, int $to, ?int $userId): self
    {
        return static::create([
            'study' => $manifest->study(),
            'number' => $number,
            'first_scenario' => $from,
            'last_scenario' => $to,
            'manifest' => $manifest->canonical(),
            'manifest_sha256' => $manifest->sha256(),
            'core_sha256' => $manifest->coreHash(),
            'user_id' => $userId,
        ]);
    }

    public function manifest(): Manifest
    {
        return Manifest::fromStored($this->manifest);
    }

    /** How many runs the wave plans; read from the stored copy without planning it again. */
    public function plannedRuns(): int
    {
        return $this->manifest()->runsPer($this->last_scenario - $this->first_scenario + 1);
    }

    /** How many of its runs exist so far: projects tagged with this study and this wave. */
    public function writtenRuns(): int
    {
        $query = Project::query();

        foreach (['study' => $this->study, 'wave' => (string) $this->number] as $key => $value) {
            $valueIds = TagValue::whereIn('tag_category_id', self::projectCategoryIds(config("study.tags.{$key}")))->where('name', $value)->pluck('id');
            $query->whereHas('tagValues', fn ($tagged) => $tagged->whereIn('tag_values.id', $valueIds));
        }

        return $query->count();
    }

    /** @return list<int> the project tag categories of this name, matched like everywhere else */
    public static function projectCategoryIds(string $name): array
    {
        return TagCategory::ofScope(TagScope::Project)->get()
            ->filter(fn (TagCategory $category) => TagNames::same($category->name, $name))
            ->modelKeys();
    }

    public function hasFailed(): bool
    {
        return $this->failed_at !== null;
    }

    public function isApplied(): bool
    {
        return $this->applied_at !== null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ProjectGroup::class, 'project_group_id');
    }
}
