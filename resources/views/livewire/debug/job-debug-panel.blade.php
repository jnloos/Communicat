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
    <flux:modal name="job-debug-panel" variant="flyout" class="w-[720px] max-w-full">
        <div class="space-y-4">
            <flux:heading size="lg">Job Debug Panel</flux:heading>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                Letzte 50 Jobs – aktualisiert via Broadcasting
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
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
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
                                            class="inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-900/40 dark:text-red-400 cursor-pointer"
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
        </div>
    </flux:modal>
</div>
