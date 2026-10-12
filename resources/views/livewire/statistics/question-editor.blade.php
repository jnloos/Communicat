@use('App\Discussion\Values\ResultForm')
@use('App\Livewire\Statistics\GroupColor')

<div class="space-y-6">
    {{-- The question's name heads it, with the autosave state and the page's actions. --}}
    <div class="flex items-center gap-3">
        <flux:heading size="xl" level="2" class="min-w-0 truncate">{{ $name }}</flux:heading>

        <flux:text size="sm" class="flex shrink-0 items-center gap-1" wire:loading.remove>
            <flux:icon.check variant="micro" />{{ __('questions.autosave.saved') }}
        </flux:text>
        <flux:text size="sm" class="shrink-0" wire:loading>{{ __('questions.autosave.saving') }}</flux:text>

        <flux:dropdown position="bottom" align="end" class="ms-auto">
            <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" :aria-label="__('common.actions.more')" />
            <flux:menu>
                <flux:menu.item icon="pencil-square" wire:click="$parent.startRenaming({{ $comparisonId }})">{{ __('questions.actions.rename') }}</flux:menu.item>
                <flux:menu.item icon="document-duplicate" wire:click="$parent.duplicate({{ $comparisonId }})">{{ __('questions.actions.duplicate') }}</flux:menu.item>
                <flux:menu.item icon="rectangle-stack" wire:click="$parent.saveAsTemplate({{ $comparisonId }})">{{ __('questions.actions.save_as_template') }}</flux:menu.item>
                <flux:menu.separator />
                <flux:menu.item variant="danger" icon="trash" wire:click="$parent.confirmDelete({{ $comparisonId }})">{{ __('questions.actions.delete') }}</flux:menu.item>
            </flux:menu>
        </flux:dropdown>
    </div>

    @if ($droppedTagValues > 0)
        <flux:callout variant="warning" icon="exclamation-triangle" :text="trans_choice('questions.result.dropped_tags', $droppedTagValues, ['count' => $droppedTagValues])" />
    @endif

    {{-- The question as one sentence. Each chip opens the controls of its part. --}}
    <div class="rounded-lg border border-zinc-200 bg-white p-4 text-base leading-9 dark:border-white/10 dark:bg-white/5">
        <x-statistics.chip :label="$parts['metric']">
            <flux:radio.group wire:model.live="question.metric" :label="__('questions.controls.metric')">
                @foreach ($metrics as $metric)
                    <flux:radio :value="$metric->value" :label="$metric->label()" />
                @endforeach
            </flux:radio.group>
        </x-statistics.chip>

        @if ($parts['people'] !== null)
            <span class="text-zinc-500 dark:text-zinc-400">{{ __('questions.sentence.of') }}</span>
            <x-statistics.chip :label="$parts['people']" :muted="$current->people->isEveryone()">
                @foreach ($personaCategories as $category)
                    <x-statistics.source-label :text="$category->label()" :source="$personaSource" />
                    <flux:checkbox.group variant="pills" wire:key="people-{{ $category->id }}"
                        wire:model.live="question.people.tags.{{ $category->id }}" class="gap-1.5!">
                        @foreach ($category->values as $value)
                            <flux:checkbox variant="pills" :value="(string) $value->id" :label="$value->name" />
                        @endforeach
                    </flux:checkbox.group>
                @endforeach
                <flux:select variant="listbox" searchable wire:model.live="question.people.expert_name"
                    :label="__('questions.controls.expert_name')" :placeholder="__('questions.controls.any_expert')">
                    <flux:select.option value="">{{ __('questions.controls.any_expert') }}</flux:select.option>
                    @foreach ($expertNames as $expertName)
                        <flux:select.option :value="$expertName">{{ $expertName }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:text size="sm">{{ __('questions.controls.people_hint') }}</flux:text>
            </x-statistics.chip>
        @endif

        @if ($parts['aggregate'] !== null)
            <x-statistics.chip :label="$parts['aggregate']" :muted="true">
                <flux:radio.group wire:model.live="question.aggregate" :label="__('questions.controls.aggregate')">
                    @foreach ($aggregates as $aggregate)
                        <flux:radio :value="$aggregate->value" :label="$aggregate->label()" :description="__('questions.aggregate_hints.'.$aggregate->value)" />
                    @endforeach
                </flux:radio.group>
            </x-statistics.chip>
        @endif

        <span class="text-zinc-500 dark:text-zinc-400">{{ __('questions.sentence.in') }}</span>
        <x-statistics.chip :label="$parts['projects']" :muted="$current->projects->isEmpty()">
            @foreach ($projectCategories as $category)
                <x-statistics.source-label :text="$category->label()" :source="$projectSource" />
                <flux:checkbox.group variant="pills" wire:key="projects-{{ $category->id }}"
                    wire:model.live="question.projects.tags.{{ $category->id }}" class="gap-1.5!">
                    @foreach ($category->values as $value)
                        <flux:checkbox variant="pills" :value="(string) $value->id" :label="$value->name" />
                    @endforeach
                </flux:checkbox.group>
            @endforeach
            <x-statistics.source-label :text="__('questions.controls.pipelines')" :source="$runSource" />
            <flux:checkbox.group variant="pills" wire:model.live="question.projects.pipelines" class="gap-1.5!">
                @foreach ($pipelines as $pipelineName => $label)
                    <flux:checkbox variant="pills" :value="$pipelineName" :label="$label" />
                @endforeach
            </flux:checkbox.group>
            <x-statistics.source-label :text="__('questions.controls.models')" :source="$runSource" />
            <flux:checkbox.group variant="pills" wire:model.live="question.projects.models" class="gap-1.5!">
                @foreach ($models as $key => $label)
                    <flux:checkbox variant="pills" :value="$key" :label="$label" />
                @endforeach
            </flux:checkbox.group>
        </x-statistics.chip>

        <span class="text-zinc-500 dark:text-zinc-400">{{ __('questions.sentence.over') }}</span>
        <x-statistics.chip :label="$parts['turns']" :muted="$current->turns->isWholeRun() && ! $result->cappedByShortestRun">
            <div class="grid grid-cols-2 gap-3">
                <flux:input type="number" min="1" wire:model.live.debounce.500ms="question.turns.from" :label="__('questions.controls.turn_from')" />
                <flux:input type="number" min="1" wire:model.live.debounce.500ms="question.turns.to" :label="__('questions.controls.turn_to')" />
            </div>
            @if ($result->shortestRun !== null)
                <flux:text size="sm">{{ trans_choice('questions.controls.turns_hint', $result->shortestRun, ['count' => $result->shortestRun]) }}</flux:text>
            @endif
        </x-statistics.chip>

        <br class="max-sm:hidden">

        <span class="text-zinc-500 dark:text-zinc-400">{{ __('questions.sentence.compared_by') }}</span>
        <x-statistics.chip :label="$parts['compare_by'] ?? __('questions.sentence.nothing')" :source="$parts['compare_by_source']" :muted="$parts['compare_by'] === null">
            <flux:radio.group wire:model.live="question.compare_by" :label="__('questions.controls.compare_by')">
                <flux:radio value="" :label="__('questions.controls.none')" />
                @foreach ($compareOptions as $key => $option)
                    <x-statistics.source-radio :value="$key" :text="$option['label']" :source="$option['source']" wire:key="compare-{{ $key }}" />
                @endforeach
            </flux:radio.group>
            @if ($current->metric->isRunLevel())
                <flux:text size="sm">{{ __('questions.controls.gini_hint') }}</flux:text>
            @endif
        </x-statistics.chip>

        <span class="text-zinc-500 dark:text-zinc-400">{{ __('questions.sentence.split_by') }}</span>
        <x-statistics.chip :label="$parts['split_by'] ?? __('questions.sentence.nothing')" :source="$parts['split_by_source']" :muted="$parts['split_by'] === null">
            <flux:radio.group wire:model.live="question.split_by" :label="__('questions.controls.split_by')">
                <flux:radio value="" :label="__('questions.controls.none')" />
                @foreach ($splitOptions as $key => $option)
                    @unless ($key === ($question['compare_by'] ?? ''))
                        <x-statistics.source-radio :value="$key" :text="$option['label']" :source="$option['source']" wire:key="split-{{ $key }}" />
                    @endunless
                @endforeach
            </flux:radio.group>
        </x-statistics.chip>
    </div>

    {{-- How many runs answer, and in which form. --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:text size="sm">
            {{ trans_choice('questions.result.runs', $result->runs, ['count' => $result->runs]) }}
            @if ($result->excluded > 0)
                · {{ trans_choice('questions.result.excluded', $result->excluded, ['count' => $result->excluded]) }}
            @endif
        </flux:text>

        <div class="flex items-center gap-2">
            @if ($current->form === ResultForm::Trajectory)
                <flux:radio.group wire:model.live="question.curve" variant="segmented" size="sm">
                    <flux:radio value="cumulative" :label="__('questions.curves.cumulative')" />
                    <flux:radio value="rolling" :label="__('questions.curves.rolling')" />
                </flux:radio.group>
            @endif

            <flux:radio.group wire:model.live="question.form" variant="segmented" size="sm">
                @foreach ($forms as $form)
                    @if ($current->supports($form))
                        <flux:radio :value="$form->value" :label="$form->label()" />
                    @else
                        <flux:radio :value="$form->value" :label="$form->label()" disabled :title="__('questions.forms.unsupported.'.$form->value)" />
                    @endif
                @endforeach
            </flux:radio.group>
        </div>
    </div>

    @if ($result->isEmpty())
        <x-empty-state icon="chart-bar">{{ __('questions.result.empty') }}</x-empty-state>
    @else
        {{-- Legend: one colour per group, the same in every panel. --}}
        @if (count($result->groups) > 1)
            <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                @foreach ($result->groups as $key => $label)
                    <span class="flex items-center gap-1.5" wire:key="legend-{{ $key }}">
                        <span class="size-2.5 rounded-full" style="background-color: var(--color-{{ GroupColor::at($loop->index) }})"></span>{{ $label }}
                    </span>
                @endforeach
            </div>
        @endif

        @switch($current->form)
            @case(ResultForm::Composition)
                <x-statistics.composition-bars :result="$result" :metric="$current->metric" />
                @break
            @case(ResultForm::Trajectory)
                <x-statistics.curve-panels :result="$result" />
                @if ($current->curve === 'rolling')
                    <flux:text size="sm">{{ trans_choice('statistics.trajectory.rolling_hint', $rollingWindow, ['count' => $rollingWindow]) }}</flux:text>
                @endif
                @break
            @default
                <x-statistics.point-panels :result="$result" :metric="$current->metric" />
                @if ($current->metric->isShare())
                    <flux:text size="sm">{{ __('questions.result.fair_share') }}</flux:text>
                @endif
        @endswitch

        <x-statistics.run-table :result="$result" :metric="$current->metric" />
    @endif
</div>
