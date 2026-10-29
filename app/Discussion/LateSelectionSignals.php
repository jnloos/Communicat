<?php

namespace App\Discussion;

use LogicException;

/**
 * A stage tried to add selection signals after SelectSpeaker had already built the
 * selection. They would never reach job_logs.selection, so the mis-ordered
 * pipeline fails loudly instead of losing measurement data.
 */
class LateSelectionSignals extends LogicException {}
