<?php

namespace App\Providers;

use App\Services\MarkdownParser;
use Illuminate\Support\ServiceProvider;
use Parsedown;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MarkdownParser::class, function () {
            return new MarkdownParser(new Parsedown());
        });
    }

    public function boot(): void {}
}
