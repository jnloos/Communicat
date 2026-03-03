<?php

namespace App\Observers;

use App\Models\Contributor;
use App\Models\Expert;

class ExpertObserver
{
    public function created(Expert $expert): void {
        Contributor::firstOrCreate(['expert_id' => $expert->id]);
    }
}
