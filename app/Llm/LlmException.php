<?php

namespace App\Llm;

use RuntimeException;
use Throwable;

class LlmException extends RuntimeException
{
    public const KIND_API = 'api';

    public const KIND_REFUSAL = 'refusal';

    public const KIND_EMPTY = 'empty';

    public const KIND_PARSE = 'parse';

    public function __construct(
        string $message,
        public readonly string $kind = self::KIND_API,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
