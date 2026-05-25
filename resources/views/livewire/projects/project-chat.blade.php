@props([
    'project',
    'messages'
])

<div
    class="w-full"
    x-data="{
        mode: 'text',
        init() {
            this.$store.discussionMode.initForProject('{{ $project->id }}');
            this.mode = this.$store.discussionMode.value;

            this.$watch('$store.discussionMode.value', (value) => {
                this.mode = value;
            });
        },
        setMode(value) {
            this.$store.discussionMode.setMode(value);
        }
    }"
>
    <!-- Modals -->
    <livewire:projects.select-contributors :project="$project" />
    <livewire:projects.edit-project :project="$project" />
    <livewire:projects.expert-thoughts-flyout :project="$project" />

    <!-- Project Management -->
    <div class="flex items-center gap-2">
        {{-- Left: settings/leave + title (equal flex so the tabs stay centered) --}}
        <div class="flex-1 min-w-0 flex items-center">
            @can('manage-project', $project)
                <flux:tooltip :content="__('Project settings')" position="bottom">
                    <flux:button
                        variant="primary"
                        icon="cog"
                        class="me-3 cursor-pointer shrink-0"
                        :aria-label="__('Project settings')"
                        @click="$wire.dispatch('edit_project')"
                    />
                </flux:tooltip>
            @else
                <flux:tooltip :content="__('Leave project')" position="bottom">
                    <flux:button
                        variant="primary"
                        icon="arrow-left-end-on-rectangle"
                        class="me-3 cursor-pointer shrink-0"
                        :aria-label="__('Leave project')"
                        wire:click="needsConfirmation('leaveProject')"
                    />
                </flux:tooltip>
            @endcan
            <flux:heading size="xl" class="my-auto truncate">
                {{ $project->title }}
            </flux:heading>
        </div>

        {{-- Center: tab selection — always exactly centered --}}
        <flux:radio.group variant="segmented" x-model="mode" x-on:change="setMode(mode)" class="shrink-0">
            <flux:radio value="text" icon="chat-bubble-left-right">{{ __('Text') }}</flux:radio>
            <flux:radio value="voice" icon="microphone">{{ __('Voice') }}</flux:radio>
        </flux:radio.group>

        {{-- Right: contributors (equal flex, right-aligned) --}}
        <div class="flex-1 min-w-0 flex justify-end">
            <x-projects.contributor-group :contributors="$project->experts()->get()->concat($project->users()->whereKeyNot(auth()->id())->get())" :label="__('Set Contributors')" @click="$wire.dispatch('select_contributors')">
                {{ __('Add ') }}
            </x-projects.contributor-group>
        </div>
    </div>

    <!-- Chat -->
    <div class="relative py-6" x-show="mode === 'text'">
        <!-- Fade top -->
        <div class="absolute top-6 left-0 right-0 h-2 bg-linear-to-b from-white dark:from-zinc-800 to-transparent z-10 pointer-events-none"></div>
        <div id="chat" class="relative w-full mx-auto overflow-y-auto marker" style="max-height: 84vh;"
            x-data="{
                loading: false,
                hasMore: @entangle('hasMore'),
                scrollThreshold: 300,
                isNearBottom() {
                    const el = this.$el;
                    return el.scrollHeight - el.scrollTop - el.clientHeight <= this.scrollThreshold;
                },
                scrollToBottom() {
                    this.$el.scrollTop = this.$el.scrollHeight;
                },
                init() {
                    const el    = this.$el;
                    let locked  = false;

                    // Beim ersten Render ganz nach unten scrollen
                    this.$nextTick(() => {
                        el.scrollTop = el.scrollHeight;
                    });

                    const nearTop = () => el.scrollTop <= 50;

                    // Scrollback Pagination: ältere Nachrichten laden
                    el.addEventListener('scroll', async () => {
                        if (!locked && this.hasMore && nearTop()) {
                            locked       = true;
                            this.loading = true;
                            const before = el.scrollHeight;

                            await $wire.loadMore();

                            this.$nextTick(() => {
                                el.scrollTop = el.scrollHeight - before;
                                this.loading = false;
                                locked       = false;
                            });
                        }
                    });

                    // Auto-scroll on new message, only if already near the bottom
                    window.addEventListener('message_generated', () => {
                        const wasNearBottom = this.isNearBottom();
                        this.$nextTick(() => {
                            if (wasNearBottom) this.scrollToBottom();
                        });
                    });
                }
            }"
        >
            <!-- Spinner -->
            <div x-show="loading" x-cloak class="flex justify-center py-8">
                <flux:icon.loading class="w-5 h-5 text-gray-500" />
            </div>

            <!-- Nachrichten -->
            <div class="space-y-8 pb-24 max-w-220 mx-auto">
                @foreach ($messages as $msg)
                    <x-projects.chat-message :id="$msg->id" :msg="$msg" />
                @endforeach
            </div>
        </div>
    </div>

    <div x-show="mode === 'voice'" class="py-6">
        <x-projects.voice-stage :project="$project" :messages="$messages" />
    </div>

    <!-- Chat Control -->
    <livewire:projects.control-chat :project="$project" />
</div>
