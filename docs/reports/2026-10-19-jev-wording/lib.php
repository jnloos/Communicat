<?php

// Wording experiment for the Jev orchestrator question. Read-only on the DB; calls TypeSafe directly (no prompt_logs rows).
require __DIR__.'/boot.php';

use App\Discussion\Memory\Memory;
use App\Discussion\Selectors\JevSpeakerSelector;
use App\Discussion\Support\PersonaLines;
use App\Discussion\Support\PromptRenderer;
use App\Discussion\Support\SeededShuffle;
use App\Discussion\Values\MemoryView;
use App\Models\Message;
use App\Models\Project;

const CELLS = [ // cell => [kind, twin used for the alternative roster]
    185 => ['pos', 186], 187 => ['pos', 188], 189 => ['pos', 190], 191 => ['pos', 192],
    186 => ['eq', 185], 188 => ['eq', 187], 190 => ['eq', 189], 192 => ['eq', 191],
    241 => ['neg', 186], 242 => ['neg', 188], 243 => ['neg', 190], 244 => ['neg', 192],
];
const TURNS = 60;
const FOLD_AT = 40;
const FOLD_OLDEST = 20;
const TAIL = ' You may choose the same person several times in a row.';
const VARIANTS = [
    'V0' => null, // JevSpeakerSelector::DECISION, as it runs today
    'V1' => 'Choose exactly one person from the participant list who is most likely to speak next.'.TAIL,
    'V2' => 'Choose exactly one person from the participant list whose turn it is to speak next.'.TAIL,
    'V3' => 'Choose exactly one person from the participant list who should speak next.'.TAIL,
];

function instructions(string $variant): string
{
    return VARIANTS[$variant] ?? JevSpeakerSelector::DECISION;
}

/** @return array{project: Project, experts: array, labels: array<string,string>, focus: string, messages: list<Message>} */
function loadCell(int $id): array
{
    $project = Project::findOrFail($id);
    $experts = $project->contributingExperts()->all();
    $labels = [];
    foreach ($experts as $e) {
        $labels[$e->name] = PersonaLines::for($project, $e)->rosterLabel();
    }
    $focus = collect($experts)->first(fn ($e) => $e->tagValues->contains(fn ($v) => $v->fullLabel() === 'Focus persona: true'));
    $messages = $project->participantMessages()->whereNotNull('expert_id')
        ->with(['expert:id,name', 'user:id,name', 'addressee:id,name'])->orderBy('id')->get()->values()->all();
    assert(count($messages) === TURNS);

    return compact('project', 'experts', 'labels', 'focus', 'messages');
}

/** Per turn: the visible History as Memory::describe() builds it, plus facts for the metrics. */
function states(array $cell): array
{
    $memory = app(Memory::class);
    $pending = [];
    $folded = 0;
    $watermark = null;
    $out = [];

    foreach ($cell['messages'] as $i => $message) {
        $t = $i + 1;
        $history = [];
        foreach ($pending as $offset => $m) {
            $history[] = $memory->describe($m, $folded + $offset + 1);
        }
        $counts = array_count_values(array_filter(array_column($history, 'token')));
        arsort($counts);
        $top = $counts ? max($counts) : 0;
        $last = $pending ? end($pending) : null;

        $out[$t] = [
            't' => $t,
            'history' => $history,
            'actual' => $message->expert->promptId,
            'last' => $last?->expert->promptId,
            'last_addressee' => $last?->addressee?->promptId,
            'modal' => array_keys(array_filter($counts, fn ($c) => $c === $top)), // all speakers at the top
        ];

        $pending[] = $message;
        if (count($pending) >= FOLD_AT) {
            $watermark = $pending[FOLD_OLDEST - 1]->id;
            $pending = array_slice($pending, FOLD_OLDEST);
            $folded += FOLD_OLDEST;
        }
    }

    // The replay's window must end where the real run's Summarize stage left its watermark.
    assert($cell['project']->summarized_until_message_id === $watermark);

    return $out;
}

/** @return array{state: string, options: array<string,string>, instructions: string} */
function request(array $cell, array $state, string $variant, string $order, string $roster, array $twinLabels): array
{
    $project = $cell['project'];
    $experts = $cell['experts'];
    if ($order === 'shuf') {
        $experts = app(SeededShuffle::class)->shuffle($experts, $project, $state['t']);
    }
    $memory = new MemoryView($state['history'], '', ''); // Long-Term was not stored per turn, so it is empty in every replayed state
    $text = app(PromptRenderer::class)->render('prompts.jev.orchestrator', [
        'project' => $project, 'memory' => $memory, 'experts' => collect($experts),
    ]);

    $options = [];
    foreach ($experts as $e) {
        $own = $cell['labels'][$e->name];
        $label = $roster === 'alt' ? $twinLabels[$e->name] : $own;
        if ($roster === 'alt') {
            $text = str_replace("{$e->name} [{$e->promptId}]{$own}", "{$e->name} [{$e->promptId}]{$label}", $text);
        }
        $options[$e->promptId] = mb_substr($e->name.$label, 0, 252);
    }

    return ['state' => $text, 'options' => $options, 'instructions' => instructions($variant)];
}
