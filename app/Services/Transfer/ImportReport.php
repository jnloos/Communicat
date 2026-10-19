<?php

namespace App\Services\Transfer;

/** What an import did, per section: each entry created, skipped or failed, with a note why. */
final class ImportReport
{
    public const CREATED = 'created';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    /** Badge colour per outcome, in the order the report shows them. */
    public const COLORS = [self::CREATED => 'green', self::SKIPPED => 'zinc', self::FAILED => 'red'];

    public const TAGS = 'tags';

    public const PERSONA_ATTRIBUTES = 'persona_attributes';

    public const EXPERTS = 'experts';

    public const PROJECTS = 'projects';

    public const GROUPS = 'groups';

    public const QUESTIONS = 'questions';

    private const ORDER = [self::TAGS, self::PERSONA_ATTRIBUTES, self::EXPERTS, self::PROJECTS, self::GROUPS, self::QUESTIONS];

    /** @var array<string, list<array{name: string, outcome: string, note: ?string}>> */
    private array $entries = [];

    public function add(string $section, string $name, string $outcome, ?string $note = null): void
    {
        $this->entries[$section][] = ['name' => $name, 'outcome' => $outcome, 'note' => $note];
    }

    /** @return list<array{name: string, outcome: string, note: ?string}> */
    public function entries(string $section): array
    {
        return $this->entries[$section] ?? [];
    }

    /**
     * The section's entries with the failed ones first, each group in the order it was added.
     *
     * @return list<array{name: string, outcome: string, note: ?string}>
     */
    public function entriesFailedFirst(string $section): array
    {
        $entries = $this->entries($section);
        $failed = array_filter($entries, fn (array $entry) => $entry['outcome'] === self::FAILED);

        return [...array_values($failed), ...array_values(array_diff_key($entries, $failed))];
    }

    public function count(string $section, string $outcome): int
    {
        return count(array_filter($this->entries($section), fn (array $entry) => $entry['outcome'] === $outcome));
    }

    /** @return list<string> */
    public function sections(): array
    {
        return array_values(array_filter(self::ORDER, fn (string $section) => $this->entries($section) !== []));
    }

    /** True when no section creates anything: everything in the bundle is already here, or it is empty. */
    public function createsNothing(): bool
    {
        foreach ($this->sections() as $section) {
            if ($this->count($section, self::CREATED) > 0) {
                return false;
            }
        }

        return true;
    }

    /** True when there are entries and every one is skipped: the bundle is already here, nothing failed. */
    public function isAllSkipped(): bool
    {
        return $this->sections() !== [] && $this->createsNothing() && ! $this->hasFailures();
    }

    public function hasFailures(): bool
    {
        foreach ($this->sections() as $section) {
            if ($this->count($section, self::FAILED) > 0) {
                return true;
            }
        }

        return false;
    }

    public function toArray(): array
    {
        return $this->entries;
    }

    public static function fromArray(array $data): self
    {
        $report = new self;

        foreach ($data as $section => $entries) {
            foreach ($entries as $entry) {
                $report->add((string) $section, (string) $entry['name'], (string) $entry['outcome'], $entry['note'] ?? null);
            }
        }

        return $report;
    }
}
