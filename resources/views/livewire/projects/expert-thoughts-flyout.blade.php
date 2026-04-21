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

                <div class="rounded-md border border-zinc-200 dark:border-zinc-700 p-4 bg-zinc-50 dark:bg-zinc-900/40">
                    <div class="text-[10px] uppercase tracking-wide text-zinc-500 mb-2">
                        Gedächtnis zur Diskussion
                    </div>
                    @if ($thoughts?->content)
                        <pre class="text-xs whitespace-pre-wrap font-mono text-zinc-700 dark:text-zinc-300 max-h-[60vh] overflow-auto">{{ $thoughts->content }}</pre>
                    @else
                        <p class="text-sm text-zinc-400">
                            Noch kein Gedächtnis vorhanden. {{ $expert->name }} hat sich bislang keine Notizen gemacht.
                        </p>
                    @endif
                </div>
            </div>
        @else
            <p class="text-sm text-zinc-400 py-8 text-center">Kein Experte ausgewählt.</p>
        @endif
    </flux:modal>
</div>
