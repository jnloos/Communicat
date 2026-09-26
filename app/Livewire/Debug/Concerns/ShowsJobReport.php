<?php

namespace App\Livewire\Debug\Concerns;

use App\Models\JobLog;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;

/**
 * What the two hosts of the job report share: the full-page JobDebugPanel and
 * the JobDebugFlyout inside a discussion.
 *
 * They render the same markup (x-debug.job-report), so the data that markup
 * needs has to be defined once. When the query lived in both classes they had
 * already drifted: the flyout eager-loaded messages.addressee and the panel did
 * not, so the same view cost one extra query per message on one of the two.
 */
trait ShowsJobReport
{
    /** How many jobs the report lists. Mirrored in the `debug.subheading` string. */
    public const JOB_LIMIT = 50;

    /** When false, incoming job updates are ignored so the view stays put while reading. */
    public bool $live = true;

    public ?int $selectedJobId = null;

    public function togglePause(): void
    {
        $this->live = ! $this->live;
    }

    /** Clicking the open job closes it again, so one control does both. */
    public function selectJob(int $jobId): void
    {
        $this->selectedJobId = $this->selectedJobId === $jobId ? null : $jobId;
    }

    #[On('echo-private:debug,.JobLogUpdated')]
    public function onJobLogUpdated(): void
    {
        if ($this->jobReportIsFrozen()) {
            $this->skipRender();
        }
    }

    /**
     * Why a host would ignore a live update. Paused means someone is reading;
     * the flyout widens this to "closed", because a panel nobody looks at
     * should not re-query on every turn.
     */
    protected function jobReportIsFrozen(): bool
    {
        return ! $this->live;
    }

    /**
     * The report's two queries for one project. Every relation the shared
     * markup touches is eager-loaded here — `messages.addressee` included,
     * which the list of prompt-log columns below is the other half of.
     *
     * @return array{logs: Collection<int, JobLog>, selected: ?JobLog}
     */
    protected function jobReportFor(?int $projectId): array
    {
        if ($projectId === null) {
            return ['logs' => collect(), 'selected' => null];
        }

        return [
            'logs' => JobLog::where('project_id', $projectId)->latest()->take(self::JOB_LIMIT)->get(),
            'selected' => $this->selectedJobId === null ? null : JobLog::with([
                'project',
                'promptLogs:id,job_log_id,label,model,prompt,response,latency_ms,created_at,status,error,provider,checkpoint,purpose,reasoning,tokens_in,tokens_out,tokens_reasoning',
                'messages.expert',
                'messages.addressee',
            ])
                ->where('project_id', $projectId)
                ->find($this->selectedJobId),
        ];
    }
}
