<?php

namespace App\Providers;

use App\Discussion\Pipelines\PipelineRegistry;
use App\Services\Text\MarkdownParser;
use Illuminate\Support\ServiceProvider;
use Parsedown;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MarkdownParser::class, function () {
            return new MarkdownParser(new Parsedown);
        });

        $this->app->singleton(PipelineRegistry::class, fn () => new PipelineRegistry(
            app_path('Discussion/Pipelines'),
            'App\\Discussion\\Pipelines',
        ));
    }

    public function boot(): void {}
}
