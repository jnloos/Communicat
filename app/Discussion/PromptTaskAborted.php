<?php

namespace App\Discussion;

/** A ParallelPrompts task failed with an error that ends the run (a rejected key, model drift). */
final class PromptTaskAborted extends PromptTaskFailed implements AbortsRun {}
