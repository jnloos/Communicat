<?php

namespace App\Observers;

use App\Models\Contributor;
use App\Models\User;

class UserObserver
{
    public function created(User $user): void {
        Contributor::firstOrCreate(['user_id' => $user->id]);
    }
}
