<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A key that stays the same on every instance: the numeric id differs between
 * databases, the uuid travels with an export and lets an import recognise a
 * row it already has. Not the primary key -- nothing else changes. Not
 * mass assignable: only this hook and an import (forceFill) set it.
 */
trait HasPublicUuid
{
    public static function bootHasPublicUuid(): void
    {
        static::creating(function (Model $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }
}
