<?php

namespace App\Services;

use App\Models\Expert;
use App\Models\Project;
use RuntimeException;

class ExpertSuggester
{
    public function __construct(protected OpenAIClient $openAI) {}

    /**
     * Suggest up to $topN experts that fit the given project.
     *
     * @return array<int, array{expert_id:int, reason:string}>
     *
     * @throws RuntimeException on unusable LLM output
     */
    public function suggest(Project $project, int $topN = 5): array
    {
        $experts = Expert::with('tags')
            ->orderBy('name')
            ->get()
            ->map(fn (Expert $e) => [
                'id' => $e->id,
                'name' => $e->name,
                'job' => $e->job,
                'description' => $e->description,
                'tags' => $e->tags->pluck('name')->all(),
            ])
            ->all();

        if (count($experts) === 0) {
            return [];
        }

        $prompt = view('prompts.suggest-experts', [
            'project' => [
                'title' => $project->title,
                'description' => $project->description,
            ],
            'experts' => $experts,
            'topN' => $topN,
        ])->render();

        $raw = $this->openAI->sendFast($prompt, 'expert-suggest');

        $decoded = $this->decodeJson($raw);
        if ($decoded === null || ! isset($decoded['suggestions']) || ! is_array($decoded['suggestions'])) {
            throw new RuntimeException('Expert suggestion LLM returned unusable output.');
        }

        $validIds = Expert::pluck('id')->all();
        $validSet = array_flip($validIds);

        $result = [];
        $seen = [];

        foreach ($decoded['suggestions'] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $id = isset($entry['expert_id']) ? (int) $entry['expert_id'] : 0;
            $reason = isset($entry['reason']) && is_string($entry['reason'])
                ? trim($entry['reason'])
                : '';

            if ($id === 0 || ! isset($validSet[$id]) || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $result[] = [
                'expert_id' => $id,
                'reason' => mb_substr($reason, 0, 160),
            ];

            if (count($result) >= $topN) {
                break;
            }
        }

        return $result;
    }

    protected function decodeJson(string $raw): ?array
    {
        $text = trim($raw);

        if ($text === '') {
            return null;
        }

        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $text, $m) === 1) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
