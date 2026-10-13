<?php

namespace App\Console\Commands\Concerns;

use App\Models\User;

/** The user a bundle command acts for: --user, or the first admin. */
trait ResolvesBundleUser
{
    private function bundleUser(): ?User
    {
        $id = $this->option('user');

        if ($id !== null && $id !== '') {
            $user = User::find($id);
            if ($user === null) {
                $this->error("No user with id {$id}.");
            }

            return $user;
        }

        $user = User::where('is_admin', true)->orderBy('id')->first();
        if ($user === null) {
            $this->error('No admin user; pass --user.');
        }

        return $user;
    }
}
