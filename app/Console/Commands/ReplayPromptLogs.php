<?php

namespace App\Console\Commands;

use App\Models\Expert;
use App\Models\Message;
use App\Models\PromptLog;
use App\Services\PromptingPipeline\Support\SpeakTrailerResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Replay recorded SPEAK responses through the current parser + resolver and
 * report format compliance. No API calls, no writes.
 *
 * This exists because the addressing defect was only visible in aggregate: the
 * trailer was missing in ~20% of the most recent turns and self-contradicting in
 * others, which no single run makes obvious. Running this before and after a
 * prompt or parser change turns "feels better" into a number.
 */
class ReplayPromptLogs extends Command
{
    protected $signature = 'replay:prompt-logs {--limit=0 : Only replay the newest N speak calls}';

    protected $description = 'Replay logged SPEAK responses through the parser and report addressing compliance';

    public function handle(): int
    {
        $logs = $this->speakLogs();

        if ($logs->isEmpty()) {
            $this->warn('No speak prompt_logs found — nothing to replay.');

            return self::SUCCESS;
        }

        $stats = [
            'total' => $logs->count(),
            'trailer_missing' => 0,
            'addressee_from_trailer' => 0,
            'addressee_from_prose' => 0,
            'no_addressee' => 0,
            'dangling_question' => 0,
            'user_handoff' => 0,
            'invalid_pair_type' => 0,
        ];

        foreach ($logs as $log) {
            $this->tally($log, $stats);
        }

        $resolved = $stats['addressee_from_trailer'] + $stats['addressee_from_prose'];
        $questions = $stats['addressee_from_trailer'] + $stats['addressee_from_prose'] + $stats['dangling_question'];

        $this->table(['Metrik', 'Wert'], [
            ['SPEAK-Antworten geprüft', $stats['total']],
            ['Trailer fehlt komplett', $this->pct($stats['trailer_missing'], $stats['total'])],
            ['Ungültiger PAARTYP', $this->pct($stats['invalid_pair_type'], $stats['total'])],
            ['Adressat aus Trailer', $this->pct($stats['addressee_from_trailer'], $stats['total'])],
            ['Adressat aus Prosa gerettet', $this->pct($stats['addressee_from_prose'], $stats['total'])],
            ['Plenum (kein Adressat)', $this->pct($stats['no_addressee'], $stats['total'])],
            ['Frage an den Nutzer (Übergabe)', $this->pct($stats['user_handoff'], $stats['total'])],
            ['OFFENE FRAGE OHNE ADRESSAT', $this->pct($stats['dangling_question'], $stats['total'])],
        ]);

        if ($questions > 0) {
            $this->line('');
            $this->info(sprintf(
                'Fragen mit auflösbarem Adressaten: %s (Ziel: > 95%%)',
                $this->pct($resolved, $questions),
            ));
        }

        if ($stats['dangling_question'] > 0) {
            $this->warn($stats['dangling_question'].' Beitrag/Beiträge stellen eine Frage, die niemanden adressiert.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $stats
     */
    protected function tally(PromptLog $log, array &$stats): void
    {
        $parsed = $this->parseSpeak($log->response);

        if (! $parsed['trailer_present']) {
            $stats['trailer_missing']++;
        } elseif ($parsed['pair_type_raw'] !== null && $parsed['adjacency_pair_type'] === null) {
            $stats['invalid_pair_type']++;
        }

        $speakerId = (int) str_replace('speak:', '', (string) $log->label);
        $peers = $this->rosterFor($log->prompt)
            ->reject(fn (Expert $e) => $e->id === $speakerId)
            ->values();

        $resolved = app(SpeakTrailerResolver::class)->reconcile($parsed, $peers);

        if ($resolved['adjacency_partner_token'] !== null) {
            $stats[$resolved['resolver_source'] === SpeakTrailerResolver::SOURCE_PROSE
                ? 'addressee_from_prose'
                : 'addressee_from_trailer']++;

            return;
        }

        if ($resolved['ends_with_question']) {
            // A question on a hand-off turn is aimed at the human and is
            // supposed to have no expert addressee — not a defect.
            if ($this->wasHandoffTurn($log->prompt)) {
                $stats['user_handoff']++;

                return;
            }

            // Otherwise nobody is on the hook: the pair can never be closed,
            // and the round falls back to pestering the user.
            $stats['dangling_question']++;

            return;
        }

        $stats['no_addressee']++;
    }

    /**
     * Parse a raw SPEAK response the way AgentService does, plus the raw PAARTYP
     * so invalid values stay countable (AgentService drops them to null).
     *
     * @return array<string, mixed>
     */
    protected function parseSpeak(string $response): array
    {
        $marker = '---STEUERUNG---';
        $pos = mb_strpos($response, $marker);
        $trailer = $pos === false ? '' : mb_substr($response, $pos + mb_strlen($marker));

        preg_match('/PAARTYP:\s*(.+)/u', $trailer, $pairMatch);
        preg_match('/ADRESSAT:\s*(\S+)/u', $trailer, $addrMatch);

        $pairRaw = isset($pairMatch[1]) ? trim($pairMatch[1]) : null;
        $token = isset($addrMatch[1]) ? trim($addrMatch[1]) : null;
        $allowed = [
            Message::PAIR_FRAGE_ANTWORT,
            Message::PAIR_ANSPRACHE_REAKTION,
            Message::PAIR_BEITRAG_DISKUSSION,
            Message::PAIR_SYNTHESE_DISKUSSION,
        ];

        return [
            'content' => trim($pos === false ? $response : mb_substr($response, 0, $pos)),
            'adjacency_pair_type' => in_array($pairRaw, $allowed, true) ? $pairRaw : null,
            'adjacency_partner_token' => $token !== null && preg_match('/^E\d+$/', $token) ? $token : null,
            'pair_type_raw' => $pairRaw,
            'trailer_present' => $pos !== false,
        ];
    }

    /**
     * Whether the recorded prompt instructed the agent to hand back to the
     * human. Those turns are supposed to end in a question with no expert
     * addressee, so they must not count against the dangling-question metric.
     */
    protected function wasHandoffTurn(string $prompt): bool
    {
        return str_contains($prompt, 'NUTZER-ANSPRACHE (HARTE REGEL');
    }

    /**
     * Rebuild the participant roster from the recorded prompt text, so names
     * resolve exactly as they did at generation time.
     *
     * @return Collection<int, Expert>
     */
    protected function rosterFor(string $prompt): Collection
    {
        preg_match_all('/^-\s*(.+?)\s*\[(E\d+)\]/mu', $prompt, $matches, PREG_SET_ORDER);

        return collect($matches)
            ->map(function (array $m) {
                $expert = new Expert(['name' => trim($m[1])]);
                $expert->id = (int) substr($m[2], 1);

                return $expert;
            })
            ->unique(fn (Expert $e) => $e->id)
            ->values();
    }

    /**
     * @return Collection<int, PromptLog>
     */
    protected function speakLogs(): Collection
    {
        $query = PromptLog::query()
            ->where('label', 'like', 'speak:%')
            ->where('prompt', 'like', '%STEUERUNG%')
            ->orderByDesc('id');

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->get();
    }

    protected function pct(int $value, int $total): string
    {
        return $total === 0
            ? (string) $value
            : sprintf('%d (%.0f%%)', $value, $value / $total * 100);
    }
}
