{{-- Study-only, removable: see app/Study/README.md. Operations only: no share, no effect. --}}
@php($states = ['done' => 'bg-emerald-500/80 text-white', 'started' => 'bg-sky-500/70 text-white', 'given_up' => 'bg-red-500/80 text-white', 'open' => 'bg-zinc-200 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300'])

<section class="mx-auto w-full max-w-6xl" wire:poll.30s>
    <x-page-header :heading="__('study::progress.heading')" :subheading="__('study::progress.subheading')">
        <x-slot:actions>
            <div class="flex items-center gap-3">
                <x-environment-badge />
                <flux:button size="sm" icon="plus" :href="route('study.plan')" wire:navigate>{{ __('study::plan.link') }}</flux:button>
                @if (! empty($report['waves']))
                    <flux:select size="sm" wire:model.live="wave" :aria-label="__('study::progress.wave')">
                        <flux:select.option value="">{{ __('study::progress.all_waves') }}</flux:select.option>
                        @foreach ($report['waves'] as $id => $name)
                            <flux:select.option :value="$id">{{ __('study::progress.wave') }} {{ $name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
            </div>
        </x-slot:actions>
    </x-page-header>

    @if ($report['missing'] !== [])
        <flux:callout class="mt-6" icon="information-circle" :heading="__('study::progress.missing_heading')"
            :text="__('study::progress.missing', ['categories' => implode(', ', $report['missing'])])" />
    @else
        @php($totals = $report['totals'])

        @if ($report['stale'])
            <flux:callout class="mt-6" variant="danger" icon="exclamation-triangle" data-stale
                :heading="__('study::progress.stale_heading')"
                :text="__('study::progress.stale', ['minutes' => config('study.stale_after_minutes')])" />
        @endif

        <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <flux:card size="sm">
                <flux:text size="sm">{{ __('study::progress.done') }}</flux:text>
                <flux:heading size="lg" data-done>{{ $totals['done'] }} / {{ $totals['total'] }}</flux:heading>
                <flux:text size="sm">{{ __('study::progress.breakdown', ['started' => $totals['started'], 'open' => $totals['open'], 'given_up' => $totals['given_up']]) }}</flux:text>
            </flux:card>
            <flux:card size="sm">
                <flux:text size="sm">{{ __('study::progress.pace') }}</flux:text>
                <flux:heading size="lg">{{ __('study::progress.turns_per_hour', ['count' => $report['turnsPerHour']]) }}</flux:heading>
            </flux:card>
            <flux:card size="sm">
                <flux:text size="sm">{{ __('study::progress.eta') }}</flux:text>
                <flux:heading size="lg">
                    {{ $report['hoursLeft'] === null ? '–' : __('study::progress.hours', ['hours' => number_format($report['hoursLeft'], 1, ',', '.')]) }}
                </flux:heading>
            </flux:card>
            <flux:card size="sm">
                <flux:text size="sm">{{ __('study::progress.last_turn') }}</flux:text>
                <flux:heading size="lg">{{ $report['lastTurnAt'] === null ? '–' : \Illuminate\Support\Carbon::parse($report['lastTurnAt'])->diffForHumans() }}</flux:heading>
                <flux:text size="sm">{{ __('study::progress.queued', ['count' => $report['queued']]) }}</flux:text>
            </flux:card>
        </div>

        @forelse ($report['grids'] as $pipeline => $grid)
            <flux:card class="mt-6 overflow-x-auto">
                <flux:heading size="lg">{{ __("pipelines.{$pipeline}") }}</flux:heading>
                <table class="mt-3 border-separate border-spacing-1 text-xs" data-grid="{{ $pipeline }}">
                    <caption class="sr-only">{{ __('study::progress.grid_caption') }}</caption>
                    <thead>
                        <tr>
                            <th class="text-start font-normal text-zinc-500">{{ __('study::progress.focus_duo') }}</th>
                            @foreach ($report['columns'] as $name)
                                <th scope="col" class="px-1 font-medium">{{ $name }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($report['rows'] as $focusId => $focus)
                            <tr>
                                <th scope="row" class="pe-2 text-start font-medium">{{ $focus }}</th>
                                @foreach ($report['columns'] as $duoId => $duo)
                                    @php($cell = $grid[$focusId][$duoId] ?? null)
                                    @php($state = match (true) { $cell === null => null, $cell['given_up'] > 0 => 'given_up', $cell['done'] === $cell['total'] => 'done', $cell['done'] + $cell['started'] > 0 => 'started', default => 'open' })
                                    <td class="min-w-14 rounded px-1.5 py-1 text-center tabular-nums {{ $state === null ? 'text-zinc-400' : $states[$state] }}"
                                        title="{{ $focus }} × {{ $duo }}{{ $cell === null ? '' : ': '.__('study::progress.cell_title', $cell) }}">
                                        {{ $cell === null ? '–' : $cell['done'].'/'.$cell['total'] }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </flux:card>
        @empty
            <flux:callout class="mt-6" icon="information-circle" :text="__('study::progress.no_cells')" />
        @endforelse

        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            <flux:card>
                <flux:heading>{{ __('study::progress.disruptions') }}</flux:heading>
                @forelse ($report['disruptions'] as $kind => $count)
                    <div class="mt-2 flex justify-between text-sm"><span class="font-mono">{{ $kind }}</span><span class="tabular-nums">{{ $count }}</span></div>
                @empty
                    <flux:text class="mt-2">{{ __('study::progress.none') }}</flux:text>
                @endforelse
            </flux:card>
            <flux:card>
                <flux:heading>{{ __('study::progress.failed_jobs') }}</flux:heading>
                @forelse ($report['failedJobs'] as $failed)
                    <div class="mt-2 text-sm">
                        <span class="font-medium">#{{ $failed['project'] ?? '?' }}</span>
                        <span class="text-zinc-500">{{ $failed['at'] }}</span>
                        <div class="truncate font-mono text-xs" title="{{ $failed['reason'] }}">{{ $failed['reason'] }}</div>
                    </div>
                @empty
                    <flux:text class="mt-2">{{ __('study::progress.none') }}</flux:text>
                @endforelse
            </flux:card>
        </div>
    @endif
</section>
