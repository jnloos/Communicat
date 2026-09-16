<div>
    <flux:modal name="expert-thoughts-flyout" variant="flyout" class="w-full p-5! sm:p-8! md:w-[560px]">
        @if ($expert)
            <div class="space-y-4">
                <div class="flex items-center gap-3">
                    <x-contributors.contributors-avatar
                        :name="$expert->name"
                        :avatar-url="$expert->avatar_url"
                        class="w-12 h-12"
                    />
                    <div>
                        <flux:heading size="lg">{{ $expert->name }}</flux:heading>
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $expert->job }}</p>
                    </div>
                </div>

                <div class="text-xs font-medium uppercase tracking-wide text-zinc-500">
                    {{ __('experts.memory.heading') }}
                </div>

                @if (!$thoughts?->content)
                    <x-empty-state icon="light-bulb">
                        {{ __('experts.memory.empty', ['name' => $expert->name]) }}
                    </x-empty-state>
                @elseif ($memory['structured'])
                    <div class="space-y-3 max-h-[70vh] overflow-y-auto pr-1">
                        @if (!empty($memory['user']))
                            <section class="rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900/40 overflow-hidden">
                                <header class="flex items-center gap-2 px-4 py-2 bg-zinc-100/70 dark:bg-zinc-800/50 border-b border-zinc-200 dark:border-zinc-700">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-zinc-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                        <circle cx="12" cy="7" r="4"/>
                                    </svg>
                                    <h4 class="text-xs font-semibold uppercase tracking-wide text-zinc-600 dark:text-zinc-300">
                                        {{ __('experts.memory.about_user') }}
                                    </h4>
                                </header>
                                <p class="px-4 py-3 text-sm whitespace-pre-wrap text-zinc-700 dark:text-zinc-200">{{ $memory['user'] }}</p>
                            </section>
                        @endif

                        @foreach ($memory['users'] as $name => $note)
                            <section class="rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900/40 overflow-hidden">
                                <header class="flex items-center gap-2 px-4 py-2 bg-zinc-100/70 dark:bg-zinc-800/50 border-b border-zinc-200 dark:border-zinc-700">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-zinc-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                        <circle cx="12" cy="7" r="4"/>
                                    </svg>
                                    <h4 class="text-xs font-semibold uppercase tracking-wide text-zinc-600 dark:text-zinc-300">
                                        {{ __('experts.memory.about', ['name' => $name]) }}
                                    </h4>
                                </header>
                                <p class="px-4 py-3 text-sm whitespace-pre-wrap text-zinc-700 dark:text-zinc-200">{{ $note }}</p>
                            </section>
                        @endforeach

                        @foreach ($memory['experts'] as $name => $note)
                            @php($avatar = $expertAvatars[$name] ?? null)
                            <section class="rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900/40 overflow-hidden">
                                <header class="flex items-center gap-2 px-4 py-2 bg-zinc-100/70 dark:bg-zinc-800/50 border-b border-zinc-200 dark:border-zinc-700">
                                    @if ($avatar)
                                        <x-experts.memory-avatar size="xs" :expert-id="$avatar['id']" :name="$name" :avatar-url="$avatar['avatar_url']" />
                                    @else
                                        <x-contributors.contributors-avatar :name="$name" class="size-7"/>
                                    @endif
                                    <h4 class="text-xs font-semibold uppercase tracking-wide text-zinc-600 dark:text-zinc-300">
                                        {{ __('experts.memory.about', ['name' => $name]) }}
                                    </h4>
                                </header>
                                <p class="px-4 py-3 text-sm whitespace-pre-wrap text-zinc-700 dark:text-zinc-200">{{ $note }}</p>
                            </section>
                        @endforeach

                        @if (!empty($memory['open_questions']))
                            <section class="rounded-md border border-amber-200 dark:border-amber-700/50 bg-amber-50 dark:bg-amber-900/20 overflow-hidden">
                                <header class="flex items-center gap-2 px-4 py-2 bg-amber-100/70 dark:bg-amber-900/30 border-b border-amber-200 dark:border-amber-700/50">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-amber-700 dark:text-amber-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <circle cx="12" cy="12" r="10"/>
                                        <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/>
                                        <line x1="12" y1="17" x2="12.01" y2="17"/>
                                    </svg>
                                    <h4 class="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-300">
                                        {{ __('experts.memory.open_questions') }}
                                    </h4>
                                </header>
                                <ul class="px-4 py-3 space-y-1 text-sm text-zinc-700 dark:text-zinc-200 list-disc list-inside">
                                    @foreach ($memory['open_questions'] as $question)
                                        <li>{{ $question }}</li>
                                    @endforeach
                                </ul>
                            </section>
                        @endif

                        @if (!empty($memory['state']))
                            <section class="rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900/40 overflow-hidden">
                                <header class="flex items-center gap-2 px-4 py-2 bg-zinc-100/70 dark:bg-zinc-800/50 border-b border-zinc-200 dark:border-zinc-700">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-zinc-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M12 8v4l3 2"/>
                                        <circle cx="12" cy="12" r="10"/>
                                    </svg>
                                    <h4 class="text-xs font-semibold uppercase tracking-wide text-zinc-600 dark:text-zinc-300">
                                        {{ __('experts.memory.last_state') }}
                                    </h4>
                                </header>
                                <p class="px-4 py-3 text-sm whitespace-pre-wrap text-zinc-700 dark:text-zinc-200">{{ $memory['state'] }}</p>
                            </section>
                        @endif
                    </div>
                @else
                    <div class="rounded-md border border-zinc-200 dark:border-zinc-700 p-4 bg-zinc-50 dark:bg-zinc-900/40 space-y-2">
                        <p class="text-[11px] text-zinc-500 italic">
                            {{ __('experts.memory.legacy_format') }}
                        </p>
                        <pre class="text-xs whitespace-pre-wrap font-mono text-zinc-700 dark:text-zinc-300 max-h-[60vh] overflow-auto">{{ $memory['raw'] }}</pre>
                    </div>
                @endif
            </div>
        @else
            <p class="text-sm text-zinc-400 py-8 text-center">{{ __('experts.memory.none_selected') }}</p>
        @endif
    </flux:modal>
</div>
