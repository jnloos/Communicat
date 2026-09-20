<?php

namespace App\Discussion;

use LogicException;

/** A stage read a payload field that no earlier stage has written: the pipeline is mis-ordered. */
class MissingPayloadSlot extends LogicException {}
