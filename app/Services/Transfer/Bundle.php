<?php

namespace App\Services\Transfer;

/**
 * The bundle format: a ZIP of JSON files. One format for a single project, a
 * single expert and the whole study, so one importer reads all three.
 */
final class Bundle
{
    public const SCHEMA_VERSION = 1;

    /** Guard against zip bombs: what an import may unpack in total. */
    public const MAX_UNPACKED_BYTES = 524_288_000;

    /** One entry is read into memory whole, so a single entry is capped on its own. */
    public const MAX_ENTRY_BYTES = 104_857_600;

    public const KIND_PROJECT = 'project';

    public const KIND_EXPERT = 'expert';

    public const KIND_GLOBAL = 'global';

    public const MANIFEST = 'manifest.json';

    public const TAGS = 'tags.json';

    public const EXPERTS = 'experts.json';

    public const GROUPS = 'groups.json';

    public const QUESTIONS = 'questions.json';

    /** Entry names a bundle may contain; anything else is refused. */
    public const ENTRY_PATTERN = '#^(?:(?:manifest|tags|experts|groups|questions)\.json|projects/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.json)$#D';

    public static function projectEntry(string $uuid): string
    {
        return "projects/{$uuid}.json";
    }
}
