@use('App\Discussion\Support\LocaleNumber')

<div>
    <x-flyout :name="\App\Livewire\Projects\StatisticsFlyout::MODAL" width="720px">
        <x-modal-header :heading="__('statistics.title')" :subheading="__('statistics.subheading')" />

        <div class="mt-6 flex-1 overflow-y-auto">
            @if (! $opened)
                {{-- The modal opens on the click; the numbers follow with the server's answer.
                     Same blocks as below (two pie cards, trajectory card, table), so nothing jumps. --}}
                <div role="status" aria-busy="true" data-statistics-skeleton
                     x-data="{ slow: false, timer: null }"
                     x-on:open-statistics.window="slow = false; clearTimeout(timer); timer = setTimeout(() => slow = true, 15000)">
                    <flux:callout x-show="slow" x-cloak variant="warning" icon="exclamation-triangle" role="alert" class="mb-4" :text="__('statistics.load_failed')" />
                    <span class="sr-only">{{ __('common.loading') }}</span>
                    <flux:skeleton.group animate="shimmer" aria-hidden="true">
                        <div class="grid gap-6 sm:grid-cols-2">
                            @foreach ([1, 2] as $pie)
                                <flux:card size="sm">
                                    <flux:skeleton class="h-5 w-28" />
                                    <flux:skeleton class="mt-1.5 h-4 w-36" />
                                    <flux:skeleton class="mt-4 aspect-square h-auto w-full rounded-full" />
                                    <div class="mt-3 space-y-1">
                                        @foreach ([1, 2, 3, 4] as $row)
                                            <flux:skeleton class="h-5 w-full" />
                                        @endforeach
                                    </div>
                                    <flux:skeleton class="mt-4 h-[30px] w-full" />
                                </flux:card>
                            @endforeach
                        </div>

                        <flux:card size="sm" class="mt-6">
                            <flux:skeleton class="h-[34px] w-full" />
                            <flux:skeleton class="mt-4 aspect-[2/1] h-auto w-full rounded-lg" />
                            <flux:skeleton class="mx-auto mt-3 h-5 w-2/3" />
                        </flux:card>

                        <div class="pt-6">
                            @foreach ([1, 2, 3, 4, 5] as $row)
                                <flux:skeleton class="h-[47.6px] w-full" />
                            @endforeach
                        </div>
                    </flux:skeleton.group>
                </div>
            @elseif ($report->isEmpty())
                <x-empty-state icon="chart-bar">
                    {{ __('statistics.empty') }}
                </x-empty-state>
            @else
                {{-- The two measures side by side. Same colour per participant in
                     both, so a slice that is bigger on the right than on the left
                     reads as "speaks rarely, writes at length" without any maths. --}}
                <div class="grid gap-6 sm:grid-cols-2">
                    @foreach ($charts->pies() as $pie)
                        <x-statistics.share-pie
                            :sectors="$pie['sectors']"
                            :label="$pie['label']"
                            :unit="$pie['unit']"
                            :gini="$pie['gini']"
                            :gini-title="$pie['giniTitle']"
                            :legend="$pie['legend']"
                            :share-label="$pie['shareLabel']"
                            :ceiling="$report->giniCeiling"
                            :total="$pie['total']"
                        />
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
                                {{ $trajectoryMode === \App\Discussion\Values\CurveMode::Rolling
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
                        wire:key="trajectory-{{ $trajectoryUnit->value }}-{{ $trajectoryMode->value }}-{{ count($trajectoryRows) }}"
                        wire:loading.delay.class="opacity-60"
                        wire:target="trajectoryUnit,trajectoryMode"
                        :value="$trajectoryRows"
                        class="mt-4 aspect-[2/1] transition-opacity"
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

                        {{-- Flux hides the tooltip by opacity only and leaves it in place; without this it swallows clicks on whatever it last covered. --}}
                        <flux:chart.tooltip class="pointer-events-none">
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
                                        <span class="size-2.5 shrink-0 rounded-full" style="background-color: var(--color-{{ $charts->colorFor($index) }}-500)"></span>
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
    </x-flyout>
</div>
