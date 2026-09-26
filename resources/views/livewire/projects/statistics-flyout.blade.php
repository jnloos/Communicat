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
                        ['sectors' => $wordSectors, 'label' => __('statistics.word_share'), 'unit' => __('statistics.columns.words'), 'gini' => $report->wordGini, 'total' => trans_choice('statistics.words_total', $report->totalWords, ['count' => number_format($report->totalWords, 0, ',', '.')])],
                    ] as $chart)
                        <section class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
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
                                    {{ number_format($chart['gini'], 2, ',', '.') }}
                                    <span class="text-zinc-400">/ {{ number_format($report->giniCeiling, 2, ',', '.') }}</span>
                                </span>
                            </div>
                        </section>
                    @endforeach
                </div>

                {{-- The numbers behind the slices: a pie cannot be read off precisely,
                     and a participant who never spoke has no slice at all. --}}
                <div class="mt-6 overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <table class="w-full text-sm">
                        <thead class="bg-zinc-100 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                            <tr>
                                <th class="px-3 py-2 text-left font-medium">{{ __('statistics.columns.participant') }}</th>
                                <th class="px-3 py-2 text-right font-medium">{{ __('statistics.columns.turns') }}</th>
                                <th class="px-3 py-2 text-right font-medium">{{ __('statistics.columns.turn_share') }}</th>
                                <th class="px-3 py-2 text-right font-medium">{{ __('statistics.columns.words') }}</th>
                                <th class="px-3 py-2 text-right font-medium">{{ __('statistics.columns.word_share') }}</th>
                                <th class="px-3 py-2 text-right font-medium">{{ __('statistics.columns.words_per_turn') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($report->speakers as $index => $share)
                                <tr wire:key="share-{{ $share->expertId }}" @class(['text-zinc-400 dark:text-zinc-600' => $share->turns === 0])>
                                    <td class="px-3 py-2">
                                        <span class="flex items-center gap-2">
                                            <span class="size-2.5 shrink-0 rounded-full" style="background-color: var(--color-{{ $this->colorFor($index) }}-500)"></span>
                                            <span class="truncate">{{ $share->name }}</span>
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono">{{ $share->turns }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ number_format($share->turnShare * 100, 1, ',', '.') }} %</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ number_format($share->words, 0, ',', '.') }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ number_format($share->wordShare * 100, 1, ',', '.') }} %</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ number_format($share->wordsPerTurn(), 1, ',', '.') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <flux:text size="sm" class="mt-3">{{ __('statistics.footnote') }}</flux:text>
            @endif
        </div>
    </flux:modal>
</div>
