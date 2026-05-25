<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Expert extends Model
{
    use HasFactory;

    protected $casts = [
        'core_beliefs'     => 'array',
        'knowledge_limits' => 'array',
    ];

    protected $fillable = [
        'name',
        'avatar_url',
        'job',
        'description',
        'profile',
        'core_beliefs',
        'knowledge_limits',
        'style',
        'voice_id',
    ];

    public function summaries(): HasMany
    {
        return $this->hasMany(Summary::class);
    }

    public function projects(): MorphToMany
    {
        return $this->morphToMany(Project::class, 'contributor', 'project_contributors');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function thoughtsAbout(int|Project $project): Summary
    {
        if ($project instanceof Project) {

            $project = $project->id;
        }

        return Summary::firstOrCreate(
            ['project_id' => $project, 'expert_id' => $this->id],
            ['content' => '']
        );
    }

    public function isContributing(Project $project): bool
    {
        return $this->projects()->whereKey($project->id)->exists();
    }

    public static function findByName(string $name): self
    {
        return static::where('name', trim($name))->firstOrFail();
    }

    public static function findManyByName(array $names): \Illuminate\Support\Collection
    {
        return static::whereIn('name', array_map('trim', $names))->get();
    }

    public function asPromptArray(Project $project): array
    {
        $summary = $this->thoughtsAbout($project);

        $persona = collect([
            'Profil'            => $this->profile,
            'Kernüberzeugungen' => $this->formatPersonaList($this->core_beliefs),
            'Wissensgrenzen'    => $this->formatPersonaList($this->knowledge_limits),
            'Stil'              => $this->style,
        ])
            ->filter()
            ->map(fn ($v, $k) => "[$k]\n$v")
            ->implode("\n\n");

        return [
            'name'        => $this->name,
            'expert_id'   => $this->id,
            'job'         => $this->job,
            'description' => $persona,
            'thoughts'    => $summary,
        ];
    }

    private function formatPersonaList(mixed $items): ?string
    {
        if (empty($items)) {
            return null;
        }
        if (is_string($items)) {
            return $items;
        }
        $filtered = array_values(array_filter($items, fn ($v) => trim((string) $v) !== ''));
        if (empty($filtered)) {
            return null;
        }

        return implode("\n", array_map(fn ($v, $i) => ($i + 1) . '. ' . $v, $filtered, array_keys($filtered)));
    }
}
