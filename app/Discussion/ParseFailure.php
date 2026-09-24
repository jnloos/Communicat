<?php

namespace App\Discussion;

use RuntimeException;

/**
 * A stage could not read what it needs out of a model answer — a missing marker,
 * an empty contribution. Transport and API errors come from the SDK
 * (Laravel\Ai\Exceptions\*) and are not wrapped.
 */
class ParseFailure extends RuntimeException {}
