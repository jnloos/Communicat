@use('App\Services\Transfer\ImportReport')

@props([
    'report', {{-- ImportReport --}}
])

{{-- What an import would do or did: a card per section with its counts, the entries in an accordion, failures first and open. --}}
<div class="space-y-3">
    @forelse ($report->sections() as $section)
        @php($failed = $report->count($section, ImportReport::FAILED))

        <flux:card size="sm" wire:key="report-{{ $section }}">
            <flux:accordion>
                <flux:accordion.item :expanded="$failed > 0">
                    <flux:accordion.heading>
                        <div class="flex flex-wrap items-center gap-2">
                            <span>{{ __('transfer.sections.'.$section) }}</span>
                            @foreach (ImportReport::COLORS as $outcome => $color)
                                @if ($report->count($section, $outcome) > 0)
                                    <flux:badge size="sm" :color="$color">{{ trans_choice('transfer.outcomes.'.$outcome, $report->count($section, $outcome), ['count' => $report->count($section, $outcome)]) }}</flux:badge>
                                @endif
                            @endforeach
                        </div>
                    </flux:accordion.heading>
                    <flux:accordion.content>
                        <ul class="divide-y divide-zinc-100 dark:divide-white/10">
                            @foreach ($report->entriesFailedFirst($section) as $entry)
                                <li class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-1.5 text-sm">
                                    {{-- An unreadable entry may have no name to show. --}}
                                    <span @class(['font-medium', 'text-zinc-800 dark:text-white' => $entry['name'] !== '', 'italic text-zinc-500 dark:text-zinc-400' => $entry['name'] === ''])>{{ $entry['name'] !== '' ? $entry['name'] : __('transfer.unnamed_entry') }}</span>
                                    <flux:badge size="sm" :color="ImportReport::COLORS[$entry['outcome']] ?? 'zinc'">{{ __('transfer.outcome.'.$entry['outcome']) }}</flux:badge>
                                    @if ($entry['note'])
                                        <span class="w-full text-xs text-zinc-500 dark:text-zinc-400">{{ $entry['note'] }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </flux:accordion.content>
                </flux:accordion.item>
            </flux:accordion>
        </flux:card>
    @empty
        <flux:text size="sm">{{ __('transfer.import.empty') }}</flux:text>
    @endforelse
</div>
