@use('App\Discussion\Support\LocaleNumber')

<div>
    <flux:modal :name="\App\Livewire\Projects\StatisticsFlyout::MODAL" variant="flyout" class="flex w-full flex-col p-5! sm:p-8! md:w-[720px]">
        <x-modal-header :heading="__('statistics.title')" :subheading="__('statistics.subheading')" />

        <div class="mt-6 flex-1 overflow-y-auto">
            @if ($report->isEmpty())
                <x-empty-state icon="chart-bar">
                    {{ __('statistics.empty') }}
                </x-empty-state>
            @else
                {{-- The two measures side by side. Same colour per participant in
                     both, so a slice that is bigger on the right than on the left
                     reads as "speaks rarely, writes at length" without any maths. --}}
                <div class="grid gap-6 sm:grid-cols-2">
                    @foreach ([
                        ['sectors' => $turnSectors, 'label' => __('statistics.turn_share'), 'unit' => __('statistics.columns.turns'), 'gini' => $report->turnGini, 'total' => trans_choice('statistics.turns_total', $report->spokenTurns, ['count' => $report->spokenTurns])],
                        ['sectors' => $wordSectors, 'label' => __('statistics.word_share'), 'unit' => __('statistics.columns.words'), 'gini' => $report->wordGini, 'total' => trans_choice('statistics.words_total', $report->totalWords, ['count' => LocaleNumber::decimal($report->totalWords, 0)])],
                    ] as $chart)
                        <flux:card size="sm">
                            <flux:heading size="sm">{{ $chart['label'] }}</flux:heading>
                            <flux:text size="sm" class="mt-0.5">{{ $chart['total'] }}</flux:text>

                            {{-- Hovering a sector: Flux marks the others data-inactive and
                                 hands the tooltip the sector's own colour through
                                 --flux-chart-color, which the indicator picks up. --}}
                            <flux:chart :value="$chart['sectors']" class="mt-4 aspect-square">
                                <flux:chart.svg>
                                    <flux:chart.pie
                                        field="value"
                                        label-field="name"
                                        class="origin-center cursor-pointer transition-[opacity,transform] duration-150 data-inactive:opacity-35 data-active:scale-105"
                                    />
                                </flux:chart.svg>

                                <flux:chart.tooltip>
                                    <flux:chart.tooltip.heading field="name" />
                                    <flux:chart.tooltip.value field="value" :label="$chart['unit']">
                                        <flux:chart.tooltip.indicator />
                                    </flux:chart.tooltip.value>
                                    <flux:chart.tooltip.value field="share" :label="__('statistics.columns.turn_share')" />
                                </flux:chart.tooltip>
                            </flux:chart>

                            {{-- The coefficient means nothing without its ceiling: with
                                 four agents the maximum is 0.75, not 1. --}}
                            <div class="mt-4 flex items-baseline justify-between border-t border-zinc-200 pt-3 dark:border-zinc-700">
                                <span class="text-xs uppercase tracking-wide text-zinc-500">{{ __('statistics.gini') }}</span>
                                <span class="font-mono text-sm text-zinc-700 dark:text-zinc-300">
                                    {{ LocaleNumber::decimal($chart['gini'], 2) }}
                                    <span class="text-zinc-400">/ {{ LocaleNumber::decimal($report->giniCeiling, 2) }}</span>
                                </span>
                            </div>
                        </flux:card>
                    @endforeach
                </div>

                {{-- How the shares got where they are. Cumulative shows where the run
                     settles, but flattens by arithmetic alone (one turn moves it by at
                     most ~1/n); the rolling view forgets everything older than the
                     window and shows whether the discussion itself is still moving. --}}
                <flux:card size="sm" class="mt-6">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <flux:heading size="sm">{{ __('statistics.trajectory.title') }}</flux:heading>
                            <flux:text size="sm" class="mt-0.5">
                                {{ $trajectoryMode === 'rolling'
                                    ? trans_choice('statistics.trajectory.rolling_hint', $trajectoryWindow, ['count' => $trajectoryWindow])
                                    : __('statistics.trajectory.cumulative_hint') }}
                            </flux:text>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <flux:radio.group wire:model.live="trajectoryUnit" variant="segmented" size="sm">
                                <x-segmented-option value="turns" :label="__('statistics.columns.turns')" :tooltip="__('statistics.trajectory.turns_tooltip')" />
                                <x-segmented-option value="words" :label="__('statistics.columns.words')" :tooltip="__('statistics.trajectory.words_tooltip')" />
                            </flux:radio.group>
                            <flux:radio.group wire:model.live="trajectoryMode" variant="segmented" size="sm">
                                <x-segmented-option value="cumulative" :label="__('statistics.trajectory.cumulative')" :tooltip="__('statistics.trajectory.cumulative_tooltip')" />
                                <x-segmented-option value="rolling" :label="__('statistics.trajectory.rolling')"
                                    :tooltip="trans_choice('statistics.trajectory.rolling_tooltip', $trajectoryWindow, ['count' => $trajectoryWindow])" />
                            </flux:radio.group>
                        </div>
                    </div>

                    {{-- Keyed on the toggles: the chart's children are wire:ignore'd, so a
                         new series needs a fresh element rather than a morphed one. --}}
                    <flux:chart
                        wire:key="trajectory-{{ $trajectoryUnit }}-{{ $trajectoryMode }}-{{ count($trajectoryRows) }}"
                        :value="$trajectoryRows"
                        class="mt-4 aspect-[2/1]"
                    >
                        <flux:chart.svg>
                            @foreach ($trajectoryLines as $line)
                                <flux:chart.line :field="$line['field']" curve="none" style="color: var(--color-{{ $line['color'] }}-500)" />
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
                            @foreach ($trajectoryLines as $line)
                                <flux:chart.tooltip.value :field="$line['field']" :label="$line['name']" suffix=" %" />
                            @endforeach
                        </flux:chart.tooltip>
                    </flux:chart>

                    <div class="mt-3 flex flex-wrap justify-center gap-x-4 gap-y-1">
                        @foreach ($trajectoryLines as $line)
                            <flux:chart.legend :label="$line['name']" class="p-0!">
                                <flux:chart.legend.indicator style="background-color: var(--color-{{ $line['color'] }}-500)" />
                            </flux:chart.legend>
                        @endforeach
                    </div>
                </flux:card>

                {{-- The numbers behind the slices: a pie cannot be read off precisely,
                     and a participant who never spoke has no slice at all. --}}
                <flux:table class="mt-6">
                    <flux:table.columns>
                        <flux:table.column>{{ __('statistics.columns.participant') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('statistics.columns.turns') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('statistics.columns.turn_share') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('statistics.columns.words') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('statistics.columns.word_share') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('statistics.columns.words_per_turn') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($report->speakers as $index => $share)
                            {{-- Someone who never spoke stays in the table, greyed out. --}}
                            <flux:table.row wire:key="share-{{ $share->expertId }}" :class="$share->turns === 0 ? '*:text-zinc-400! dark:*:text-zinc-600!' : ''">
                                <flux:table.cell variant="strong">
                                    <span class="flex items-center gap-2">
                                        <span class="size-2.5 shrink-0 rounded-full" style="background-color: var(--color-{{ $this->colorFor($index) }}-500)"></span>
                                        <span class="truncate">{{ $share->name }}</span>
                                    </span>
                                </flux:table.cell>
                                <flux:table.cell align="end" class="font-mono">{{ $share->turns }}</flux:table.cell>
                                <flux:table.cell align="end" class="font-mono">{{ LocaleNumber::percent($share->turnShare) }}</flux:table.cell>
                                <flux:table.cell align="end" class="font-mono">{{ LocaleNumber::decimal($share->words, 0) }}</flux:table.cell>
                                <flux:table.cell align="end" class="font-mono">{{ LocaleNumber::percent($share->wordShare) }}</flux:table.cell>
                                <flux:table.cell align="end" class="font-mono">{{ LocaleNumber::decimal($share->wordsPerTurn(), 1) }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </div>
    </flux:modal>
</div>
