<?php

namespace App\Facades;

use App\Services\Dependencies\SpecificationService;
use App\Services\PythonVenv;
use Illuminate\Support\Facades\Facade;

class Specification extends Facade
{
    protected static function getFacadeAccessor(): string {
        return SpecificationService::class;
    }
}
