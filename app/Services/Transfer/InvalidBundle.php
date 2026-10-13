<?php

namespace App\Services\Transfer;

use RuntimeException;

/** A file that is not a bundle this app can read; thrown before anything is written. */
final class InvalidBundle extends RuntimeException {}
