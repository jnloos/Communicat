@props([
    'logs',
    'selected' => null,
    'selectedJobId' => null,
])

{{-- The job list, shared by the full Job-Debug page and the flyout inside a
     discussion. A job opens right under its own row, so the detail is where the
     click was, in both hosts. Both hosts define selectJob(), the only Livewire
     action this markup calls -- that is what makes it reusable. --}}
@if ($logs->isEmpty())
    <x-empty-state icon="inbox">{{ __('debug.empty') }}</x-empty-state>
@else
    {{-- Compact rows: fifty jobs at Flux's default 52 px made the list over 2600 px long. --}}
    <flux:table class="[&_[data-flux-cell]]:py-1.5 [&_[data-flux-column]]:py-2">
        <flux:table.columns>
            <flux:table.column class="w-8"><span class="sr-only">{{ __('debug.detail.toggle') }}</span></flux:table.column>
            <flux:table.column>{{ __('debug.columns.job') }}</flux:table.column>
            <flux:table.column>{{ __('debug.columns.status') }}</flux:table.column>
            <flux:table.column align="end">{{ __('debug.columns.started') }}</flux:table.column>
            <flux:table.column align="end">{{ __('debug.columns.duration') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($logs as $log)
                @php($open = $selectedJobId === $log->id)
                <flux:table.row wire:key="job-{{ $log->id }}" wire:click="selectJob({{ $log->id }})" @class(['cursor-pointer', 'bg-zinc-800/5 dark:bg-white/[7%]' => $open])>
                    <flux:table.cell>
                        {{-- The keyboard way in; .stop, or the row's own click would close it again. --}}
                        <flux:button size="xs" variant="ghost" icon="chevron-right" wire:click.stop="selectJob({{ $log->id }})"
                            :aria-label="__('debug.detail.toggle_job', ['id' => $log->id])" aria-expanded="{{ $open ? 'true' : 'false' }}"
                            @class(['[&_svg]:rotate-90' => $open]) />
                    </flux:table.cell>
                    <flux:table.cell variant="strong" class="tabular-nums">
                        #{{ $log->id }}
                        <span class="ms-1 font-normal text-zinc-500 dark:text-zinc-300">{{ class_basename($log->job_class) }}</span>
                    </flux:table.cell>
                    <flux:table.cell><x-debug.status :status="$log->status" /></flux:table.cell>
                    <flux:table.cell align="end" class="tabular-nums">{{ $log->started_at->format('H:i:s') }}</flux:table.cell>
                    <flux:table.cell align="end" class="tabular-nums">{{ $log->duration() !== null ? $log->duration().' s' : '…' }}</flux:table.cell>
                </flux:table.row>

                @if ($open && $selected)
                    <flux:table.row wire:key="detail-{{ $selected->id }}">
                        <flux:table.cell colspan="5" class="whitespace-normal bg-zinc-800/5 dark:bg-white/[7%] px-3!">
                            <x-debug.job-detail :job="$selected" />
                        </flux:table.cell>
                    </flux:table.row>
                @endif
            @endforeach
        </flux:table.rows>
    </flux:table>
@endif
