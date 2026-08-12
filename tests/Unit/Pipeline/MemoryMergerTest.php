<?php

namespace Tests\Unit\Pipeline;

use App\Services\PromptingPipeline\Support\MemoryMerger;
use App\Services\PromptingPipeline\Support\UserQuestionMemory;
use App\Services\Text\MemoryFormatter;
use Tests\TestCase;

class MemoryMergerTest extends TestCase
{
    private MemoryMerger $merger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merger = new MemoryMerger(new MemoryFormatter);
    }

    /**
     * The defect this class exists for, using the shape of the real regression
     * observed in project 29 (think:35, logs 885→911): the second THINK only
     * repeated one peer and silently dropped the other three.
     */
    public function test_blocks_missing_from_the_new_update_are_carried_over(): void
    {
        $existing = <<<'TXT'
        [E27]
        David Kaufmann pocht auf Budgetklarheit vor jeder Maßnahme.
        [E43]
        Beate Sommerfeld will eine Pilotphase mit messbaren Kriterien.
        [E46]
        Leon Jacobi fordert Adjunkten und Peer-Tutoring.
        [OFFENE_FRAGEN]
        - Frage an Beate Sommerfeld: Welche Module zuerst?
        [STAND]
        Kapazitätsgrenze bei 60 Plätzen pro Modul.
        TXT;

        $incoming = <<<'TXT'
        [E27]
        David Kaufmann verschiebt den Fokus auf Opportunitätskosten.
        [OFFENE_FRAGEN]
        - Frage an David Kaufmann: Welcher Budgetrahmen gilt?
        [STAND]
        Pilotphase mit drei Modulen beschlossen.
        TXT;

        $merged = $this->merger->merge($existing, $incoming);

        // Updated block wins.
        $this->assertStringContainsString('Opportunitätskosten', $merged);
        $this->assertStringNotContainsString('pocht auf Budgetklarheit', $merged);

        // Omitted blocks survive — this is the whole point.
        $this->assertStringContainsString('[E43]', $merged);
        $this->assertStringContainsString('Beate Sommerfeld will eine Pilotphase', $merged);
        $this->assertStringContainsString('[E46]', $merged);
        $this->assertStringContainsString('Leon Jacobi fordert Adjunkten', $merged);

        // Explicitly restated sections are authoritative.
        $this->assertStringContainsString('Pilotphase mit drei Modulen', $merged);
        $this->assertStringContainsString('Welcher Budgetrahmen gilt?', $merged);
        $this->assertStringNotContainsString('Welche Module zuerst?', $merged);
    }

    public function test_an_omitted_open_questions_section_keeps_the_previous_one(): void
    {
        $existing = "[E27]\nx\n[OFFENE_FRAGEN]\n- Frage an David Kaufmann: Budget?\n[STAND]\nAlt.";
        $incoming = "[E27]\ny\n[STAND]\nNeu.";

        $merged = $this->merger->merge($existing, $incoming);

        $this->assertStringContainsString('Budget?', $merged);
        $this->assertStringContainsString('Neu.', $merged);
    }

    /**
     * "keine" is a deliberate statement that nothing is open, unlike an absent
     * section — it must clear the list rather than preserve it.
     */
    public function test_an_explicit_keine_clears_the_open_questions(): void
    {
        $existing = "[E27]\nx\n[OFFENE_FRAGEN]\n- Frage an David Kaufmann: Budget?\n[STAND]\nAlt.";
        $incoming = "[E27]\ny\n[OFFENE_FRAGEN]\nkeine\n[STAND]\nNeu.";

        $merged = $this->merger->merge($existing, $incoming);

        $this->assertStringNotContainsString('Budget?', $merged);
    }

    public function test_participants_who_left_the_project_are_pruned(): void
    {
        $existing = "[E27]\nBleibt.\n[E99]\nHat das Projekt verlassen.\n[STAND]\nx";
        $incoming = "[E27]\nAktualisiert.\n[STAND]\ny";

        $merged = $this->merger->merge($existing, $incoming, ['E27', 'U3']);

        $this->assertStringContainsString('Aktualisiert.', $merged);
        $this->assertStringNotContainsString('[E99]', $merged);
    }

    public function test_an_empty_update_never_clears_an_existing_memory(): void
    {
        $existing = "[E27]\nWichtiges Wissen.\n[STAND]\nStand.";

        $this->assertStringContainsString('Wichtiges Wissen.', $this->merger->merge($existing, ''));
    }

    /**
     * The user question is owned by the project, not the persona, so the merger
     * strips it and lets the caller re-stamp it from the single source. Keeping
     * per-expert copies is what let them drift apart.
     */
    public function test_the_user_question_block_is_not_carried_through_the_merge(): void
    {
        $existing = UserQuestionMemory::upsert("[E27]\nx\n[STAND]\ny", 'Alte Frage?');
        $incoming = UserQuestionMemory::upsert("[E27]\nz\n[STAND]\nw", 'Neue Frage?');

        $merged = $this->merger->merge($existing, $incoming);

        $this->assertStringNotContainsString(UserQuestionMemory::MARKER, $merged);
        $this->assertStringNotContainsString('Alte Frage?', $merged);
    }

    /**
     * Memories written by the older prompt use a bare [NUTZER] block. A merge
     * must not silently drop them.
     */
    public function test_legacy_nutzer_blocks_survive_a_merge(): void
    {
        $existing = "[NUTZER]\nMag knappe Antworten.\n[STAND]\nAlt.";
        $incoming = "[E27]\nNeu.\n[STAND]\nNeu.";

        $merged = $this->merger->merge($existing, $incoming);

        $this->assertStringContainsString('[NUTZER]', $merged);
        $this->assertStringContainsString('Mag knappe Antworten.', $merged);
    }

    /**
     * An update the formatter cannot section up is kept whole rather than
     * discarded — losing what the persona just wrote would be worse.
     */
    public function test_unstructured_update_is_kept_verbatim(): void
    {
        $merged = $this->merger->merge("[E27]\nx\n[STAND]\ny", 'Freitext ohne Marker.');

        $this->assertSame('Freitext ohne Marker.', $merged);
    }
}
