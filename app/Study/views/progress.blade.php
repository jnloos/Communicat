{{-- Study-only, removable: see app/Study/README.md. Operations only: no share, no effect. --}}
{{-- Cell tints are Flux's own badge colours, so text keeps AA contrast in both themes. --}}
@php($states = [
    'done' => 'bg-green-400/20 text-green-800 dark:bg-green-400/40 dark:text-green-200',
    'started' => 'bg-sky-400/20 text-sky-800 dark:bg-sky-400/40 dark:text-sky-200',
    'open' => 'bg-zinc-400/15 text-zinc-700 dark:bg-zinc-400/40 dark:text-zinc-200',
    'given_up' => 'bg-red-400/20 text-red-700 dark:bg-red-400/40 dark:text-red-200',
])

<section class="mx-auto w-full max-w-6xl" wire:poll.30s>
    <x-page-header :heading="__('study::progress.heading')" :subheading="__('study::progress.subheading')">
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                @if ($snapshot->pause === null)
                    <flux:button size="sm" icon="pause" wire:click="askToPause" data-pause>{{ __('study::progress.pause') }}</flux:button>
                @else
                    <flux:button size="sm" icon="play" variant="primary" wire:click="askToResume" data-resume>{{ __('study::progress.resume') }}</flux:button>
                @endif
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

    @if ($controlError !== null)
        <flux:callout class="mt-6" variant="danger" icon="exclamation-triangle" data-control-error :text="$controlError" />
    @endif

    @if ($snapshot->pause !== null)
        <flux:callout class="mt-6" icon="pause-circle" data-pause-callout
            :variant="$snapshot->pause->source === \App\Study\Control\PauseSource::Manual ? 'warning' : 'danger'"
            :heading="__('study::progress.paused_heading')"
            :text="__('study::progress.paused', ['since' => $snapshot->pause->since->format('H:i'), 'reason' => $snapshot->pause->reason])" />
    @endif

    {{-- The running callout is the paused one's counterpart; a standstill replaces it. --}}
    @if ($snapshot->standstill())
        <flux:callout class="mt-6" variant="danger" icon="exclamation-triangle" data-stale
            :heading="__('study::progress.stale_heading')"
            :text="__('study::progress.stale', ['minutes' => config('study.stale_after_minutes')])" />
    @elseif ($snapshot->pause === null)
        <flux:callout class="mt-6" variant="success" icon="play-circle" data-running
            :heading="__('study::progress.running_heading')"
            :text="$snapshot->remaining() > 0
                ? __('study::progress.running', ['turns' => $snapshot->remaining()])
                : __('study::progress.running_idle')" />
    @endif

    @if ($report['missing'] !== [])
        <flux:callout class="mt-6" icon="information-circle" :heading="__('study::progress.missing_heading')"
            :text="__('study::progress.missing', ['categories' => implode(', ', $report['missing'])])" />
    @else
        @php($totals = $report['totals'])

        <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <flux:card size="sm">
                <flux:text size="sm">{{ __('study::progress.done') }}</flux:text>
                <flux:heading size="lg" class="tabular-nums" data-done>{{ $totals['done'] }} / {{ $totals['total'] }}</flux:heading>
                <flux:text size="sm">{{ __('study::progress.breakdown', ['started' => $totals['started'], 'open' => $totals['open'], 'given_up' => $totals['given_up']]) }}</flux:text>
            </flux:card>
            <flux:card size="sm">
                <flux:text size="sm">{{ __('study::progress.pace') }}</flux:text>
                <flux:heading size="lg" class="tabular-nums">{{ __('study::progress.turns_per_hour', ['count' => $report['turnsPerHour']]) }}</flux:heading>
            </flux:card>
            <flux:card size="sm">
                <flux:text size="sm">{{ __('study::progress.eta') }}</flux:text>
                <flux:heading size="lg" class="tabular-nums">
                    {{ $report['hoursLeft'] === null ? '–' : __('study::progress.hours', ['hours' => number_format($report['hoursLeft'], 1, ',', '.')]) }}
                </flux:heading>
            </flux:card>
            <flux:card size="sm">
                <flux:text size="sm">{{ __('study::progress.last_turn') }}</flux:text>
                <flux:heading size="lg">{{ $report['lastTurnAt'] === null ? '–' : \Illuminate\Support\Carbon::parse($report['lastTurnAt'])->diffForHumans() }}</flux:heading>
                <flux:text size="sm">{{ __('study::progress.queued', ['count' => $report['queued']]) }}</flux:text>
                <p data-worker-signal @if ($snapshot->workerSilent()) data-worker-stale @endif
                    @class(['text-sm', 'text-zinc-500 dark:text-zinc-400' => ! $snapshot->workerSilent(), 'text-red-600 dark:text-red-400' => $snapshot->workerSilent()])>
                    {{ __('study::progress.worker_signal', ['ago' => $snapshot->workerSeenAt?->diffForHumans() ?? '–']) }}
                </p>
            </flux:card>
        </div>

        @if ($report['grids'] !== [])
            <ul class="mt-6 flex flex-wrap gap-x-4 gap-y-1 text-sm text-zinc-500 dark:text-zinc-400" aria-label="{{ __('study::progress.legend_label') }}">
                @foreach ($states as $state => $classes)
                    <li class="flex items-center gap-1.5"><span class="size-3 rounded-sm {{ $classes }}"></span>{{ __("study::progress.legend.{$state}") }}</li>
                @endforeach
            </ul>
        @endif

        @forelse ($report['grids'] as $pipeline => $grid)
            <flux:card class="mt-6 overflow-x-auto">
                <flux:heading size="lg">{{ __("pipelines.{$pipeline}") }}</flux:heading>
                <table class="mt-3 border-separate border-spacing-1 text-xs" data-grid="{{ $pipeline }}">
                    <caption class="sr-only">{{ __('study::progress.grid_caption') }}</caption>
                    <thead>
                        <tr>
                            <th class="text-start font-normal text-zinc-500 dark:text-zinc-400">{{ __('study::progress.focus_duo') }}</th>
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
                                    <td class="min-w-14 rounded px-1.5 py-1 text-center tabular-nums {{ $state === null ? 'text-zinc-500 dark:text-zinc-400' : $states[$state] }}"
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
                        <span class="tabular-nums text-zinc-500 dark:text-zinc-400">{{ $failed['at'] }}</span>
                        <div class="truncate font-mono text-xs" title="{{ $failed['reason'] }}">{{ $failed['reason'] }}</div>
                    </div>
                @empty
                    <flux:text class="mt-2">{{ __('study::progress.none') }}</flux:text>
                @endforelse
            </flux:card>
        </div>
    @endif

    <flux:card class="mt-6" data-events>
        <flux:heading>{{ __('study::progress.events') }}</flux:heading>
        @if ($snapshot->recentEvents->isNotEmpty())
            <ul class="mt-2 space-y-2">
                @foreach ($snapshot->recentEvents as $event)
                    <li class="flex items-baseline gap-3 text-sm">
                        <flux:badge size="sm" class="shrink-0" :color="['crit' => 'red', 'warn' => 'amber', 'ok' => 'green', 'info' => 'zinc'][$event->level] ?? 'zinc'">{{ __("study::progress.levels.{$event->level}") }}</flux:badge>
                        <time class="shrink-0 tabular-nums text-zinc-500 dark:text-zinc-400" datetime="{{ $event->created_at->toIso8601String() }}">{{ $event->created_at->format('d.m. H:i') }}</time>
                        <span class="min-w-0 break-words">{{ $event->message === '' ? __("study::watch.kind.{$event->kind}") : $event->message }}</span>
                    </li>
                @endforeach
            </ul>
        @else
            <flux:text class="mt-2">{{ __('study::progress.none') }}</flux:text>
        @endif
    </flux:card>
</section>
