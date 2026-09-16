<section class="mx-auto w-full max-w-6xl">
    <div class="space-y-6">
        {{-- Header --}}
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <flux:heading size="xl" level="1">{{ __('debug.title') }}</flux:heading>
                <flux:text class="mt-1">{{ __('debug.subheading') }}</flux:text>
            </div>

            <div class="flex w-full flex-wrap items-center gap-3 sm:w-auto">
                <flux:select wire:model.live="projectId" :placeholder="__('debug.select_project')" class="min-w-0 flex-1 sm:w-72">
                    @foreach ($projects as $project)
                        <flux:select.option :value="$project->id">{{ $project->title }}</flux:select.option>
                    @endforeach
                </flux:select>

                {{-- Live / Pause toggle --}}
                <flux:tooltip :content="$live ? __('debug.live.pause_hint') : __('debug.live.resume_hint')" position="bottom">
                    <flux:button wire:click="togglePause" :icon="$live ? 'pause' : 'play'" class="shrink-0 cursor-pointer">
                        <span class="inline-flex items-center gap-2">
                            @if ($live)
                                <span class="relative flex size-2">
                                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                                    <span class="relative inline-flex size-2 rounded-full bg-emerald-500"></span>
                                </span>
                                {{ __('debug.live.on') }}
                            @else
                                <span class="inline-flex size-2 rounded-full bg-amber-500"></span>
                                {{ __('debug.live.paused') }}
                            @endif
                        </span>
                    </flux:button>
                </flux:tooltip>
            </div>
        </div>

        {{-- Job list --}}
        <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
            <table class="w-full text-sm">
                <thead class="sticky top-0 bg-zinc-100 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400 text-xs uppercase tracking-wide">
                    <tr>
                        <th class="px-3 py-2 text-left font-medium w-6"></th>
                        <th class="px-3 py-2 text-left font-medium">{{ __('debug.columns.job') }}</th>
                        <th class="px-3 py-2 text-left font-medium">{{ __('debug.columns.status') }}</th>
                        <th class="px-3 py-2 text-left font-medium">{{ __('debug.columns.started') }}</th>
                        <th class="px-3 py-2 text-right font-medium">{{ __('debug.columns.duration') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($logs as $log)
                        @php($isSel = $selectedJobId === $log->id)
                        <tr
                            wire:key="job-{{ $log->id }}"
                            wire:click="selectJob({{ $log->id }})"
                            aria-selected="{{ $isSel ? 'true' : 'false' }}"
                            class="cursor-pointer transition-colors {{ $isSel ? 'bg-accent/10' : 'hover:bg-zinc-50 dark:hover:bg-zinc-800/50' }}"
                        >
                            {{-- LED status dot + selected accent (same accent as selected cards) --}}
                            <td class="pl-3 pr-0 py-2">
                                <span class="flex items-center">
                                    <span class="mr-2 h-4 w-0.5 rounded-full {{ $isSel ? 'bg-accent' : 'bg-transparent' }}"></span>
                                    @if ($log->status === 'success')
                                        <span class="h-2 w-2 rounded-full bg-emerald-500" title="{{ __('debug.status.success') }}"></span>
                                    @elseif ($log->status === 'failed')
                                        <span class="h-2 w-2 rounded-full bg-red-500" title="{{ __('debug.status.failed') }}"></span>
                                    @else
                                        <span class="relative flex h-2 w-2" title="{{ __('debug.status.running') }}">
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
                            <td class="px-3 py-2">
                                @if ($log->status === 'success')
                                    <span class="font-mono text-xs text-emerald-600 dark:text-emerald-400">{{ __('debug.status.success') }}</span>
                                @elseif ($log->status === 'failed')
                                    <span class="font-mono text-xs text-red-600 dark:text-red-400" title="{{ $log->payload['error'] ?? '' }}">{{ __('debug.status.failed') }}</span>
                                @else
                                    <span class="font-mono text-xs text-amber-600 dark:text-amber-400">{{ __('debug.status.running') }}</span>
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
                            <td colspan="5" class="px-3 py-8 text-center text-zinc-400 font-mono text-xs">
                                {{ __('debug.empty') }}
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
                        {{ __('debug.detail.heading', ['id' => $selected->id, 'class' => class_basename($selected->job_class)]) }}
                    </flux:heading>
                    <flux:tooltip :content="__('debug.detail.close')" position="bottom">
                        <flux:button
                            size="sm"
                            variant="ghost"
                            icon="x-mark"
                            class="cursor-pointer"
                            :aria-label="__('debug.detail.close')"
                            wire:click="selectJob({{ $selected->id }})"
                        />
                    </flux:tooltip>
                </div>

                @if ($selected->status === 'failed' && !empty($selected->payload['error']))
                    <div class="mb-3 rounded bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 p-2 text-xs font-mono text-red-700 dark:text-red-300 whitespace-pre-wrap max-h-48 overflow-auto">
                        {{ $selected->payload['error'] }}
                    </div>
                @endif

                <flux:tab.group>
                    <flux:tabs variant="segmented">
                        <flux:tab name="prompts" selected>
                            {{ __('debug.detail.tab_prompts', ['count' => $selected->promptLogs->count()]) }}
                        </flux:tab>
                        <flux:tab name="messages">
                            {{ __('debug.detail.tab_messages', ['count' => $selected->messages->count()]) }}
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
                                        <div class="text-[10px] uppercase tracking-wide text-zinc-500 mb-1">{{ __('debug.detail.prompt') }}</div>
                                        <pre class="text-xs whitespace-pre-wrap font-mono text-zinc-700 dark:text-zinc-300 max-h-80 overflow-auto">{{ $plog->prompt }}</pre>
                                    </div>
                                    <div class="p-3">
                                        <div class="text-[10px] uppercase tracking-wide text-zinc-500 mb-1">{{ __('debug.detail.response') }}</div>
                                        <pre class="text-xs whitespace-pre-wrap font-mono text-zinc-700 dark:text-zinc-300 max-h-80 overflow-auto">{{ $plog->response }}</pre>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <p class="text-xs text-zinc-400 py-4 text-center font-mono">{{ __('debug.detail.no_prompts') }}</p>
                        @endforelse
                    </flux:tab.panel>

                    <flux:tab.panel name="messages">
                        @forelse ($selected->messages as $msg)
                            <div wire:key="dmsg-{{ $msg->id }}" class="rounded border border-zinc-200 dark:border-zinc-700 mb-2 p-3 bg-zinc-50 dark:bg-zinc-900/40">
                                <div class="flex items-center gap-2 text-xs mb-1">
                                    <span class="font-semibold text-zinc-700 dark:text-zinc-300">
                                        {{ $msg->expert?->name ?? __('debug.detail.system_sender') }}
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
                            <p class="text-xs text-zinc-400 py-4 text-center font-mono">{{ __('debug.detail.no_messages') }}</p>
                        @endforelse
                    </flux:tab.panel>
                </flux:tab.group>
            </div>
        @endif
    </div>
</section>
