<?php

namespace App\Providers;

use App\Discussion\Logging\RecordPromptLog;
use App\Discussion\Pipelines\PipelineRegistry;
use App\Services\Text\MarkdownParser;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Events\StepFailed;
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

        $this->app->singleton(RecordPromptLog::class);
    }

    public function boot(): void
    {
        Event::listen(PromptingAgent::class, [RecordPromptLog::class, 'whenPrompting']);
        Event::listen(StepCompleted::class, [RecordPromptLog::class, 'whenCompleted']);
        Event::listen(StepFailed::class, [RecordPromptLog::class, 'whenStepFailed']);
        Event::listen(AgentFailed::class, [RecordPromptLog::class, 'whenAgentFailed']);
    }
}
