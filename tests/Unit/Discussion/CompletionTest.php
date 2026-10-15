<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Values\Completion;
use PHPUnit\Framework\TestCase;

class CompletionTest extends TestCase
{
    public function test_a_string_field_is_read_trimmed(): void
    {
        $this->assertSame('E7', $this->completion(['speaker' => "  E7\n"])->field('speaker'));
    }

    /** A schema is a request on some providers, not a guarantee, so a field may arrive in any type or not at all. */
    public function test_a_missing_or_non_string_field_reads_as_empty(): void
    {
        $completion = $this->completion(['list' => ['E7'], 'number' => 3, 'nothing' => null]);

        foreach (['list', 'number', 'nothing', 'absent'] as $key) {
            $this->assertSame('', $completion->field($key), $key);
        }
    }

    /** @param  array<string, mixed>  $structured */
    private function completion(array $structured): Completion
    {
        return new Completion('{}', '', 0, 0, null, $structured);
    }
}
