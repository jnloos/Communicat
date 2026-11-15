<?php

namespace Tests\Feature\Study\Progress;

use App\Study\Progress\ErrorClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ErrorClassTest extends TestCase
{
    /** @return array<string, array{?string, string}> */
    public static function messages(): array
    {
        return [
            'before the colon' => ['RuntimeException: Project 3 stopped', 'RuntimeException'],
            'namespaced' => ['Laravel\Ai\Exceptions\AiException: empty', 'Laravel\Ai\Exceptions\AiException'],
            'no colon, class first' => ['TypeError the thing went wrong', 'TypeError'],
            'no colon, prose' => ['The worker died with secret text', 'unknown'],
            'prose before a colon' => ['Could not reach host: boom', 'unknown'],
            'empty' => ['', 'unknown'],
            'null' => [null, 'unknown'],
        ];
    }

    #[DataProvider('messages')]
    public function test_it_reduces_a_message_to_its_class(?string $error, string $expected): void
    {
        $this->assertSame($expected, ErrorClass::of($error));
    }
}
