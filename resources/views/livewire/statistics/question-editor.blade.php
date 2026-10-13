@use('App\Discussion\Values\CurveMode')
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

        <flux:dropdown position="bottom" align="end" class="shrink-0">
            <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" :aria-label="__('common.actions.more')" />
            <flux:menu>
                <x-statistics.export-item :question-id="$questionId" />
                @if ($mayManage)
                    <flux:menu.separator />
                    <flux:menu.item icon="pencil-square" wire:click="$parent.startRenaming({{ $questionId }})">{{ __('questions.actions.rename') }}</flux:menu.item>
                    @unless ($readOnly)
                        <flux:menu.item icon="document-duplicate" wire:click="$parent.duplicate({{ $questionId }})">{{ __('questions.actions.duplicate') }}</flux:menu.item>
                        @if ($mayShare)
                            <flux:menu.item icon="rectangle-stack" wire:click="$parent.saveAsTemplate({{ $questionId }})">{{ __('questions.actions.save_as_template') }}</flux:menu.item>
                        @endif
                    @endunless
                    <flux:menu.separator />
                    <flux:menu.item variant="danger" icon="trash" wire:click="$parent.confirmDelete({{ $questionId }})">{{ __('questions.actions.delete') }}</flux:menu.item>
                @endif
            </flux:menu>
        </flux:dropdown>
    </div>

    {{-- A template is only to look at; the way on is a copy. --}}
    @if ($readOnly)
        <flux:callout icon="eye" inline :text="__('questions.template.view_only_notice')">
            <x-slot:actions>
                <flux:button size="sm" variant="primary" icon="document-duplicate"
                    wire:click="$parent.copyTemplate({{ $questionId }}, $wire.definition.form, $wire.definition.curve)">
                    {{ __('questions.actions.copy_to_adjust') }}
                </flux:button>
            </x-slot:actions>
        </flux:callout>
    @endif

    {{-- The researcher's own words: what the question asks and what followed from it. --}}
    {{-- A template shows the same field, disabled: same place, same label, nothing to save. --}}
    <flux:textarea wire:model.live.debounce.750ms="notes" rows="3" resize="vertical" :disabled="$readOnly"
        :label="__('questions.notes.label')" :placeholder="$readOnly ? '' : __('questions.notes.placeholder')"
        @class(['disabled:opacity-100! disabled:cursor-default' => $readOnly]) />

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
                <flux:menu.radio.group wire:model.live="definition.metric">
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
                            <flux:menu.checkbox.group wire:model.live="definition.people.tags.{{ $category->id }}">
                                @foreach ($category->values as $value)
                                    <flux:menu.checkbox :value="(string) $value->id">{{ $value->name }}</flux:menu.checkbox>
                                @endforeach
                            </flux:menu.checkbox.group>
                        </flux:menu.group>
                    @endforeach

                    {{-- A menu radio group cannot go back to "any" (Flux reads '' as unset), cannot filter and takes one value; a searchable, clearable, multiple listbox does all three. Keys are stopped here so the menu's own type-ahead does not take them from the search field. --}}
                    <flux:menu.separator />
                    <div class="px-2 pb-1" x-on:keydown.stop>
                        <flux:select variant="listbox" size="sm" multiple searchable clearable wire:model.live="definition.people.expert_names" :label="__('questions.controls.expert_names')" :placeholder="__('questions.controls.any_expert')">
                            @foreach ($expertNames as $expertName)
                                <flux:select.option :value="$expertName" wire:key="expert-{{ $expertName }}">{{ $expertName }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                </flux:menu>
            </x-statistics.chip>
        </span>
        @endif

        @if ($parts['aggregate'] !== null)
        <span class="inline-flex max-w-full shrink-0 items-center gap-1.5 whitespace-nowrap [&>*]:min-w-0">
            <x-statistics.chip :read-only="$readOnly" :label="$parts['aggregate']" :muted="true">
                <flux:menu>
                    <flux:menu.radio.group wire:model.live="definition.aggregate">
                        @foreach ($aggregates as $aggregate)
                            <flux:menu.radio :value="$aggregate->value">{{ $aggregate->label() }}</flux:menu.radio>
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
                        <flux:menu.checkbox.group wire:model.live="definition.projects.tags.{{ $category->id }}">
                            @foreach ($category->values as $value)
                                <flux:menu.checkbox :value="(string) $value->id">{{ $value->name }}</flux:menu.checkbox>
                            @endforeach
                        </flux:menu.checkbox.group>
                    </flux:menu.group>
                @endforeach

                <flux:menu.group :heading="$runSource->named(__('questions.controls.pipelines'))">
                    <flux:menu.checkbox.group wire:model.live="definition.projects.pipelines">
                        @foreach ($pipelines as $pipelineName => $label)
                            <flux:menu.checkbox :value="$pipelineName">{{ $label }}</flux:menu.checkbox>
                        @endforeach
                    </flux:menu.checkbox.group>
                </flux:menu.group>

                <flux:menu.group :heading="$runSource->named(__('questions.controls.models'))">
                    <flux:menu.checkbox.group wire:model.live="definition.projects.models">
                        @foreach ($models as $key => $label)
                            <flux:menu.checkbox :value="$key">{{ $label }}</flux:menu.checkbox>
                        @endforeach
                    </flux:menu.checkbox.group>
                </flux:menu.group>

                @if ($groups->isNotEmpty())
                    <flux:menu.group :heading="$groupSource->named(__('questions.controls.groups'))">
                        <flux:menu.checkbox.group wire:model.live="definition.projects.groups">
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
                    {{-- Empty fields are not "nothing": the placeholders show the window the answer is read over; max stops the spinner at the shortest run. --}}
                    <flux:input type="number" min="1" :max="$result->shortestRun" wire:model.live.debounce.500ms="definition.turns.from" :label="__('questions.controls.turn_from')" :placeholder="$result->turns->from" />
                    <flux:input type="number" min="1" :max="$result->shortestRun" wire:model.live.debounce.500ms="definition.turns.to" :label="__('questions.controls.turn_to')" :placeholder="$result->turns->to" />
                </div>
            </flux:popover>
        </x-statistics.chip>
        </span>

        <span class="inline-flex max-w-full shrink-0 items-center gap-1.5 whitespace-nowrap [&>*]:min-w-0">
        <span class="text-zinc-500 dark:text-zinc-400">{{ __('questions.sentence.compared_by') }}</span>
        <x-statistics.chip :read-only="$readOnly" :label="$parts['compare_by'] ?? __('questions.sentence.nothing')" :muted="$parts['compare_by'] === null">
            <flux:menu>
                <flux:menu.radio.group wire:model.live="definition.compare_by">
                    <flux:menu.radio value="">{{ __('questions.controls.none') }}</flux:menu.radio>
                    @foreach ($compareOptions as $key => $label)
                        <flux:menu.radio :value="$key" wire:key="compare-{{ $key }}">{{ $label }}</flux:menu.radio>
                    @endforeach
                </flux:menu.radio.group>
                {{-- The group the others are measured against: it comes first, and the delta is the other group minus it. --}}
                @if ($baselineOptions !== [])
                    <flux:menu.separator />
                    <flux:menu.group :heading="__('questions.controls.baseline')">
                        <flux:menu.radio.group wire:model.live="baselineChoice">
                            @foreach ($baselineOptions as $key => $label)
                                <flux:menu.radio :value="(string) $key === '' ? \App\Livewire\Statistics\QuestionEditor::NO_VALUE : (string) $key" wire:key="baseline-{{ $key }}">{{ $label }}</flux:menu.radio>
                            @endforeach
                        </flux:menu.radio.group>
                    </flux:menu.group>
                @endif
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
                <flux:menu.radio.group wire:model.live="definition.split_by">
                    <flux:menu.radio value="">{{ __('questions.controls.none') }}</flux:menu.radio>
                    @foreach ($splitOptions as $key => $label)
                        @unless ($key === ($definition['compare_by'] ?? ''))
                            <flux:menu.radio :value="$key" wire:key="split-{{ $key }}">{{ $label }}</flux:menu.radio>
                        @endunless
                    @endforeach
                </flux:menu.radio.group>
            </flux:menu>
        </x-statistics.chip>
        </span>
        </div>
    </flux:card>

    {{-- The answer's form, centred above the answer. --}}
    <div class="flex justify-center">
        <div class="flex flex-wrap items-center justify-center gap-2">
            @if ($current->form === ResultForm::Trajectory)
                <flux:radio.group wire:model.live="definition.curve" variant="segmented" size="sm">
                    @foreach (CurveMode::cases() as $mode)
                        <x-segmented-option :value="$mode->value" :label="$mode->label()"
                            :tooltip="trans_choice('statistics.trajectory.'.$mode->value.'_tooltip', $rollingWindow, ['count' => $rollingWindow])" />
                    @endforeach
                </flux:radio.group>
            @endif

            <flux:radio.group wire:model.live="definition.form" variant="segmented" size="sm">
                @foreach ($forms as $form)
                    <x-segmented-option :value="$form->value" :label="$form->label()" :tooltip="__('questions.form_hints.'.$form->value)" :disabled="! $current->supports($form)" />
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
                @if ($current->curve === CurveMode::Rolling)
                    <flux:text size="sm">{{ trans_choice('statistics.trajectory.rolling_hint', $rollingWindow, ['count' => $rollingWindow]) }}</flux:text>
                @endif
                @break
            @default
                <x-statistics.point-panels :result="$result" :metric="$current->metric" />
        @endswitch

        <x-statistics.run-table :result="$result" :metric="$current->metric" />
    @endif
</div>
