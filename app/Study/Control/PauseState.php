<?php

namespace App\Study\Control;

use Carbon\CarbonImmutable;

/** Why and since when the study is paused, as pause.json holds it. */
final readonly class PauseState
{
    public function __construct(
        public string $reason,
        public string $signal,
        public ?PauseSource $source,
        public CarbonImmutable $since,
    ) {}

    /** A file nobody can read still pauses: the safe reading of a half-written or damaged flag. */
    public static function unreadable(CarbonImmutable $since): self
    {
        return new self('unreadable', 'unreadable', null, $since);
    }

    public static function fromJson(string $json, CarbonImmutable $fileTime): self
    {
        $data = json_decode($json, true);

        if (! is_array($data) || ! is_string($data['reason'] ?? null) || ! is_string($data['signal'] ?? null) || ! is_string($data['since'] ?? null)) {
            return self::unreadable($fileTime);
        }

        return new self($data['reason'], $data['signal'], PauseSource::tryFrom((string) ($data['source'] ?? '')), CarbonImmutable::parse($data['since']));
    }

    public function toJson(): string
    {
        return json_encode([
            'reason' => $this->reason,
            'signal' => $this->signal,
            'source' => $this->source?->value,
            'since' => $this->since->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
