@use('App\Discussion\Values\ResultForm')
@use('App\Livewire\Statistics\GroupColor')

<div class="space-y-6">
    {{-- The question's title heads it, with the autosave state and the page's actions. --}}
    <div class="flex items-start gap-3">
        <flux:heading size="xl" level="2" @class(['min-w-0 flex-1 break-words', 'italic text-zinc-400! dark:text-zinc-500!' => $title === null])>{{ $title ?? __('questions.untitled') }}</flux:heading>

        @unless ($readOnly)
            <flux:text size="sm" class="flex shrink-0 items-center gap-1 pt-1.5" wire:loading.remove>
                <flux:icon.check variant="micro" />{{ __('questions.autosave.saved') }}
            </flux:text>
            <flux:text size="sm" class="shrink-0 pt-1.5" wire:loading>{{ __('questions.autosave.saving') }}</flux:text>
        @endunless

        @if ($mayManage)
            <flux:dropdown position="bottom" align="end" class="shrink-0">
                <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" :aria-label="__('common.actions.more')" />
                <flux:menu>
                    <flux:menu.item icon="pencil-square" wire:click="$parent.startRenaming({{ $comparisonId }})">{{ __('questions.actions.rename') }}</flux:menu.item>
                    @unless ($readOnly)
                        <flux:menu.item icon="document-duplicate" wire:click="$parent.duplicate({{ $comparisonId }})">{{ __('questions.actions.duplicate') }}</flux:menu.item>
                        <flux:menu.item icon="rectangle-stack" wire:click="$parent.saveAsTemplate({{ $comparisonId }})">{{ __('questions.actions.save_as_fix_question') }}</flux:menu.item>
                        <flux:menu.separator />
                    @endunless
                    <flux:menu.item variant="danger" icon="trash" wire:click="$parent.confirmDelete({{ $comparisonId }})">{{ __('questions.actions.delete') }}</flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        @endif
    </div>

    {{-- The researcher's own words: what the question asks and what followed from it. --}}
    @if ($readOnly)
        @if (filled($notes))
            <flux:text class="whitespace-pre-line">{{ $notes }}</flux:text>
        @endif
    @else
        <flux:textarea wire:model.live.debounce.750ms="notes" rows="3" resize="vertical"
            :label="__('questions.notes.label')" :placeholder="__('questions.notes.placeholder')" />
    @endif

    {{-- A template is only to look at; the way on is a copy. --}}
    @if ($readOnly)
        <flux:callout icon="eye" inline :text="__('questions.fix.view_only_notice')">
            <x-slot:actions>
                <flux:button size="sm" variant="primary" icon="document-duplicate"
                    wire:click="$parent.copyFixQuestion({{ $comparisonId }}, $wire.question.form, $wire.question.curve)">
                    {{ __('questions.actions.copy_to_adjust') }}
                </flux:button>
            </x-slot:actions>
        </flux:callout>
    @endif

    @if ($droppedTagValues > 0)
        <flux:callout variant="warning" icon="exclamation-triangle" :text="trans_choice('questions.result.dropped_tags', $droppedTagValues, ['count' => $droppedTagValues])" />
    @endif

    {{-- The question as one sentence. Each part is a button that opens a Flux menu; a template shows them disabled. --}}
    <flux:card size="sm" class="text-base">
        {{-- Each connector word travels with the trigger it introduces and breaks only as a whole unit. --}}
        <div class="flex flex-wrap items-center gap-x-1.5 gap-y-2">
        <span class="inline-flex max-w-full shrink-0 items-center gap-1.5 whitespace-nowrap [&>*]:min-w-0">
        <x-statistics.chip :read-only="$readOnly" :label="$parts['metric']">
            <flux:menu>
                <flux:menu.radio.group wire:model.live="question.metric">
                    @foreach ($metrics as $metric)
                        <flux:menu.radio :value="$metric->value">{{ $metric->label() }}</flux:menu.radio>
                    @endforeach
                </flux:menu.radio.group>
            </flux:menu>
        </x-statistics.chip>
        </span>

        @if ($parts['people'] !== null)
        <span class="inline-flex max-w-full shrink-0 items-center gap-1.5 whitespace-nowrap [&>*]:min-w-0">
            <span class="text-zinc-500 dark:text-zinc-400">{{ __('questions.sentence.of') }}</span>
            <x-statistics.chip :read-only="$readOnly" :label="$parts['people']" :muted="$current->people->isEveryone()">
                <flux:menu keep-open>
                    @foreach ($personaCategories as $category)
                        <flux:menu.group :heading="$personaSource->named($category->label())" wire:key="people-{{ $category->id }}">
                            <flux:menu.checkbox.group wire:model.live="question.people.tags.{{ $category->id }}">
                                @foreach ($category->values as $value)
                                    <flux:menu.checkbox :value="(string) $value->id">{{ $value->name }}</flux:menu.checkbox>
                                @endforeach
                            </flux:menu.checkbox.group>
                        </flux:menu.group>
                    @endforeach

                    <flux:menu.submenu :heading="__('questions.controls.expert_name')" keep-open>
                        <flux:menu.radio.group wire:model.live="question.people.expert_name">
                            <flux:menu.radio value="">{{ __('questions.controls.any_expert') }}</flux:menu.radio>
                            @foreach ($expertNames as $expertName)
                                <flux:menu.radio :value="$expertName">{{ $expertName }}</flux:menu.radio>
                            @endforeach
                        </flux:menu.radio.group>
                    </flux:menu.submenu>
                </flux:menu>
            </x-statistics.chip>
        </span>
        @endif

        @if ($parts['aggregate'] !== null)
        <span class="inline-flex max-w-full shrink-0 items-center gap-1.5 whitespace-nowrap [&>*]:min-w-0">
            <x-statistics.chip :read-only="$readOnly" :label="$parts['aggregate']" :muted="true">
                <flux:menu>
                    <flux:menu.radio.group wire:model.live="question.aggregate">
                        @foreach ($aggregates as $aggregate)
                            <flux:menu.radio :value="$aggregate->value" :title="__('questions.aggregate_hints.'.$aggregate->value)">{{ $aggregate->label() }}</flux:menu.radio>
                        @endforeach
                    </flux:menu.radio.group>
                </flux:menu>
            </x-statistics.chip>
        </span>
        @endif

        <span class="inline-flex max-w-full shrink-0 items-center gap-1.5 whitespace-nowrap [&>*]:min-w-0">
        <span class="text-zinc-500 dark:text-zinc-400">{{ __('questions.sentence.in') }}</span>
        <x-statistics.chip :read-only="$readOnly" :label="$parts['projects']" :muted="$current->projects->isEmpty()">
            <flux:menu keep-open>
                @foreach ($projectCategories as $category)
                    <flux:menu.group :heading="$projectSource->named($category->label())" wire:key="projects-{{ $category->id }}">
                        <flux:menu.checkbox.group wire:model.live="question.projects.tags.{{ $category->id }}">
                            @foreach ($category->values as $value)
                                <flux:menu.checkbox :value="(string) $value->id">{{ $value->name }}</flux:menu.checkbox>
                            @endforeach
                        </flux:menu.checkbox.group>
                    </flux:menu.group>
                @endforeach

                <flux:menu.group :heading="$runSource->named(__('questions.controls.pipelines'))">
                    <flux:menu.checkbox.group wire:model.live="question.projects.pipelines">
                        @foreach ($pipelines as $pipelineName => $label)
                            <flux:menu.checkbox :value="$pipelineName">{{ $label }}</flux:menu.checkbox>
                        @endforeach
                    </flux:menu.checkbox.group>
                </flux:menu.group>

                <flux:menu.group :heading="$runSource->named(__('questions.controls.models'))">
                    <flux:menu.checkbox.group wire:model.live="question.projects.models">
                        @foreach ($models as $key => $label)
                            <flux:menu.checkbox :value="$key">{{ $label }}</flux:menu.checkbox>
                        @endforeach
                    </flux:menu.checkbox.group>
                </flux:menu.group>

                @if ($groups->isNotEmpty())
                    <flux:menu.group :heading="$groupSource->named(__('questions.controls.groups'))">
                        <flux:menu.checkbox.group wire:model.live="question.projects.groups">
                            @foreach ($groups as $groupId => $groupName)
                                <flux:menu.checkbox :value="(string) $groupId" wire:key="group-{{ $groupId }}">{{ $groupName }}</flux:menu.checkbox>
                            @endforeach
                        </flux:menu.checkbox.group>
                    </flux:menu.group>
                @endif
            </flux:menu>
        </x-statistics.chip>
        </span>

        {{-- Menus cannot hold input fields, so the window is the one part that opens a popover. --}}
        <span class="inline-flex max-w-full shrink-0 items-center gap-1.5 whitespace-nowrap [&>*]:min-w-0">
        <x-statistics.chip :read-only="$readOnly" :label="$parts['turns']" :muted="$current->turns->isWholeRun() && ! $result->cappedByShortestRun">
            <flux:popover class="w-80 max-w-[calc(100vw-2rem)] space-y-4">
                <div class="grid grid-cols-2 gap-3">
                    {{-- Empty fields are not "nothing": the placeholders show the window the answer is read over. --}}
                    <flux:input type="number" min="1" wire:model.live.debounce.500ms="question.turns.from" :label="__('questions.controls.turn_from')" :placeholder="$result->turns->from" />
                    <flux:input type="number" min="1" wire:model.live.debounce.500ms="question.turns.to" :label="__('questions.controls.turn_to')" :placeholder="$result->turns->to" />
                </div>
                @if ($result->shortestRun !== null)
                    @php($run = e($result->shortestRunTitle))
                    <flux:text size="sm">{!! trans_choice('questions.controls.turns_hint', $result->shortestRun, [
                        'count' => $result->shortestRun,
                        'run' => '<a href="'.route('project.show', $result->shortestRunId).'" class="underline">'.$run.'</a>',
                    ]) !!}</flux:text>
                @endif
            </flux:popover>
        </x-statistics.chip>
        </span>

        <span class="inline-flex max-w-full shrink-0 items-center gap-1.5 whitespace-nowrap [&>*]:min-w-0">
        <span class="text-zinc-500 dark:text-zinc-400">{{ __('questions.sentence.compared_by') }}</span>
        <x-statistics.chip :read-only="$readOnly" :label="$parts['compare_by'] ?? __('questions.sentence.nothing')" :muted="$parts['compare_by'] === null">
            <flux:menu>
                <flux:menu.radio.group wire:model.live="question.compare_by">
                    <flux:menu.radio value="">{{ __('questions.controls.none') }}</flux:menu.radio>
                    @foreach ($compareOptions as $key => $label)
                        <flux:menu.radio :value="$key" wire:key="compare-{{ $key }}">{{ $label }}</flux:menu.radio>
                    @endforeach
                </flux:menu.radio.group>
                @if ($current->metric->isRunLevel())
                    <flux:menu.separator />
                    <flux:text size="sm" class="px-2 py-1">{{ __('questions.controls.gini_hint') }}</flux:text>
                @endif
            </flux:menu>
        </x-statistics.chip>
        </span>

        <span class="inline-flex max-w-full shrink-0 items-center gap-1.5 whitespace-nowrap [&>*]:min-w-0">
        <span class="text-zinc-500 dark:text-zinc-400">{{ __('questions.sentence.split_by') }}</span>
        <x-statistics.chip :read-only="$readOnly" :label="$parts['split_by'] ?? __('questions.sentence.nothing')" :muted="$parts['split_by'] === null">
            <flux:menu>
                <flux:menu.radio.group wire:model.live="question.split_by">
                    <flux:menu.radio value="">{{ __('questions.controls.none') }}</flux:menu.radio>
                    @foreach ($splitOptions as $key => $label)
                        @unless ($key === ($question['compare_by'] ?? ''))
                            <flux:menu.radio :value="$key" wire:key="split-{{ $key }}">{{ $label }}</flux:menu.radio>
                        @endunless
                    @endforeach
                </flux:menu.radio.group>
            </flux:menu>
        </x-statistics.chip>
        </span>
        </div>
    </flux:card>

    {{-- How many runs answer, and in which form. --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:text size="sm">
            {{ trans_choice('questions.result.runs', $result->runs, ['count' => $result->runs]) }}@if ($result->excluded > 0), {{ trans_choice('questions.result.excluded', $result->excluded, ['count' => $result->excluded]) }}
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
