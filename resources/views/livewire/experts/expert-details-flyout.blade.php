<div>
    <flux:modal name="expert-details-flyout" variant="flyout" class="w-[720px] max-w-full">
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

                @if ($expert->tags->isNotEmpty())
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($expert->tags as $tag)
                            <span class="rounded-full bg-zinc-100 dark:bg-zinc-800 px-2 py-0.5 text-xs text-zinc-600 dark:text-zinc-300">
                                {{ $tag->name }}
                            </span>
                        @endforeach
                    </div>
                @endif

                <div class="space-y-3">
                    @if (filled($expert->description))
                        <section class="rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900/40 overflow-hidden">
                            <header class="px-4 py-2 bg-zinc-100/70 dark:bg-zinc-800/50 border-b border-zinc-200 dark:border-zinc-700">
                                <h4 class="text-xs font-semibold uppercase tracking-wide text-zinc-600 dark:text-zinc-300">
                                    {{ __('Beschreibung') }}
                                </h4>
                            </header>
                            <p class="px-4 py-3 text-sm whitespace-pre-wrap text-zinc-700 dark:text-zinc-200">{{ $expert->description }}</p>
                        </section>
                    @endif

                    @if (filled($expert->profile))
                        <section class="rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900/40 overflow-hidden">
                            <header class="px-4 py-2 bg-zinc-100/70 dark:bg-zinc-800/50 border-b border-zinc-200 dark:border-zinc-700">
                                <h4 class="text-xs font-semibold uppercase tracking-wide text-zinc-600 dark:text-zinc-300">
                                    {{ __('Profil') }}
                                </h4>
                            </header>
                            <p class="px-4 py-3 text-sm whitespace-pre-wrap text-zinc-700 dark:text-zinc-200">{{ $expert->profile }}</p>
                        </section>
                    @endif

                    @if (!empty($expert->core_beliefs))
                        <section class="rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900/40 overflow-hidden">
                            <header class="px-4 py-2 bg-zinc-100/70 dark:bg-zinc-800/50 border-b border-zinc-200 dark:border-zinc-700">
                                <h4 class="text-xs font-semibold uppercase tracking-wide text-zinc-600 dark:text-zinc-300">
                                    {{ __('Kernüberzeugungen') }}
                                </h4>
                            </header>
                            <ul class="px-4 py-3 space-y-1 text-sm text-zinc-700 dark:text-zinc-200 list-disc list-inside">
                                @foreach ($expert->core_beliefs as $belief)
                                    <li>{{ $belief }}</li>
                                @endforeach
                            </ul>
                        </section>
                    @endif

                    @if (!empty($expert->knowledge_limits))
                        <section class="rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900/40 overflow-hidden">
                            <header class="px-4 py-2 bg-zinc-100/70 dark:bg-zinc-800/50 border-b border-zinc-200 dark:border-zinc-700">
                                <h4 class="text-xs font-semibold uppercase tracking-wide text-zinc-600 dark:text-zinc-300">
                                    {{ __('Wissensgrenzen') }}
                                </h4>
                            </header>
                            <ul class="px-4 py-3 space-y-1 text-sm text-zinc-700 dark:text-zinc-200 list-disc list-inside">
                                @foreach ($expert->knowledge_limits as $limit)
                                    <li>{{ $limit }}</li>
                                @endforeach
                            </ul>
                        </section>
                    @endif

                    @if (filled($expert->style))
                        <section class="rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900/40 overflow-hidden">
                            <header class="px-4 py-2 bg-zinc-100/70 dark:bg-zinc-800/50 border-b border-zinc-200 dark:border-zinc-700">
                                <h4 class="text-xs font-semibold uppercase tracking-wide text-zinc-600 dark:text-zinc-300">
                                    {{ __('Stil') }}
                                </h4>
                            </header>
                            <p class="px-4 py-3 text-sm whitespace-pre-wrap text-zinc-700 dark:text-zinc-200">{{ $expert->style }}</p>
                        </section>
                    @endif
                </div>
            </div>
        @else
            <p class="text-sm text-zinc-400 py-8 text-center">{{ __('Kein Experte ausgewählt.') }}</p>
        @endif
    </flux:modal>
</div>
