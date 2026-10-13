<?php

namespace App\Services\Transfer;

use RuntimeException;

/** One entry of a bundle file does not have the shape the import reads; it fails alone. */
final class UnreadableEntry extends RuntimeException {}
