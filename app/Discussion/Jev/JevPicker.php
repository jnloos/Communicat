<?php

namespace App\Discussion\Jev;

use App\Discussion\Support\TieBreaker;
use App\Discussion\TurnPayload;
use App\Discussion\Values\JevDecides;
use App\Discussion\Values\JevDecisionModel;
use App\Discussion\Values\JevPick;
use App\Discussion\Values\Purpose;
use App\Models\Expert;
use Illuminate\Support\Str;
use Laravel\Ai\Classification\Choice;

/**
 * What the three Jev choice selectors share: one `choice` question over a closed
 * list of options, read for the top probability, a tie handed to the TieBreaker,
 * and a fallback to the TieBreaker when Jev gives nothing readable.
 *
 * No balancing: the same expert may win any number of times in a row, and a tie
 * (equal probabilities to the sixth place) is a reproducible draw from the
 * project seed, never in favour of whoever spoke least.
 *
 * A turn in which the floor was drawn because Jev gave nothing is reported to
 * JevOutageGuard, which halts the turn (TurnPayload::halt(), before anybody
 * speaks) once that has happened turn after turn.
 */
class JevPicker
{
    public const QUESTION = 'speaker';

    /** TypeSafe accepts at most 255 characters per criterion. */
    private const DESCRIPTION_LIMIT = 255;

    public function __construct(
        private readonly JevClient $jev,
        private readonly TieBreaker $tieBreaker,
        private readonly JevOutageGuard $outage,
    ) {}

    /**
     * One candidate decides nothing and a choice question needs two options, so
     * Jev is not asked: the candidate speaks, flagged as not asked (and not as a
     * fallback, nothing went wrong). There are no probabilities then.
     *
     * @param  array<string, Expert>  $candidates  option => expert; the options are exactly what Jev may answer with
     * @param  array<string, string>  $descriptions  option => what the option is (short: a name with its role, or a draft's letter)
     */
    public function pick(TurnPayload $payload, Purpose $purpose, string $state, string $instructions, array $candidates, array $descriptions): JevPick
    {
        if (count($candidates) === 1) {
            $option = array_key_first($candidates);

            return new JevPick($candidates[$option], (string) $option, tieBroken: false, fallback: false, asked: false);
        }

        $model = JevDecisionModel::forProject($payload->project, JevDecides::Selector);
        $options = array_map(fn (string $text) => Str::limit($text, self::DESCRIPTION_LIMIT - 3), $descriptions);
        $reply = $this->jev->ask($model, $state, [self::QUESTION => new Choice($instructions, $options)], $purpose, $payload->jobLogId);
        $read = $reply->choice(self::QUESTION, array_keys($candidates));

        $pick = $read === null
            ? $this->drawn($payload, $candidates, array_keys($candidates), [], null, fallback: true, asked: true, model: $reply->servedModel(), latencyMs: $reply->latencyMs)
            : $this->drawn($payload, $candidates, $read->leaders, $read->probabilities, $read->confidence, fallback: false, asked: true, model: $reply->servedModel(), latencyMs: $reply->latencyMs);

        $this->outage->haltIfDown($payload, $pick->fellBack());

        return $pick;
    }

    /**
     * The floor goes to a draw when there was nothing to put to Jev at all (no
     * draft handed in). Recorded as a fallback, like the counterpart selectors,
     * and as not asked: it says nothing about whether Jev is reachable.
     *
     * @param  array<string, Expert>|list<Expert>  $candidates
     */
    public function unasked(TurnPayload $payload, array $candidates): JevPick
    {
        return $this->drawn($payload, $candidates, array_keys($candidates), [], null, fallback: true, asked: false, model: null, latencyMs: null);
    }

    /**
     * @param  array<string|int, Expert>  $candidates
     * @param  list<string|int>  $leaders  the keys of $candidates in the running
     * @param  array<string, float>  $probabilities
     */
    private function drawn(TurnPayload $payload, array $candidates, array $leaders, array $probabilities, ?float $confidence, bool $fallback, bool $asked, ?string $model, ?int $latencyMs): JevPick
    {
        $tied = count($leaders) > 1;
        $speaker = $tied
            ? $this->tieBreaker->pick(array_map(fn ($key) => $candidates[$key], $leaders), $payload->project, $payload->turnIndex)
            : $candidates[$leaders[0]];

        $key = collect($leaders)->first(fn ($leader) => $candidates[$leader]->id === $speaker->id);

        // A fallback is a draw like a tie, and is flagged as one, as in the counterpart selectors.
        return new JevPick($speaker, $fallback ? null : (string) $key, $tied || $fallback, $fallback, $asked, $probabilities, $confidence, $model, $latencyMs);
    }
}
