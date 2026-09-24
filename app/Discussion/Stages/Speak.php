<?php

namespace App\Discussion\Stages;

use App\Discussion\Agents\SpeakAgent;
use App\Discussion\Memory\Memory;
use App\Discussion\ParseFailure;
use App\Discussion\Support\PromptRenderer;
use App\Discussion\TurnPayload;
use App\Discussion\Values\Completion;
use App\Discussion\Values\Contribution;
use App\Discussion\Values\ModelConfig;
use App\Events\PipelineStageChanged;
use App\Models\Expert;
use App\Models\Message;
use App\Models\Project;
use Closure;

/** The selected speaker writes the public contribution. Needs SelectSpeaker before it. */
class Speak
{
    public const MARKER_CONTROL = '---STEUERUNG---';

    public const PAIR_TYPES = [
        Message::PAIR_FRAGE_ANTWORT,
        Message::PAIR_ANSPRACHE_REAKTION,
        Message::PAIR_BEITRAG_DISKUSSION,
        Message::PAIR_SYNTHESE_DISKUSSION,
    ];

    public function __construct(
        private readonly PromptRenderer $prompts,
        private readonly Memory $memory,
    ) {}

    public function handle(TurnPayload $payload, Closure $next)
    {
        $speaker = $payload->selection()->speaker;

        PipelineStageChanged::announce($payload->project->id, 'speaking', [$speaker]);

        $completion = (new SpeakAgent(
            ModelConfig::fromConfig($payload->project->model),
            $payload->jobLogId,
            $speaker->id,
        ))->ask($this->prompt($payload, $speaker));

        $payload->contribute($this->parse($completion, $payload->project));

        return $next($payload);
    }

    private function prompt(TurnPayload $payload, Expert $speaker): string
    {
        return $this->prompts->render('prompts.speak', [
            'expert' => $speaker,
            'project' => $payload->project,
            'memory' => $this->memory->viewFor($payload->project, $speaker),
            'proposal' => $payload->thoughtOf($speaker)?->proposal,
            'marker_control' => self::MARKER_CONTROL,
            'pair_types' => self::PAIR_TYPES,
        ] + $this->prompts->participants($payload->project));
    }

    /** The visible prose is never parsed; only the trailer after the marker is. */
    private function parse(Completion $completion, Project $project): Contribution
    {
        $position = mb_strpos($completion->text, self::MARKER_CONTROL);

        $text = trim($position === false ? $completion->text : mb_substr($completion->text, 0, $position));
        $trailer = $position === false ? '' : mb_substr($completion->text, $position + mb_strlen(self::MARKER_CONTROL));

        if ($text === '') {
            throw new ParseFailure('Speak: the answer has no visible contribution.');
        }

        return new Contribution($text, $this->partnerToken($trailer, $project), $this->pairType($trailer), $completion);
    }

    private function partnerToken(string $trailer, Project $project): ?string
    {
        if (! preg_match('/ADRESSAT:\s*(\S+)/u', $trailer, $match)) {
            return null;
        }

        return $project->contributorByPromptId($match[1]) instanceof Expert ? $match[1] : null;
    }

    private function pairType(string $trailer): ?string
    {
        if (! preg_match('/PAARTYP:\s*(.+)/u', $trailer, $match)) {
            return null;
        }

        $value = trim($match[1]);

        return in_array($value, self::PAIR_TYPES, true) ? $value : null;
    }
}
