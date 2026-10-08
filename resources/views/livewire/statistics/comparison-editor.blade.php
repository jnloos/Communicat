@use('App\Discussion\Values\ComparisonDisplay')

<div class="space-y-8">
    {{-- The comparison is the object of the page: its name is the heading,
         with the autosave state and the same "…" menu as in the list. Its
         actions live on the page, which owns the list and the name modal. --}}
    <div class="flex items-center gap-3">
        <flux:heading size="xl" level="2" class="min-w-0 truncate">{{ $name }}</flux:heading>

        {{-- Every edit is saved at once; this says so, since there is no save button. --}}
        <flux:text size="sm" class="flex shrink-0 items-center gap-1" wire:loading.remove>
            <flux:icon.check variant="micro" />{{ __('comparisons.autosave.saved') }}
        </flux:text>
        <flux:text size="sm" class="shrink-0" wire:loading>{{ __('comparisons.autosave.saving') }}</flux:text>

        <flux:dropdown position="bottom" align="end" class="ms-auto">
            <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" :aria-label="__('common.actions.more')" />
            <flux:menu>
                <flux:menu.item icon="pencil-square" wire:click="$parent.startRenaming({{ $comparisonId }})">{{ __('comparisons.actions.rename') }}</flux:menu.item>
                <flux:menu.item icon="document-duplicate" wire:click="$parent.duplicate({{ $comparisonId }})">{{ __('comparisons.actions.duplicate') }}</flux:menu.item>
                <flux:menu.separator />
                <flux:menu.item variant="danger" icon="trash" wire:click="$parent.confirmDelete({{ $comparisonId }})">{{ __('comparisons.actions.delete') }}</flux:menu.item>
            </flux:menu>
        </flux:dropdown>
    </div>

    @if ($droppedTagValues > 0 || $emptiedSeries !== [] || $unresolvedSeries !== [])
        <flux:callout variant="warning" icon="exclamation-triangle">
            @if ($droppedTagValues > 0)
                <flux:callout.heading>{{ trans_choice('comparisons.dropped_tags', $droppedTagValues, ['count' => $droppedTagValues]) }}</flux:callout.heading>
            @endif
            @if ($emptiedSeries !== [])
                <flux:callout.text>
                    {{ trans_choice('comparisons.emptied_series', count($emptiedSeries), [
                        'labels' => collect($emptiedSeries)->map(fn ($label) => __('comparisons.quoted', ['label' => $label]))->implode(', '),
                    ]) }}
                </flux:callout.text>
            @endif
            @if ($unresolvedSeries !== [])
                <flux:callout.text>
                    {{ trans_choice('comparisons.unresolved_series', count($unresolvedSeries), [
                        'labels' => collect($unresolvedSeries)->map(fn ($label) => __('comparisons.quoted', ['label' => $label]))->implode(', '),
                    ]) }}
                </flux:callout.text>
            @endif
        </flux:callout>
    @endif

    <section class="space-y-3">
        <div class="flex items-center justify-between gap-3">
            <x-statistics.section-label>{{ __('comparisons.series.heading') }}</x-statistics.section-label>
            <flux:button size="sm" variant="ghost" icon="plus" wire:click="addSeries">{{ __('comparisons.series.add') }}</flux:button>
        </div>

        @if ($definitions === [])
            <x-empty-state icon="queue-list">{{ __('comparisons.series.empty') }}</x-empty-state>
        @endif

        @php($runLevel = $comparisonView->metric->isRunLevel())

        @foreach ($definitions as $index => $definition)
            @php($referenceType = $series[$index]['reference']['type'] ?? 'each')

            {{-- Folding is Alpine state only: it saves nothing. Beyond three
                 series the cards start folded to their summary line. --}}
            <flux:card wire:key="series-{{ $index }}" x-data="{ open: {{ $openCards[$index] ? 'true' : 'false' }} }" class="p-4!">
                <div class="flex items-center gap-2">
                    <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-controls="series-body-{{ $index }}"
                        class="flex min-w-0 flex-1 items-center gap-2 rounded text-start text-sm" title="{{ __('comparisons.series.toggle') }}">
                        <flux:icon.chevron-right variant="micro" class="shrink-0 text-zinc-400 transition-transform" x-bind:class="open && 'rotate-90'" />
                        <span class="size-3 shrink-0 rounded-full" style="background-color: var(--color-{{ $seriesColors[$index] }})"></span>
                        <span class="truncate">
                            <span class="font-medium text-zinc-800 dark:text-white">{{ $results[$index]->label }}</span>
                            <span class="text-zinc-500 dark:text-zinc-400">— {{ $summaries[$index] }}</span>
                        </span>
                    </button>
                    <flux:button variant="ghost" size="sm" icon="trash" wire:click="confirmRemoveSeries({{ $index }})"
                        :aria-label="__('comparisons.series.remove')" :tooltip="__('comparisons.series.remove')" />
                </div>

                <div id="series-body-{{ $index }}" x-show="open" @if (! $openCards[$index]) x-cloak @endif class="mt-5 space-y-5">
                    <flux:input size="sm" wire:model.blur="series.{{ $index }}.label" :label="__('comparisons.series.label')"
                        :placeholder="__('comparisons.series.label')" />

                    <div class="grid gap-6 md:grid-cols-2">
                        {{-- Which runs --}}
                        <div class="space-y-4">
                            <x-statistics.section-label>{{ __('comparisons.series.projects') }}</x-statistics.section-label>

                            {{-- A template's tag this database does not know: the series
                                 matches no run while it is here; removing it is the
                                 user's explicit choice to widen. --}}
                            @if ($definition->hasUnresolvedTags())
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach ($definition->unresolvedLabels() as $tagIndex => $tagLabel)
                                        <flux:badge size="sm" color="amber" icon="exclamation-triangle" :title="__('comparisons.series.unresolved')"
                                            wire:key="series-{{ $index }}-unresolved-{{ $tagIndex }}">
                                            {{ $tagLabel }}
                                            <flux:badge.close wire:click="removeUnresolvedTag({{ $index }}, {{ $tagIndex }})" :aria-label="__('comparisons.series.remove_unresolved', ['tag' => $tagLabel])" />
                                        </flux:badge>
                                    @endforeach
                                </div>
                            @endif

                            {{-- One pill group per category: OR within it, AND across
                                 categories (TagFilter); no pill picked means any. --}}
                            @foreach ($projectCategories as $category)
                                <flux:checkbox.group variant="pills"
                                    wire:key="series-{{ $index }}-project-tags-{{ $category->id }}"
                                    wire:model.live="series.{{ $index }}.projects.tags.{{ $category->id }}"
                                    :label="$category->label()" class="gap-1.5!">
                                    @foreach ($category->values as $value)
                                        <flux:checkbox variant="pills" :value="(string) $value->id" :label="$value->name" />
                                    @endforeach
                                </flux:checkbox.group>
                            @endforeach

                            <flux:select variant="listbox" clearable wire:model.live="series.{{ $index }}.projects.pipeline"
                                :label="__('comparisons.series.pipeline')" :placeholder="__('comparisons.series.any')">
                                @foreach ($pipelines as $pipelineName => $label)
                                    <flux:select.option :value="$pipelineName">{{ $label }}</flux:select.option>
                                @endforeach
                            </flux:select>

                            <flux:select variant="listbox" clearable wire:model.live="series.{{ $index }}.projects.model"
                                :label="__('comparisons.series.model')" :placeholder="__('comparisons.series.any')">
                                @foreach ($models as $key => $label)
                                    <flux:select.option :value="$key">{{ $label }}</flux:select.option>
                                @endforeach
                            </flux:select>

                            {{-- Folded by default so every card keeps its height; the count stays visible. --}}
                            <details class="group text-sm">
                                <summary class="flex list-none items-center gap-1 text-zinc-500 dark:text-zinc-400 [&::-webkit-details-marker]:hidden">
                                    @if ($matches[$index]->isNotEmpty())
                                        <flux:icon.chevron-right variant="micro" class="transition-transform group-open:rotate-90" />
                                    @endif
                                    {{ trans_choice('comparisons.series.matches', $matches[$index]->count(), ['count' => $matches[$index]->count()]) }}
                                </summary>
                                @if ($matches[$index]->isNotEmpty())
                                    <ul class="mt-1 max-h-40 space-y-0.5 overflow-y-auto ps-5 text-xs text-zinc-500 dark:text-zinc-400">
                                        @foreach ($matches[$index] as $project)
                                            <li wire:key="series-{{ $index }}-match-{{ $project->id }}" class="truncate">{{ $project->title }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </details>
                        </div>

                        {{-- Which agents --}}
                        <div class="space-y-4">
                            <x-statistics.section-label>{{ __('comparisons.reference.heading') }}</x-statistics.section-label>

                            <flux:select variant="listbox" wire:model.live="series.{{ $index }}.reference.type" :label="__('comparisons.reference.type')" :disabled="$runLevel">
                                @foreach ($referenceTypes as $type)
                                    <flux:select.option :value="$type">{{ __("comparisons.reference.types.{$type}") }}</flux:select.option>
                                @endforeach
                            </flux:select>

                            @switch($referenceType)
                                @case('persona_tags')
                                    @foreach ($personaCategories as $category)
                                        <flux:checkbox.group variant="pills"
                                            wire:key="series-{{ $index }}-persona-tags-{{ $category->id }}"
                                            wire:model.live="series.{{ $index }}.reference.tags.{{ $category->id }}"
                                            :label="$category->label()" :disabled="$runLevel" class="gap-1.5!">
                                            @foreach ($category->values as $value)
                                                <flux:checkbox variant="pills" :value="(string) $value->id" :label="$value->name" />
                                            @endforeach
                                        </flux:checkbox.group>
                                    @endforeach
                                    @break

                                @case('seat')
                                    <flux:input type="number" min="1" wire:model.live.debounce.500ms="series.{{ $index }}.reference.seat" :label="__('comparisons.reference.seat')" :disabled="$runLevel" />
                                    @break

                                @case('expert_name')
                                    <flux:select variant="listbox" searchable wire:model.live="series.{{ $index }}.reference.name"
                                        :label="__('comparisons.reference.expert_name')" :placeholder="__('comparisons.reference.choose_expert')" :disabled="$runLevel">
                                        @foreach ($expertNames as $expertName)
                                            <flux:select.option :value="$expertName">{{ $expertName }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    @break
                            @endswitch

                            {{-- Only a tag reference can hit several agents of one run. --}}
                            @if ($runLevel)
                                <flux:text size="sm">{{ __('comparisons.reference.run_level_hint') }}</flux:text>
                            @elseif ($referenceType === 'persona_tags')
                                <flux:text size="sm">{{ __('comparisons.reference.shared_hint') }}</flux:text>
                            @endif
                        </div>
                    </div>
                </div>
            </flux:card>
        @endforeach
    </section>

    <section class="space-y-3">
        <x-statistics.section-label>{{ __('comparisons.view.heading') }}</x-statistics.section-label>

        {{-- Every control carries its label the same way, so the row lines up at the bottom. --}}
        <div class="flex flex-wrap items-end gap-4 rounded-lg bg-zinc-50 p-3 dark:bg-white/5">
            <flux:field class="w-56">
                <flux:label>{{ __('comparisons.view.metric') }}</flux:label>
                <flux:select variant="listbox" wire:model.live="viewSettings.metric">
                    @foreach ($metrics as $metric)
                        <flux:select.option :value="$metric->value">{{ $metric->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            <flux:field>
                <flux:label>{{ __('comparisons.view.display') }}</flux:label>
                <flux:radio.group wire:model.live="viewSettings.display" variant="segmented">
                    @foreach ($displays as $display)
                        @if ($display->supports($comparisonView->metric))
                            <flux:radio :value="$display->value" :label="$display->label()" />
                        @else
                            <flux:radio :value="$display->value" :label="$display->label()" disabled :title="$display->unsupportedHint()" />
                        @endif
                    @endforeach
                </flux:radio.group>
            </flux:field>

            <flux:field class="w-36">
                <flux:label>{{ __('comparisons.view.turn_limit') }}</flux:label>
                <flux:input type="number" min="1" wire:model.live.debounce.500ms="viewSettings.turn_limit" :placeholder="__('comparisons.view.all_turns')" />
            </flux:field>

            @if ($comparisonView->display === ComparisonDisplay::Trajectory)
                <flux:field>
                    <flux:label>{{ __('comparisons.view.mode') }}</flux:label>
                    <flux:radio.group wire:model.live="viewSettings.trajectory_mode" variant="segmented">
                        <flux:radio value="cumulative" :label="__('comparisons.modes.cumulative')" />
                        <flux:radio value="rolling" :label="__('comparisons.modes.rolling')" />
                    </flux:radio.group>
                </flux:field>
            @endif
        </div>

        @if ($comparisonView->display === ComparisonDisplay::Trajectory && $comparisonView->trajectoryMode === 'rolling')
            <flux:text size="sm">{{ trans_choice('statistics.trajectory.rolling_hint', $rollingWindow, ['count' => $rollingWindow]) }}</flux:text>
        @endif
    </section>

    <section class="space-y-4">
        <x-statistics.section-label>{{ __('comparisons.results.heading') }}</x-statistics.section-label>

        @php($excluded = collect($results)->filter(fn ($result) => $result->excluded > 0))

        @if ($paletteRepeats || $excluded->isNotEmpty())
            <flux:callout variant="warning" icon="exclamation-triangle">
                @if ($paletteRepeats)
                    <flux:callout.text>{{ __('comparisons.results.palette_repeats') }}</flux:callout.text>
                @endif
                @foreach ($excluded as $result)
                    <flux:callout.text>
                        {{ trans_choice('comparisons.results.excluded', $result->excluded, ['count' => $result->excluded, 'label' => $result->label]) }}
                    </flux:callout.text>
                @endforeach
            </flux:callout>
        @endif

        @if ($lines === [])
            <x-empty-state icon="chart-bar">{{ __('comparisons.results.empty') }}</x-empty-state>
        @elseif ($comparisonView->display === ComparisonDisplay::Table)
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('comparisons.results.line') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('comparisons.results.n') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('comparisons.results.mean') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('comparisons.results.min') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('comparisons.results.max') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($lines as $entry)
                        <flux:table.row wire:key="result-row-{{ $loop->index }}">
                            <flux:table.cell class="align-top">
                                <span class="flex items-center gap-2">
                                    <span class="size-2.5 shrink-0 rounded-full" style="background-color: var(--color-{{ $entry['color'] }})"></span>
                                    <span class="truncate font-medium text-zinc-800 dark:text-white">{{ $entry['label'] }}</span>
                                </span>
                                <details class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                    <summary>{{ __('comparisons.results.per_project') }}</summary>
                                    <ul class="mt-1 space-y-0.5">
                                        @foreach ($entry['line']->values as $value)
                                            <li class="flex justify-between gap-3">
                                                <span class="truncate">{{ $value->title }}</span>
                                                <span class="tabular-nums">{{ $comparisonView->metric->format($value->value) }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </details>
                            </flux:table.cell>
                            <flux:table.cell align="end" class="align-top tabular-nums">{{ $entry['line']->n() }}</flux:table.cell>
                            <flux:table.cell align="end" class="align-top tabular-nums">{{ $comparisonView->metric->format($entry['line']->mean()) }}</flux:table.cell>
                            <flux:table.cell align="end" class="align-top tabular-nums">{{ $comparisonView->metric->format($entry['line']->min()) }}</flux:table.cell>
                            <flux:table.cell align="end" class="align-top tabular-nums">{{ $comparisonView->metric->format($entry['line']->max()) }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @elseif ($comparisonView->display === ComparisonDisplay::Bars)
            <x-statistics.range-bars :groups="$barGroups" :scale="$barScale" :ticks="$barTicks" :equal-share="$equalShare" />
        @elseif ($comparisonView->display === ComparisonDisplay::Pie)
            <x-statistics.pies :pies="$pies" :equal-share="$equalShare" />
        @elseif (count($trajectoryRows) < 2)
            <x-empty-state icon="presentation-chart-line">{{ __('comparisons.results.too_short') }}</x-empty-state>
        @else
            {{-- Keyed on the data: the chart's children are wire:ignore'd, so new
                 rows need a fresh element rather than a morphed one. --}}
            <flux:chart wire:key="comparison-curve-{{ md5(json_encode($trajectoryRows)) }}" :value="$trajectoryRows" class="aspect-[2/1]">
                <flux:chart.svg>
                    @foreach ($lines as $index => $entry)
                        <flux:chart.line :field="'l'.$index" curve="none" style="color: var(--color-{{ $entry['lineColor'] }})" />
                    @endforeach

                    <flux:chart.axis axis="x" field="turn" scale="linear">
                        <flux:chart.axis.line />
                        <flux:chart.axis.tick />
                    </flux:chart.axis>
                    <flux:chart.axis axis="y" tick-start="0" tick-end="100" tick-suffix=" %">
                        <flux:chart.axis.grid />
                        <flux:chart.axis.tick />
                    </flux:chart.axis>

                    <flux:chart.cursor />
                </flux:chart.svg>

                <flux:chart.tooltip>
                    <flux:chart.tooltip.heading field="label" />
                    @foreach ($lines as $index => $entry)
                        <flux:chart.tooltip.value :field="'t'.$index" :label="$entry['label']" />
                        <flux:chart.tooltip.value :field="'n'.$index" :label="__('comparisons.results.n_of', ['label' => $entry['label']])" />
                    @endforeach
                </flux:chart.tooltip>
            </flux:chart>

            <div class="flex flex-wrap justify-center gap-x-4 gap-y-1">
                @foreach ($lines as $entry)
                    <flux:chart.legend :label="$entry['label']" class="p-0!">
                        <flux:chart.legend.indicator style="background-color: var(--color-{{ $entry['lineColor'] }})" />
                    </flux:chart.legend>
                @endforeach
            </div>
        @endif
    </section>
</div>
