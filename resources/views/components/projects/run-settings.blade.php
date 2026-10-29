@props([
    'pipelines', {{-- array<string, string>: pipeline class name => label --}}
    'providers', {{-- array<string, string>: provider key => label --}}
    'models', {{-- array<string, string>: model key => label, of the chosen provider only --}}
    'definitions' => [], {{-- array<int, string>: attribute id => name; none = nothing to reveal --}}
    'locked' => false, {{-- the run has started: the attribute visibility is fixed --}}
    'withHelp' => false, {{-- the field descriptions, shown where a project is made, not where it is edited --}}
    'thresholdSuggestions' => [20, 30, 40, 60],
    'oldestSuggestions' => [10, 20, 30],
])

{{-- The run's settings in the project forms (create and edit), inside their
     accordion: pipeline, provider and model, then the two summarization
     numbers. Bound to the parent's ProjectForm, so both forms validate and
     store them alike. The numbers are free text with suggestions. --}}
@php
    $help = fn (string $key) => $withHelp ? __("projects.fields.{$key}_help") : null;
    // What each pipeline does, by class name; one without a text shows none.
    $pipelineInfo = collect($pipelines)->mapWithKeys(fn ($label, $name) => [
        $name => trans()->has("pipeline_info.{$name}") ? __("pipeline_info.{$name}") : '',
    ]);
@endphp

<flux:accordion.item transition="true">
    <flux:accordion.heading>
        <div class="flex gap-2 {{ $errors->hasAny(['form.pipeline', 'form.provider', 'form.model']) ? 'text-red-500 dark:text-red-400' : '' }}">
            <flux:icon.cpu-chip class="size-5"/> {{ __('projects.groups.ai_pipeline') }}
        </div>
    </flux:accordion.heading>
    <flux:accordion.content class="my-4 space-y-4">
        <flux:select wire:model.defer="form.pipeline" :label="__('projects.fields.pipeline')">
            @foreach ($pipelines as $name => $label)
                <option value="{{ $name }}">{{ $label }}</option>
            @endforeach
        </flux:select>

        {{-- The text of the pipeline chosen right now. $wire.form.pipeline changes in
             the browser at once, although the select is deferred. Same callout as
             the info boxes of Settings → Tags and Attributes. The text has a fixed
             minimum height (the longest runs to four lines), so Provider and Model
             below do not jump when the pipeline changes; aria-live announces it. --}}
        <div x-data="{ info: @js($pipelineInfo) }" x-show="info[$wire.form.pipeline]" x-cloak>
            <flux:callout icon="information-circle">
                <flux:callout.text x-text="info[$wire.form.pipeline]" aria-live="polite" class="min-h-20" data-pipeline-info></flux:callout.text>
            </flux:callout>
        </div>

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

<flux:accordion.item transition="true" wire:model="form.attributesOpen">
    <flux:accordion.heading>
        <div class="flex gap-2 {{ $errors->has('form.attributeVisibility') || $errors->has('form.attributeVisibility.*') ? 'text-red-500 dark:text-red-400' : '' }}">
            <flux:icon.identification class="size-5"/> {{ __('projects.groups.attributes') }}
        </div>
    </flux:accordion.heading>
    <flux:accordion.content class="my-4 space-y-4">
        @if ($definitions === [])
            {{-- Nothing to reveal: the levels stay as they are. --}}
            <flux:text data-attributes-none>
                @php
                    $settingsLabel = e(__('projects.attributes.settings_link'));
                    $link = auth()->user()?->is_admin
                        ? '<a href="'.e(route('settings.attributes')).'" wire:navigate class="underline">'.$settingsLabel.'</a>'
                        : $settingsLabel;
                @endphp
                {!! str_replace('%LINK%', $link, e(__('projects.attributes.none_defined', ['link' => '%LINK%']))) !!}
            </flux:text>
        @else
            <flux:text size="sm" data-attributes-intro>{{ __('projects.attributes.intro') }}</flux:text>

            @if ($locked)
                <flux:text size="sm">{{ __('projects.fields.attribute_visibility_locked') }}</flux:text>
            @endif

            {{-- Same full-width select with a label above as pipeline, provider and model. --}}
            @foreach ($definitions as $id => $name)
                <flux:select
                    wire:model.defer="form.attributeVisibility.{{ $id }}"
                    :label="__('projects.attributes.shown_to', ['name' => $name])"
                    :disabled="$locked"
                    :title="$locked ? __('projects.fields.attribute_visibility_locked') : null"
                    data-attribute-row="{{ $id }}"
                >
                    @foreach (\App\Discussion\Values\AttributeVisibility::cases() as $case)
                        <option value="{{ $case->value }}">{{ __('projects.attribute_visibility.'.$case->value) }}</option>
                    @endforeach
                </flux:select>
            @endforeach
        @endif
    </flux:accordion.content>
</flux:accordion.item>
