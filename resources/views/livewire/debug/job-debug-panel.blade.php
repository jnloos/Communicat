<div>
    {{-- Trigger Button (fixed bottom-right). z-[60] keeps it above the
         chat composer (z-50) so it stays clickable. --}}
    <button
        wire:click="open"
        title="Job Debug Panel"
        class="fixed bottom-5 right-5 z-[60] flex items-center justify-center w-10 h-10 rounded-full bg-amber-500 hover:bg-amber-400 text-white shadow-lg shadow-amber-500/30 transition-colors"
    >
        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/>
            <path d="M12 8v4"/>
            <path d="M12 16h.01"/>
        </svg>
    </button>

    {{-- Panel Modal — open state bound to $show so re-renders never close it --}}
    <flux:modal wire:model="show" name="job-debug-panel" variant="flyout" class="w-[1000px] max-w-full">
        <div class="space-y-4">
            {{-- Header --}}
            <div class="flex items-start justify-between gap-4">
                <div class="space-y-1">
                    <flux:heading size="lg" class="font-mono tracking-tight">Job Debug</flux:heading>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        Letzte 50 Jobs · Zeile anklicken für Details.
                    </p>
                </div>

                {{-- Live / Pause toggle --}}
                <button
                    type="button"
                    wire:click="togglePause"
                    class="shrink-0 inline-flex items-center gap-2 rounded-md border px-2.5 py-1.5 text-xs font-medium font-mono transition-colors
                        {{ $live
                            ? 'border-emerald-300 dark:border-emerald-700 text-emerald-700 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-900/20'
                            : 'border-amber-300 dark:border-amber-700 text-amber-700 dark:text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-900/20' }}"
                    title="{{ $live ? 'Live-Updates pausieren, um in Ruhe zu lesen' : 'Live-Updates fortsetzen' }}"
                >
                    @if ($live)
                        <span class="relative flex h-2 w-2">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                        </span>
                        LIVE
                    @else
                        <span class="inline-flex h-2 w-2 rounded-full bg-amber-500"></span>
                        PAUSIERT
                    @endif
                </button>
            </div>

            {{-- Job list --}}
            <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                <table class="w-full text-sm">
                    <thead class="sticky top-0 bg-zinc-100 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400 text-xs uppercase tracking-wide">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium w-6"></th>
                            <th class="px-3 py-2 text-left font-medium">Job</th>
                            <th class="px-3 py-2 text-left font-medium">Projekt</th>
                            <th class="px-3 py-2 text-left font-medium">Status</th>
                            <th class="px-3 py-2 text-left font-medium">Start</th>
                            <th class="px-3 py-2 text-right font-medium">Dauer</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse ($logs as $log)
                            @php($isSel = $selectedJobId === $log->id)
                            <tr
                                wire:key="job-{{ $log->id }}"
                                wire:click="selectJob({{ $log->id }})"
                                class="cursor-pointer transition-colors {{ $isSel ? 'bg-amber-50 dark:bg-amber-900/20' : 'hover:bg-zinc-50 dark:hover:bg-zinc-800/50' }}"
                            >
                                {{-- LED status dot + selected accent --}}
                                <td class="pl-3 pr-0 py-2">
                                    <span class="flex items-center">
                                        <span class="mr-2 h-4 w-0.5 rounded-full {{ $isSel ? 'bg-amber-500' : 'bg-transparent' }}"></span>
                                        @if ($log->status === 'success')
                                            <span class="h-2 w-2 rounded-full bg-emerald-500" title="success"></span>
                                        @elseif ($log->status === 'failed')
                                            <span class="h-2 w-2 rounded-full bg-red-500" title="failed"></span>
                                        @else
                                            <span class="relative flex h-2 w-2" title="running">
                                                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-amber-400 opacity-75"></span>
                                                <span class="relative h-2 w-2 rounded-full bg-amber-500"></span>
                                            </span>
                                        @endif
                                    </span>
                                </td>
                                <td class="px-3 py-2 font-mono text-xs text-zinc-700 dark:text-zinc-300">
                                    {{ class_basename($log->job_class) }}
                                    <span class="text-zinc-400 dark:text-zinc-600">#{{ $log->id }}</span>
                                </td>
                                <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400 truncate max-w-[16rem]">
                                    {{ $log->project?->title ?? '–' }}
                                </td>
                                <td class="px-3 py-2">
                                    @if ($log->status === 'success')
                                        <span class="font-mono text-xs text-emerald-600 dark:text-emerald-400">success</span>
                                    @elseif ($log->status === 'failed')
                                        <span class="font-mono text-xs text-red-600 dark:text-red-400" title="{{ $log->payload['error'] ?? '' }}">failed</span>
                                    @else
                                        <span class="font-mono text-xs text-amber-600 dark:text-amber-400">running…</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 font-mono text-xs text-zinc-500 dark:text-zinc-400 whitespace-nowrap">
                                    {{ $log->started_at->format('H:i:s') }}
                                </td>
                                <td class="px-3 py-2 font-mono text-xs text-right text-zinc-500 dark:text-zinc-400 whitespace-nowrap">
                                    {{ $log->duration() !== null ? $log->duration() . 's' : '…' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-3 py-8 text-center text-zinc-400 font-mono text-xs">
                                    Noch keine Jobs gelaufen.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Detail pane --}}
            @if ($selected)
                <div wire:key="detail-{{ $selected->id }}" class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4">
                    <div class="flex items-center justify-between mb-3">
                        <flux:heading size="sm" class="font-mono">
                            Job #{{ $selected->id }} · {{ class_basename($selected->job_class) }}
                        </flux:heading>
                        <button
                            wire:click="selectJob({{ $selected->id }})"
                            class="text-xs font-mono text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300"
                        >
                            ✕ schließen
                        </button>
                    </div>

                    @if ($selected->status === 'failed' && !empty($selected->payload['error']))
                        <div class="mb-3 rounded bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 p-2 text-xs font-mono text-red-700 dark:text-red-300 whitespace-pre-wrap max-h-48 overflow-auto">
                            {{ $selected->payload['error'] }}
                        </div>
                    @endif

                    <flux:tab.group>
                        <flux:tabs variant="segmented">
                            <flux:tab name="prompts" selected>
                                Prompts ({{ $selected->promptLogs->count() }})
                            </flux:tab>
                            <flux:tab name="messages">
                                Nachrichten ({{ $selected->messages->count() }})
                            </flux:tab>
                        </flux:tabs>

                        <flux:tab.panel name="prompts">
                            @forelse ($selected->promptLogs as $plog)
                                <div
                                    wire:key="plog-{{ $plog->id }}"
                                    x-data="{ open: false }"
                                    class="rounded border border-zinc-200 dark:border-zinc-700 mb-2 bg-zinc-50 dark:bg-zinc-900/40"
                                >
                                    <button
                                        type="button"
                                        @click="open = !open"
                                        class="w-full cursor-pointer px-3 py-2 text-xs flex items-center gap-2 flex-wrap text-left"
                                    >
                                        <svg class="h-3 w-3 shrink-0 text-zinc-400 transition-transform" :class="open && 'rotate-90'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
                                        <span class="font-mono text-amber-700 dark:text-amber-400">{{ $plog->label ?? '–' }}</span>
                                        <span class="text-zinc-400">·</span>
                                        <span class="font-mono text-zinc-600 dark:text-zinc-400">{{ $plog->model }}</span>
                                        <span class="text-zinc-400">·</span>
                                        <span class="font-mono text-zinc-500">{{ $plog->latency_ms !== null ? $plog->latency_ms . ' ms' : '–' }}</span>
                                        <span class="ml-auto font-mono text-zinc-400">{{ $plog->created_at?->format('H:i:s') }}</span>
                                    </button>
                                    <div x-show="open" x-cloak class="grid grid-cols-1 md:grid-cols-2 gap-0 border-t border-zinc-200 dark:border-zinc-700">
                                        <div class="p-3 border-b md:border-b-0 md:border-r border-zinc-200 dark:border-zinc-700">
                                            <div class="text-xs uppercase tracking-wide text-zinc-500 mb-1">Prompt</div>
                                            <pre class="text-xs whitespace-pre-wrap font-mono text-zinc-700 dark:text-zinc-300 max-h-80 overflow-auto">{{ $plog->prompt }}</pre>
                                        </div>
                                        <div class="p-3">
                                            <div class="text-xs uppercase tracking-wide text-zinc-500 mb-1">Response</div>
                                            <pre class="text-xs whitespace-pre-wrap font-mono text-zinc-700 dark:text-zinc-300 max-h-80 overflow-auto">{{ $plog->response }}</pre>
                                        </div>
                                    </div>
                                </div>
                            @empty
                                <p class="text-xs text-zinc-400 py-4 text-center font-mono">Keine Prompts für diesen Job aufgezeichnet.</p>
                            @endforelse
                        </flux:tab.panel>

                        <flux:tab.panel name="messages">
                            @forelse ($selected->messages as $msg)
                                <div wire:key="dmsg-{{ $msg->id }}" class="rounded border border-zinc-200 dark:border-zinc-700 mb-2 p-3 bg-zinc-50 dark:bg-zinc-900/40">
                                    <div class="flex items-center gap-2 text-xs mb-1">
                                        <span class="font-semibold text-zinc-700 dark:text-zinc-300">
                                            {{ $msg->expert?->name ?? 'System' }}
                                        </span>
                                        @if ($msg->adjacency_pair_type)
                                            <span class="text-zinc-400">·</span>
                                            <span class="font-mono text-zinc-500">{{ $msg->adjacency_pair_type }}</span>
                                        @endif
                                        @if ($msg->adjacency_partner_id)
                                            <span class="text-zinc-400">→</span>
                                            <span class="text-zinc-500">{{ $msg->adjacencyPartner?->name }}</span>
                                        @endif
                                        <span class="ml-auto font-mono text-zinc-400">{{ $msg->created_at?->format('H:i:s') }}</span>
                                    </div>
                                    <div class="text-sm whitespace-pre-wrap text-zinc-700 dark:text-zinc-300">
                                        {{ $msg->content }}
                                    </div>
                                </div>
                            @empty
                                <p class="text-xs text-zinc-400 py-4 text-center font-mono">Keine Nachrichten von diesem Job erzeugt.</p>
                            @endforelse
                        </flux:tab.panel>
                    </flux:tab.group>
                </div>
            @endif
        </div>
    </flux:modal>
</div>
