<div class="space-y-6">
    @if ($droppedTagValues > 0)
        <flux:callout variant="warning" icon="exclamation-triangle"
            :heading="trans_choice('comparisons.dropped_tags', $droppedTagValues, ['count' => $droppedTagValues])" />
    @endif

    <section class="space-y-4">
        <div class="flex items-center justify-between gap-3">
            <flux:heading size="lg">{{ __('comparisons.series.heading') }}</flux:heading>
            <flux:button size="sm" icon="plus" wire:click="addSeries" class="cursor-pointer">{{ __('comparisons.series.add') }}</flux:button>
        </div>

        @if ($definitions === [])
            <x-empty-state icon="queue-list">{{ __('comparisons.series.empty') }}</x-empty-state>
        @endif

        @foreach ($definitions as $index => $definition)
            @php($referenceType = $series[$index]['reference']['type'] ?? 'each')

            <flux:card wire:key="series-{{ $index }}" class="space-y-5">
                <div class="flex items-end gap-3">
                    <span class="mb-3 size-3 shrink-0 rounded-full" style="background-color: var(--color-{{ $seriesColors[$index] }}-500)"></span>
                    <flux:input wire:model.blur="series.{{ $index }}.label" :label="__('comparisons.series.label')" class="flex-1" />
                    <flux:button variant="ghost" size="sm" icon="trash" wire:click="removeSeries({{ $index }})"
                        class="cursor-pointer" :aria-label="__('comparisons.series.remove')" />
                </div>

                <div class="grid gap-5 md:grid-cols-2">
                    {{-- Which runs --}}
                    <div class="space-y-3">
                        <flux:heading size="sm">{{ __('comparisons.series.projects') }}</flux:heading>

                        @foreach ($projectCategories as $category)
                            <flux:select variant="listbox" multiple
                                wire:key="series-{{ $index }}-project-tags-{{ $category->id }}"
                                wire:model.live="series.{{ $index }}.projects.tags.{{ $category->id }}"
                                :label="$category->name" :placeholder="__('comparisons.series.any')">
                                @foreach ($category->values as $value)
                                    <flux:select.option :value="$value->id">{{ $value->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        @endforeach

                        <flux:select wire:model.live="series.{{ $index }}.projects.pipeline" :label="__('comparisons.series.pipeline')">
                            <option value="">{{ __('comparisons.series.any') }}</option>
                            @foreach ($pipelines as $name => $label)
                                <option value="{{ $name }}">{{ $label }}</option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:model.live="series.{{ $index }}.projects.model" :label="__('comparisons.series.model')">
                            <option value="">{{ __('comparisons.series.any') }}</option>
                            @foreach ($models as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </flux:select>

                        <div>
                            <flux:text size="sm">{{ trans_choice('comparisons.series.matches', $matches[$index]->count(), ['count' => $matches[$index]->count()]) }}</flux:text>
                            @if ($matches[$index]->isNotEmpty())
                                <ul class="mt-1 max-h-32 overflow-y-auto text-xs text-zinc-500 dark:text-zinc-400">
                                    @foreach ($matches[$index] as $project)
                                        <li wire:key="series-{{ $index }}-match-{{ $project->id }}" class="truncate">{{ $project->title }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </div>

                    {{-- Which agents --}}
                    <div class="space-y-3">
                        <flux:heading size="sm">{{ __('comparisons.reference.heading') }}</flux:heading>

                        <flux:select wire:model.live="series.{{ $index }}.reference.type" :label="__('comparisons.reference.type')">
                            @foreach ($referenceTypes as $type)
                                <option value="{{ $type }}">{{ __("comparisons.reference.types.{$type}") }}</option>
                            @endforeach
                        </flux:select>

                        @switch($referenceType)
                            @case('persona_tags')
                                @foreach ($personaCategories as $category)
                                    <flux:select variant="listbox" multiple
                                        wire:key="series-{{ $index }}-persona-tags-{{ $category->id }}"
                                        wire:model.live="series.{{ $index }}.reference.tags.{{ $category->id }}"
                                        :label="$category->name" :placeholder="__('comparisons.series.any')">
                                        @foreach ($category->values as $value)
                                            <flux:select.option :value="$value->id">{{ $value->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                @endforeach
                                @break

                            @case('seat')
                                <flux:input type="number" min="1" wire:model.live.debounce.500ms="series.{{ $index }}.reference.seat" :label="__('comparisons.reference.seat')" />
                                @break

                            @case('expert_name')
                                <flux:select wire:model.live="series.{{ $index }}.reference.name" :label="__('comparisons.reference.expert_name')">
                                    <option value="">—</option>
                                    @foreach ($expertNames as $expertName)
                                        <option value="{{ $expertName }}">{{ $expertName }}</option>
                                    @endforeach
                                </flux:select>
                                @break
                        @endswitch

                        <flux:text size="sm">{{ __('comparisons.reference.shared_hint') }}</flux:text>
                    </div>
                </div>
            </flux:card>
        @endforeach
    </section>

    <section class="space-y-4">
        <flux:heading size="lg">{{ __('comparisons.view.heading') }}</flux:heading>

        <div class="flex flex-wrap items-end gap-3">
            <flux:select wire:model.live="viewSettings.metric" :label="__('comparisons.view.metric')" class="max-w-56">
                @foreach ($metrics as $metric)
                    <option value="{{ $metric->value }}">{{ $metric->label() }}</option>
                @endforeach
            </flux:select>

            <flux:radio.group wire:model.live="viewSettings.display" :label="__('comparisons.view.display')" variant="segmented" size="sm">
                @foreach ($displays as $display)
                    <flux:radio :value="$display->value" :label="$display->label()" />
                @endforeach
            </flux:radio.group>

            <flux:input type="number" min="1" class="max-w-40"
                wire:model.live.debounce.500ms="viewSettings.turn_limit"
                :label="__('comparisons.view.turn_limit')" :placeholder="__('comparisons.view.all_turns')" />

            @if ($comparisonView->display === \App\Discussion\Values\ComparisonDisplay::Trajectory && $comparisonView->metric->hasTrajectory())
                <flux:radio.group wire:model.live="viewSettings.trajectory_mode" :label="__('comparisons.view.mode')" variant="segmented" size="sm">
                    <flux:radio value="cumulative" :label="__('comparisons.modes.cumulative')" />
                    <flux:radio value="rolling" :label="__('comparisons.modes.rolling')" />
                </flux:radio.group>
            @endif
        </div>

        @if ($comparisonView->metric->isRunLevel())
            <flux:text size="sm">{{ __('comparisons.reference.run_level_hint') }}</flux:text>
        @endif

        @foreach ($results as $result)
            @if ($result->excluded > 0)
                <flux:text size="sm" class="text-amber-600 dark:text-amber-400">
                    {{ trans_choice('comparisons.results.excluded', $result->excluded, ['count' => $result->excluded, 'label' => $result->label]) }}
                </flux:text>
            @endif
        @endforeach

        @if ($lines === [])
            <x-empty-state icon="chart-bar">{{ __('comparisons.results.empty') }}</x-empty-state>
        @elseif ($comparisonView->display === \App\Discussion\Values\ComparisonDisplay::Table)
            <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-100 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium">{{ __('comparisons.results.line') }}</th>
                            <th class="px-3 py-2 text-right font-medium">{{ __('comparisons.results.n') }}</th>
                            <th class="px-3 py-2 text-right font-medium">{{ __('comparisons.results.mean') }}</th>
                            <th class="px-3 py-2 text-right font-medium">{{ __('comparisons.results.min') }}</th>
                            <th class="px-3 py-2 text-right font-medium">{{ __('comparisons.results.max') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($lines as $entry)
                            <tr wire:key="result-row-{{ $loop->index }}">
                                <td class="px-3 py-2">
                                    <span class="flex items-center gap-2">
                                        <span class="size-2.5 shrink-0 rounded-full" style="background-color: var(--color-{{ $entry['color'] }}-500)"></span>
                                        <span class="truncate">{{ $entry['label'] }}</span>
                                    </span>
                                    <details class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                        <summary class="cursor-pointer">{{ __('comparisons.results.per_project') }}</summary>
                                        <ul class="mt-1 space-y-0.5">
                                            @foreach ($entry['line']->values as $value)
                                                <li class="flex justify-between gap-3">
                                                    <span class="truncate">{{ $value->title }}</span>
                                                    <span class="font-mono">{{ $comparisonView->metric->format($value->value) }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </details>
                                </td>
                                <td class="px-3 py-2 text-right align-top font-mono">{{ $entry['line']->n() }}</td>
                                <td class="px-3 py-2 text-right align-top font-mono">{{ $comparisonView->metric->format($entry['line']->mean()) }}</td>
                                <td class="px-3 py-2 text-right align-top font-mono">{{ $comparisonView->metric->format($entry['line']->min()) }}</td>
                                <td class="px-3 py-2 text-right align-top font-mono">{{ $comparisonView->metric->format($entry['line']->max()) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @elseif ($comparisonView->display === \App\Discussion\Values\ComparisonDisplay::Bars)
            <x-statistics.range-bars :bars="$bars" :scale="$barScale" />
        @elseif (! $comparisonView->metric->hasTrajectory())
            <x-empty-state icon="presentation-chart-line">{{ __('comparisons.results.no_trajectory') }}</x-empty-state>
        @elseif (count($trajectoryRows) < 2)
            <x-empty-state icon="presentation-chart-line">{{ __('comparisons.results.too_short') }}</x-empty-state>
        @else
            {{-- Keyed on the data: the chart's children are wire:ignore'd, so new
                 rows need a fresh element rather than a morphed one. --}}
            <flux:chart wire:key="comparison-curve-{{ md5(json_encode($trajectoryRows)) }}" :value="$trajectoryRows" class="aspect-[2/1]">
                <flux:chart.svg>
                    @foreach ($lines as $index => $entry)
                        <flux:chart.line :field="'l'.$index" curve="none" style="color: var(--color-{{ $entry['color'] }}-500)" />
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
                        <flux:chart.tooltip.value :field="'l'.$index" :label="$entry['label']" suffix=" %" />
                        <flux:chart.tooltip.value :field="'n'.$index" :label="__('comparisons.results.n')" />
                    @endforeach
                </flux:chart.tooltip>
            </flux:chart>

            <div class="flex flex-wrap justify-center gap-x-4 gap-y-1">
                @foreach ($lines as $entry)
                    <flux:chart.legend :label="$entry['label']" class="p-0!">
                        <flux:chart.legend.indicator style="background-color: var(--color-{{ $entry['color'] }}-500)" />
                    </flux:chart.legend>
                @endforeach
            </div>
        @endif
    </section>
</div>