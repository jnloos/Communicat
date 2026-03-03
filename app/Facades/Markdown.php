<?php

namespace App\Facades;

use App\Services\MarkdownParser;
use Illuminate\Support\Facades\Facade;

class Markdown extends Facade
{
    protected static function getFacadeAccessor(): string {
        return MarkdownParser::class;
    }
}
