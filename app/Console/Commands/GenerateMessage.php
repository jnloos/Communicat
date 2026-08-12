<?php

namespace App\Console\Commands;

use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Message;
use App\Models\Project;
use App\Models\Summary;
use App\Models\User;
use App\Services\Clients\OpenAIClient;
use App\Services\PromptingPipeline\DiscussionPipeline;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Run the discussion pipeline synchronously, without the queue, and report the
 * structural metrics afterwards.
 *
 * Doubles as a regression harness and as study instrumentation: the adjacency-
 * pair closure rate and the distribution of speaking turns are the structural
 * naturalness measures the thesis uses, so a prompt change that quietly wrecks
 * them becomes visible here instead of in the transcripts weeks later.
 */
class GenerateMessage extends Command
{
    protected $signature = 'spec:gen-message
        {projectId? : Project to run against (default: the most recently active)}
        {--turns=1 : How many turns to generate}
        {--metrics : Only print the metrics for the existing discussion}
        {--broadcast : Keep the configured broadcaster (needs a running Reverb)}';

    protected $description = 'Run the discussion pipeline synchronously and report structural metrics';

    public function handle(): int
    {
        $project = $this->resolveProject();

        if ($project === null) {
            $this->error('No project found. Create one first (php artisan dev:build-suite).');

            return self::FAILURE;
        }

        // A console run has no websocket clients, and every pipeline stage
        // broadcasts. Without this the whole run dies on the first stage event
        // unless Reverb happens to be up.
        if (! $this->option('broadcast')) {
            config(['broadcasting.default' => 'null']);
        }

        $this->info(sprintf('Projekt %d: %s', $project->id, $project->title));

        if (! $this->option('metrics')) {
            $this->runTurns($project);
        }

        $this->reportMetrics($project->fresh());

        return self::SUCCESS;
    }

    protected function runTurns(Project $project): void
    {
        $turns = max(1, (int) $this->option('turns'));

        for ($turn = 1; $turn <= $turns; $turn++) {
            // Without a bound JobLog nothing lands in prompt_logs, and the run
            // would be unobservable afterwards.
            $log = JobLog::create([
                'job_class' => self::class,
                'project_id' => $project->id,
                'status' => 'running',
                'started_at' => now(),
            ]);

            OpenAIClient::bindJobLog($log->id);

            try {
                $result = (new DiscussionPipeline($project, $log->id))->run();
                $log->update(['status' => 'success', 'finished_at' => now()]);
            } catch (\Throwable $e) {
                $log->update([
                    'status' => 'failed',
                    'finished_at' => now(),
                    'payload' => ['error' => $e->getMessage()],
                ]);
                $this->error("Turn {$turn} fehlgeschlagen: ".$e->getMessage());

                return;
            } finally {
                OpenAIClient::bindJobLog(null);
            }

            $latest = $project->messages()->whereNotNull('expert_id')->latest('id')->first();
            $this->line(sprintf(
                '  Turn %2d  %-18s %-20s %s',
                $turn,
                $latest?->expert?->name ?? '—',
                $latest?->adjacency_pair_type ?? '—',
                ! empty($result['stop']) ? '⏸ wartet auf Nutzer' : '',
            ));

            if (! empty($result['stop'])) {
                $this->warn('  Runde übergibt an den Nutzer und hält an.');

                return;
            }
        }
    }

    protected function reportMetrics(Project $project): void
    {
        $messages = $project->messages()->whereNotNull('expert_id')->orderBy('id')->get();

        if ($messages->isEmpty()) {
            $this->warn('Keine Expertenbeiträge — keine Metriken.');

            return;
        }

        [$opened, $closed, $namedAsker] = $this->pairClosure($messages);
        $handoffs = $messages->where('adjacency_partner_type', User::class)->count();

        $lengths = $messages->map(fn (Message $m) => mb_strlen((string) $m->content))->sort()->values();

        $this->newLine();
        $this->table(['Strukturmetrik', 'Wert'], [
            ['Expertenbeiträge', $messages->count()],
            ['Offene Experten-Paare eröffnet', $opened],
            ['davon geschlossen (verknüpfte Antwort)', $this->pct($closed, $opened)],
            ['davon Antwort nennt den Frager', $this->pct($namedAsker, $closed)],
            ['Übergaben an den Nutzer', $handoffs],
            ['Verhältnis Paare : Übergaben', sprintf('%d : %d', $opened, $handoffs)],
            ['Gini der Redeanteile (0 = gleich)', sprintf('%.2f', $this->gini($messages))],
            ['Beiträge mit Reaktionsmarker', $this->pct($this->withReactionMarker($messages), $messages->count())],
            ['Median-Länge (Zeichen)', (int) ($lengths[intdiv($lengths->count(), 2)] ?? 0)],
            ['Beiträge unter 250 Zeichen', $this->pct($lengths->filter(fn (int $l) => $l < 250)->count(), $lengths->count())],
        ]);

        $this->reportMemory($project);
    }

    /**
     * Pair closure, read off the explicit `answers_message_id` link rather than
     * inferred from "the next speaker happened to be the addressee". The old
     * heuristic counted a turn that never mentions the asker as a closure, and
     * missed any closure that arrived a turn later.
     *
     * The third figure is the one that decides whether a pair is *recognisable*
     * to a reader: does the answer actually name the person who asked?
     *
     * @param  Collection<int, Message>  $messages
     * @return array{0: int, 1: int, 2: int}
     */
    protected function pairClosure(Collection $messages): array
    {
        $answersByPair = $messages->whereNotNull('answers_message_id')->keyBy('answers_message_id');

        $opened = 0;
        $closed = 0;
        $namedAsker = 0;

        foreach ($messages as $message) {
            if (! $message->opensExpertPair()) {
                continue;
            }

            $opened++;
            $answer = $answersByPair->get($message->id);

            if ($answer === null) {
                continue;
            }

            $closed++;

            $askerName = $message->expert?->name;
            $firstName = $askerName !== null ? (preg_split('/\s+/u', $askerName)[0] ?? $askerName) : null;

            if ($firstName !== null && stripos((string) $answer->content, $firstName) !== false) {
                $namedAsker++;
            }
        }

        return [$opened, $closed, $namedAsker];
    }

    /**
     * Turns that carry an actual conversational move (agreement, hedged
     * disagreement, uptake) rather than another block of exposition.
     *
     * @param  Collection<int, Message>  $messages
     */
    protected function withReactionMarker(Collection $messages): int
    {
        $pattern = '/\b(ich stimme|stimme .{0,20}zu|da bin ich|genau,|sehe ich auch|finde ich stark|einverstanden'
            .'|guter punkt|teile ich|dem schließe ich|hmm|nicht sicher, ob|kommt drauf an|widerspreche|das sehe ich anders)/iu';

        return $messages->filter(fn (Message $m) => preg_match($pattern, (string) $m->content) === 1)->count();
    }

    /**
     * Gini coefficient of turns per expert: 0 = everyone speaks equally often,
     * 1 = one persona monopolises the floor.
     *
     * @param  Collection<int, Message>  $messages
     */
    protected function gini(Collection $messages): float
    {
        $counts = array_values($messages->groupBy('expert_id')->map->count()->all());
        sort($counts);
        $n = count($counts);
        $sum = array_sum($counts);

        if ($n < 2 || $sum === 0) {
            return 0.0;
        }

        $weighted = 0;
        foreach ($counts as $i => $count) {
            $weighted += ($i + 1) * $count;
        }

        return (2 * $weighted) / ($n * $sum) - ($n + 1) / $n;
    }

    protected function reportMemory(Project $project): void
    {
        $latestMessageId = (int) $project->messages()->max('id');

        $rows = $project->contributingExperts()->map(function (Expert $expert) use ($project, $latestMessageId) {
            $summary = Summary::where('project_id', $project->id)->where('expert_id', $expert->id)->first();
            $watermark = (int) ($summary->last_message_id ?? 0);

            return [
                $expert->name,
                $project->messages()->where('expert_id', $expert->id)->count(),
                mb_strlen((string) $summary?->content),
                $watermark === 0 ? '—' : $latestMessageId - $watermark,
            ];
        })->all();

        $this->newLine();
        $this->table(['Persona', 'Beiträge', 'Gedächtnis (Zeichen)', 'Rückstand (Nachrichten)'], $rows);
    }

    protected function resolveProject(): ?Project
    {
        $id = $this->argument('projectId');

        if ($id !== null) {
            return Project::find((int) $id);
        }

        return Project::query()
            ->whereHas('messages')
            ->withMax('messages', 'id')
            ->orderByDesc('messages_max_id')
            ->first();
    }

    protected function pct(int $value, int $total): string
    {
        return $total === 0
            ? '—'
            : sprintf('%d (%.0f%%)', $value, $value / $total * 100);
    }
}
