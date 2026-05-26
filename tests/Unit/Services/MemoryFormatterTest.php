<?php

namespace Tests\Unit\Services;

use App\Services\Text\MemoryFormatter;
use Tests\TestCase;

class MemoryFormatterTest extends TestCase
{
    private MemoryFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new MemoryFormatter();
    }

    public function test_returns_empty_structure_for_empty_input(): void
    {
        $result = $this->formatter->parse('');

        $this->assertFalse($result['structured']);
        $this->assertNull($result['user']);
        $this->assertSame([], $result['experts']);
        $this->assertSame([], $result['open_questions']);
        $this->assertNull($result['state']);
        $this->assertSame('', $result['raw']);
    }

    public function test_parses_token_markers_and_resolves_names(): void
    {
        $block = <<<'TXT'
GEDÄCHTNIS-UPDATE:
[U3]
Möchte schnell skalierbare Lösungen.
[E7]
Pragmatische Backend-Sicht.
[E9]
UX-Fokus.
[STAND]
Diskussion läuft.
TXT;

        $result = $this->formatter->parse($block, [
            'U3' => 'Owner',
            'E7' => 'Sophie Wagner',
            'E9' => 'Lena Fischer',
        ]);

        $this->assertTrue($result['structured']);
        $this->assertSame(['Owner' => 'Möchte schnell skalierbare Lösungen.'], $result['users']);
        $this->assertArrayHasKey('Sophie Wagner', $result['experts']);
        $this->assertArrayHasKey('Lena Fischer', $result['experts']);
        $this->assertSame('Diskussion läuft.', $result['state']);
    }

    public function test_token_markers_fall_back_to_token_when_unmapped(): void
    {
        $result = $this->formatter->parse("[E7]\nNotiz ohne Mapping.");

        $this->assertTrue($result['structured']);
        $this->assertSame(['E7' => 'Notiz ohne Mapping.'], $result['experts']);
    }

    public function test_parses_canonical_marker_format(): void
    {
        $block = <<<'TXT'
[NUTZER]
Möchte schnell skalierbare Lösungen.
[EXPERTE: Sophie Wagner]
Pragmatische Backend-Sicht, betont API-Verträge.
[EXPERTE: Lena Fischer]
UX-Fokus, fordert Komponententests.
[OFFENE_FRAGEN]
- Authentifizierung gegen welches Identity-Backend?
- Migrationspfad für Bestandsdaten?
[STAND]
Diskussion zu OAuth vs. SAML offen.
TXT;

        $result = $this->formatter->parse($block);

        $this->assertTrue($result['structured']);
        $this->assertSame('Möchte schnell skalierbare Lösungen.', $result['user']);

        $this->assertArrayHasKey('Sophie Wagner', $result['experts']);
        $this->assertSame(
            'Pragmatische Backend-Sicht, betont API-Verträge.',
            $result['experts']['Sophie Wagner']
        );

        $this->assertArrayHasKey('Lena Fischer', $result['experts']);
        $this->assertSame(
            'UX-Fokus, fordert Komponententests.',
            $result['experts']['Lena Fischer']
        );

        $this->assertCount(2, $result['open_questions']);
        $this->assertSame(
            'Authentifizierung gegen welches Identity-Backend?',
            $result['open_questions'][0]
        );
        $this->assertSame(
            'Migrationspfad für Bestandsdaten?',
            $result['open_questions'][1]
        );

        $this->assertSame('Diskussion zu OAuth vs. SAML offen.', $result['state']);
    }

    public function test_strips_leading_gedaechtnis_update_header(): void
    {
        $block = "GEDÄCHTNIS-UPDATE:\n[NUTZER]\nDetails.\n[STAND]\nLäuft.";

        $result = $this->formatter->parse($block);

        $this->assertTrue($result['structured']);
        $this->assertSame('Details.', $result['user']);
        $this->assertSame('Läuft.', $result['state']);
    }

    public function test_drops_keine_in_open_questions(): void
    {
        $block = "[NUTZER]\nNutzer.\n[OFFENE_FRAGEN]\nkeine\n[STAND]\nFortgeschritten.";

        $result = $this->formatter->parse($block);

        $this->assertSame([], $result['open_questions']);
    }

    public function test_falls_back_to_raw_for_legacy_freetext_format(): void
    {
        $block = "Was ich über den Nutzer weiß: Mag PHP.\nLetzter Gesprächsstand: Diskussion läuft.";

        $result = $this->formatter->parse($block);

        $this->assertFalse($result['structured']);
        $this->assertSame($block, $result['raw']);
    }

    public function test_ignores_sections_without_a_known_marker(): void
    {
        $block = "[UNBEKANNT]\nInhalt.\n[NUTZER]\nNutzer-Notiz.";

        $result = $this->formatter->parse($block);

        $this->assertTrue($result['structured']);
        $this->assertSame('Nutzer-Notiz.', $result['user']);
    }
}
