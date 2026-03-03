<?php
namespace App\Services;

use App\Models\Project;
use InvalidArgumentException;

final class DiscussionStates
{
    protected const IDLE = 'idle';

    public const GENERATING = 'generating';
    public const SUMMARIZING = 'summarizing';

    protected Project $project;

    public function __construct(Project $project) {
        $this->project = $project;
    }

    public function state(): string {
        return $this->project->state ?? self::IDLE;
    }

    public function set(string $state): void {
        if(!in_array($state, [self::IDLE, self::GENERATING, self::SUMMARIZING]))
            throw new InvalidArgumentException();

        $this->project-> state = $state;
        $this->project->save();
    }

    public function startGenerating(): void {
        $this->set(self::GENERATING);
    }

    public function startSummarizing(): void {
        $this->set(self::SUMMARIZING);
    }

    public function reset(): void {
        $this->set(self::IDLE);
    }

    public function isIdle(): bool {
        return $this->state() === self::IDLE;
    }

    public function isGenerating(): bool {
        return $this->state() === self::GENERATING;
    }

    public function isSummarizing(): bool {
        return $this->state() === self::SUMMARIZING;
    }

    public function isBusy(): bool {
        return ! $this->isIdle();
    }
}
