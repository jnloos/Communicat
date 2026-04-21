<div>
    <!-- Trigger Button (fixed bottom-right) -->
    <button
        wire:click="open"
        title="Job Debug Panel"
        class="fixed bottom-5 right-5 z-50 flex items-center justify-center w-10 h-10 rounded-full bg-amber-500 hover:bg-amber-400 text-white shadow-lg transition-colors"
    >
        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/>
            <path d="M12 8v4"/>
            <path d="M12 16h.01"/>
        </svg>
    </button>

    <!-- Panel Modal -->
    <flux:modal name="job-debug-panel" variant="flyout" class="w-[1000px] max-w-full">
        <div class="space-y-4">
            <flux:heading size="lg">Job Debug Panel</flux:heading>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                Letzte 50 Jobs – aktualisiert via Broadcasting. Zeile anklicken für Details.
            </p>

            <div class="overflow-x-auto rounded-md border border-zinc-200 dark:border-zinc-700">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium">Job</th>
                            <th class="px-3 py-2 text-left font-medium">Projekt</th>
                            <th class="px-3 py-2 text-left font-medium">Status</th>
                            <th class="px-3 py-2 text-left font-medium">Gestartet</th>
                            <th class="px-3 py-2 text-left font-medium">Dauer</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                        @forelse ($logs as $log)
                            <tr
                                wire:click="selectJob({{ $log->id }})"
                                class="cursor-pointer {{ $selectedJobId === $log->id ? 'bg-amber-50 dark:bg-amber-900/20' : 'hover:bg-zinc-50 dark:hover:bg-zinc-800/50' }}"
                            >
                                <td class="px-3 py-2 font-mono text-xs text-zinc-700 dark:text-zinc-300">
                                    {{ class_basename($log->job_class) }}
                                </td>
                                <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">
                                    {{ $log->project?->title ?? '–' }}
                                </td>
                                <td class="px-3 py-2">
                                    @if ($log->status === 'success')
                                        <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700 dark:bg-green-900/40 dark:text-green-400">
                                            ✓ success
                                        </span>
                                    @elseif ($log->status === 'failed')
                                        <span
                                            class="inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-900/40 dark:text-red-400"
                                            title="{{ $log->payload['error'] ?? '' }}"
                                        >
                                            ✗ failed
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/40 dark:text-amber-400">
                                            ⟳ running
                                        </span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-xs text-zinc-500 dark:text-zinc-400 whitespace-nowrap">
                                    {{ $log->started_at->format('H:i:s') }}
                                </td>
                                <td class="px-3 py-2 text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ $log->duration() !== null ? $log->duration() . 's' : '…' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-3 py-6 text-center text-zinc-400">
                                    Noch keine Jobs gelaufen.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($selected)
                <div class="rounded-md border border-zinc-200 dark:border-zinc-700 p-4">
                    <div class="flex items-center justify-between mb-3">
                        <flux:heading size="sm">
                            Details · Job #{{ $selected->id }} · {{ class_basename($selected->job_class) }}
                        </flux:heading>
                        <button
                            wire:click="selectJob({{ $selected->id }})"
                            class="text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300"
                        >
                            Schließen
                        </button>
                    </div>

                    @if ($selected->status === 'failed' && !empty($selected->payload['error']))
                        <div class="mb-3 rounded bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 p-2 text-xs font-mono text-red-700 dark:text-red-300 whitespace-pre-wrap">
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
                                <details class="rounded border border-zinc-200 dark:border-zinc-700 mb-2 bg-zinc-50 dark:bg-zinc-900/40">
                                    <summary class="cursor-pointer px-3 py-2 text-xs flex items-center gap-2 flex-wrap">
                                        <span class="font-mono text-amber-700 dark:text-amber-400">
                                            {{ $plog->label ?? '–' }}
                                        </span>
                                        <span class="text-zinc-400">·</span>
                                        <span class="font-mono text-zinc-600 dark:text-zinc-400">
                                            {{ $plog->model }}
                                        </span>
                                        <span class="text-zinc-400">·</span>
                                        <span class="text-zinc-500">
                                            {{ $plog->latency_ms !== null ? $plog->latency_ms . ' ms' : '–' }}
                                        </span>
                                        <span class="ml-auto text-zinc-400">
                                            {{ $plog->created_at?->format('H:i:s') }}
                                        </span>
                                    </summary>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-0 border-t border-zinc-200 dark:border-zinc-700">
                                        <div class="p-3 border-b md:border-b-0 md:border-r border-zinc-200 dark:border-zinc-700">
                                            <div class="text-[10px] uppercase tracking-wide text-zinc-500 mb-1">Prompt</div>
                                            <pre class="text-xs whitespace-pre-wrap font-mono text-zinc-700 dark:text-zinc-300 max-h-80 overflow-auto">{{ $plog->prompt }}</pre>
                                        </div>
                                        <div class="p-3">
                                            <div class="text-[10px] uppercase tracking-wide text-zinc-500 mb-1">Response</div>
                                            <pre class="text-xs whitespace-pre-wrap font-mono text-zinc-700 dark:text-zinc-300 max-h-80 overflow-auto">{{ $plog->response }}</pre>
                                        </div>
                                    </div>
                                </details>
                            @empty
                                <p class="text-xs text-zinc-400 py-4 text-center">Keine Prompts für diesen Job aufgezeichnet.</p>
                            @endforelse
                        </flux:tab.panel>

                        <flux:tab.panel name="messages">
                            @forelse ($selected->messages as $msg)
                                <div class="rounded border border-zinc-200 dark:border-zinc-700 mb-2 p-3 bg-zinc-50 dark:bg-zinc-900/40">
                                    <div class="flex items-center gap-2 text-xs mb-1">
                                        <span class="font-semibold text-zinc-700 dark:text-zinc-300">
                                            {{ $msg->expert?->name ?? 'System' }}
                                        </span>
                                        @if ($msg->adjacency_pair_type)
                                            <span class="text-zinc-400">·</span>
                                            <span class="text-zinc-500">{{ $msg->adjacency_pair_type }}</span>
                                        @endif
                                        @if ($msg->next_speaker)
                                            <span class="text-zinc-400">→</span>
                                            <span class="text-zinc-500">{{ $msg->next_speaker }}</span>
                                        @endif
                                        <span class="ml-auto text-zinc-400">
                                            {{ $msg->created_at?->format('H:i:s') }}
                                        </span>
                                    </div>
                                    <div class="text-sm whitespace-pre-wrap text-zinc-700 dark:text-zinc-300">
                                        {{ $msg->content }}
                                    </div>
                                </div>
                            @empty
                                <p class="text-xs text-zinc-400 py-4 text-center">Keine Nachrichten von diesem Job erzeugt.</p>
                            @endforelse
                        </flux:tab.panel>
                    </flux:tab.group>
                </div>
            @endif
        </div>
    </flux:modal>
</div>
