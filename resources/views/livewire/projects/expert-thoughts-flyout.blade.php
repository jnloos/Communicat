<div>
    <flux:modal name="expert-thoughts-flyout" variant="flyout" class="w-[560px] max-w-full">
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

                <div class="text-[10px] uppercase tracking-wide text-zinc-500">
                    {{ __('Gedächtnis zur Diskussion') }}
                </div>

                @if (!$thoughts?->content)
                    <div class="rounded-md border border-dashed border-zinc-200 dark:border-zinc-700 p-6 text-center">
                        <p class="text-sm text-zinc-400">
                            {{ __('Noch kein Gedächtnis vorhanden.') }} {{ $expert->name }}
                            {{ __('hat sich bislang keine Notizen gemacht.') }}
                        </p>
                    </div>
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
                                        {{ __('Über den Nutzer') }}
                                    </h4>
                                </header>
                                <p class="px-4 py-3 text-sm whitespace-pre-wrap text-zinc-700 dark:text-zinc-200">{{ $memory['user'] }}</p>
                            </section>
                        @endif

                        @foreach ($memory['experts'] as $name => $note)
                            @php($avatar = $expertAvatars[$name] ?? null)
                            <section class="rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900/40 overflow-hidden">
                                <header class="flex items-center gap-2 px-4 py-2 bg-zinc-100/70 dark:bg-zinc-800/50 border-b border-zinc-200 dark:border-zinc-700">
                                    @if ($avatar)
                                        <button
                                            type="button"
                                            title="{{ __('Gedanken von') }} {{ $name }}"
                                            @click="$dispatch('open-expert-thoughts', { expertId: {{ $avatar['id'] }} })"
                                            class="rounded-full cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
                                        >
                                            <x-contributors.contributors-avatar
                                                :name="$name"
                                                :avatar-url="$avatar['avatar_url']"
                                                class="w-6 h-6"
                                            />
                                        </button>
                                    @else
                                        <x-contributors.contributors-avatar
                                            :name="$name"
                                            class="w-6 h-6"
                                        />
                                    @endif
                                    <h4 class="text-xs font-semibold uppercase tracking-wide text-zinc-600 dark:text-zinc-300">
                                        {{ __('Über') }} {{ $name }}
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
                                        {{ __('Offene Fragen') }}
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
                                        {{ __('Letzter Gesprächsstand') }}
                                    </h4>
                                </header>
                                <p class="px-4 py-3 text-sm whitespace-pre-wrap text-zinc-700 dark:text-zinc-200">{{ $memory['state'] }}</p>
                            </section>
                        @endif
                    </div>
                @else
                    <div class="rounded-md border border-zinc-200 dark:border-zinc-700 p-4 bg-zinc-50 dark:bg-zinc-900/40 space-y-2">
                        <p class="text-[11px] text-zinc-500 italic">
                            {{ __('Format wird beim nächsten Update aktualisiert.') }}
                        </p>
                        <pre class="text-xs whitespace-pre-wrap font-mono text-zinc-700 dark:text-zinc-300 max-h-[60vh] overflow-auto">{{ $memory['raw'] }}</pre>
                    </div>
                @endif
            </div>
        @else
            <p class="text-sm text-zinc-400 py-8 text-center">{{ __('Kein Experte ausgewählt.') }}</p>
        @endif
    </flux:modal>
</div>
