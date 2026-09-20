<?php

namespace App\Discussion\Pipelines;

use Illuminate\Support\Facades\Lang;
use ReflectionClass;

class PipelineRegistry
{
    /** @var array<string, class-string<TurnPipeline>>|null */
    private ?array $pipelines = null;

    public function __construct(
        private readonly string $directory,
        private readonly string $namespace,
    ) {}

    /** @return array<string, class-string<TurnPipeline>> short class name → class */
    public function all(): array
    {
        if ($this->pipelines !== null) {
            return $this->pipelines;
        }

        $found = [];

        foreach (glob($this->directory.'/*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            $class = $this->namespace.'\\'.$name;

            if ($this->isPipeline($class)) {
                $found[$name] = $class;
            }
        }

        ksort($found);

        return $this->pipelines = $found;
    }

    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    public function resolve(string $name): TurnPipeline
    {
        $class = $this->all()[$name]
            ?? throw new UnknownPipeline("Unknown pipeline [{$name}]. Known: ".implode(', ', array_keys($this->all())));

        return app($class);
    }

    public function label(string $name): string
    {
        return Lang::has("pipelines.{$name}") ? __("pipelines.{$name}") : $name;
    }

    /** @return array<string, string> short class name → label, for dropdowns */
    public function options(): array
    {
        $options = [];

        foreach (array_keys($this->all()) as $name) {
            $options[$name] = $this->label($name);
        }

        return $options;
    }

    public function default(): string
    {
        return (string) config('discussion.default_pipeline');
    }

    private function isPipeline(string $class): bool
    {
        return class_exists($class)
            && is_subclass_of($class, TurnPipeline::class)
            && ! (new ReflectionClass($class))->isAbstract();
    }
}
