@props([
    'pipelines', {{-- array<string, string>: pipeline class name => label --}}
    'providers', {{-- array<string, string>: provider key => label --}}
    'models', {{-- array<string, string>: model key => label, of the chosen provider only --}}
    'withHelp' => false, {{-- the field descriptions, shown where a project is made, not where it is edited --}}
    'thresholdSuggestions' => [20, 30, 40, 60],
    'oldestSuggestions' => [10, 20, 30],
])

{{-- The run's settings in the project forms (create and edit), inside their
     accordion: pipeline, provider and model, then the two summarization
     numbers. Bound to the parent's ProjectForm, so both forms validate and
     store them alike. The numbers are free text with suggestions. --}}
@php($help = fn (string $key) => $withHelp ? __("projects.fields.{$key}_help") : null)

<flux:accordion.item transition="true">
    <flux:accordion.heading>
        <div class="flex gap-2 {{ $errors->hasAny(['form.pipeline', 'form.provider', 'form.model']) ? 'text-red-500 dark:text-red-400' : '' }}">
            <flux:icon.cpu-chip class="size-5"/> {{ __('projects.groups.ai_pipeline') }}
        </div>
    </flux:accordion.heading>
    <flux:accordion.content class="my-4 space-y-4">
        <flux:select wire:model.defer="form.pipeline" :label="__('projects.fields.pipeline')" :description="$help('pipeline')">
            @foreach ($pipelines as $name => $label)
                <option value="{{ $name }}">{{ $label }}</option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="form.provider" :label="__('projects.fields.provider')" :description="$help('provider')">
            @foreach ($providers as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </flux:select>

        <flux:select wire:model.defer="form.model" :label="__('projects.fields.model')" :description="$help('model')">
            @foreach ($models as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </flux:select>
    </flux:accordion.content>
</flux:accordion.item>

<flux:accordion.item transition="true">
    <flux:accordion.heading>
        <div class="flex gap-2 {{ $errors->hasAny(['form.summarizeThreshold', 'form.summarizeOldest']) ? 'text-red-500 dark:text-red-400' : '' }}">
            <flux:icon.arrows-pointing-in class="size-5"/> {{ __('projects.groups.reduction') }}
        </div>
    </flux:accordion.heading>
    <flux:accordion.content class="my-4 space-y-4">
        <flux:autocomplete wire:model.defer="form.summarizeThreshold" :label="__('projects.fields.summarize_threshold')" :description="$help('summarize_threshold')">
            @foreach ($thresholdSuggestions as $suggestion)
                <flux:autocomplete.item>{{ $suggestion }}</flux:autocomplete.item>
            @endforeach
        </flux:autocomplete>

        <flux:autocomplete wire:model.defer="form.summarizeOldest" :label="__('projects.fields.summarize_oldest')" :description="$help('summarize_oldest')">
            @foreach ($oldestSuggestions as $suggestion)
                <flux:autocomplete.item>{{ $suggestion }}</flux:autocomplete.item>
            @endforeach
        </flux:autocomplete>
    </flux:accordion.content>
</flux:accordion.item>
