<?php

namespace App\Facades;

use App\Services\PythonVenv;
use Illuminate\Support\Facades\Facade;

class Python extends Facade
{
    protected static function getFacadeAccessor(): string {
        return PythonVenv::class;
    }
}
