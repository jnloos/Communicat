<?php

namespace App\Discussion\Metrics;

use App\Discussion\Values\CostLine;
use App\Discussion\Values\CostReport;
use App\Models\Project;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What a run cost, summed from prompt_logs and the prices in config/ai.php.
 *
 * Three rules, and why:
 *
 * - **Every call counts, not only the successful ones.** A refused or filtered
 *   answer was still generated and is still billed; leaving it out would make
 *   a run with a high failure rate look cheap. A call that never reached the
 *   provider has no tokens and therefore adds nothing on its own.
 * - **Reasoning tokens are not added.** The providers report them inside the
 *   output count and bill them at the output rate, so `tokens_out` already
 *   holds them -- adding `tokens_reasoning` would charge them twice.
 * - **An unpriced model yields null, not zero.** A missing price is a gap in
 *   the bookkeeping, and a zero would read as "this was free".
 */
class RunCosts
{
    public function forProject(Project $project): CostReport
    {
        return $this->report($project, null);
    }

    /** The cost of the calls made after that prompt log -- one command's own run. */
    public function forProjectSince(Project $project, int $promptLogId): CostReport
    {
        return $this->report($project, $promptLogId);
    }

    /** The largest prompt log id there is, to be handed back to forProjectSince(). */
    public function mark(): int
    {
        return (int) DB::table('prompt_logs')->max('id');
    }

    private function report(Project $project, ?int $afterPromptLogId): CostReport
    {
        $prices = $this->pricesByModel();

        $rows = DB::table('prompt_logs')
            ->join('job_logs', 'job_logs.id', '=', 'prompt_logs.job_log_id')
            ->where('job_logs.project_id', $project->id)
            ->when($afterPromptLogId !== null, fn (Builder $query) => $query->where('prompt_logs.id', '>', $afterPromptLogId))
            ->groupBy('prompt_logs.provider', 'prompt_logs.model')
            ->selectRaw('prompt_logs.provider as provider, prompt_logs.model as model')
            ->selectRaw('count(*) as calls, coalesce(sum(tokens_in), 0) as tokens_in, coalesce(sum(tokens_out), 0) as tokens_out')
            ->get();

        $lines = $rows
            ->map(function (object $row) use ($prices) {
                $price = $prices[$row->provider.'/'.$row->model] ?? null;
                $tokensIn = (int) $row->tokens_in;
                $tokensOut = (int) $row->tokens_out;

                return new CostLine(
                    provider: (string) $row->provider,
                    model: (string) $row->model,
                    calls: (int) $row->calls,
                    tokensIn: $tokensIn,
                    tokensOut: $tokensOut,
                    usd: $price === null
                        ? null
                        : ($tokensIn * $price['input'] + $tokensOut * $price['output']) / 1_000_000,
                );
            })
            ->all();

        return new CostReport($lines);
    }

    /**
     * Prices keyed by what prompt_logs stores -- provider and literal model id --
     * because the registry key never reaches the log.
     *
     * @return array<string, array{input: float, output: float}>
     */
    private function pricesByModel(): array
    {
        $prices = [];

        foreach (config('ai.models') as $model) {
            if (($model['price'] ?? null) === null) {
                continue;
            }

            $prices[$model['provider'].'/'.$model['model']] = [
                'input' => (float) $model['price']['input'],
                'output' => (float) $model['price']['output'],
            ];
        }

        return $prices;
    }
}
