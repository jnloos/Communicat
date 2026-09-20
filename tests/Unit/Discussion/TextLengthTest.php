<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Support\TextLength;
use PHPUnit\Framework\TestCase;

class TextLengthTest extends TestCase
{
    public function test_counts_words_separated_by_any_whitespace(): void
    {
        $this->assertSame(3, TextLength::words('Alice antwortet kurz.'));
        $this->assertSame(4, TextLength::words("  Zwei  Wörter\nund\tmehr "));
        $this->assertSame(0, TextLength::words('   '));
    }

    public function test_counts_characters_not_bytes(): void
    {
        $this->assertSame(21, TextLength::chars('Alice antwortet kurz.'));
        $this->assertSame(5, TextLength::chars('Größe'));
    }
}
