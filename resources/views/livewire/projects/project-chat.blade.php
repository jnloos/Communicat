@props([
    'project',
    'messages'
])

<div class="flex h-[calc(100dvh-3.5rem-1px)] flex-col lg:h-dvh">
    <!-- Modals -->
    <livewire:projects.select-contributors :project="$project" />
    <livewire:projects.edit-project :project="$project" />
    <livewire:projects.expert-thoughts-flyout :project="$project" />

    <!-- Project header -->
    <header class="flex shrink-0 items-center gap-2 border-b border-zinc-200 px-4 py-3 sm:gap-3 sm:px-6 dark:border-zinc-700">
        @can('manage-project', $project)
            <flux:tooltip :content="__('chat.header.project_settings')" position="bottom">
                <flux:button
                    variant="subtle"
                    icon="cog-6-tooth"
                    class="shrink-0 cursor-pointer"
                    :aria-label="__('chat.header.project_settings')"
                    @click="$wire.dispatch('edit_project')"
                />
            </flux:tooltip>
        @else
            <flux:tooltip :content="__('chat.header.leave_project')" position="bottom">
                <flux:button
                    variant="subtle"
                    icon="arrow-left-end-on-rectangle"
                    class="shrink-0 cursor-pointer"
                    :aria-label="__('chat.header.leave_project')"
                    wire:click="needsConfirmation('leaveProject')"
                />
            </flux:tooltip>
        @endcan

        <flux:heading size="lg" level="1" class="min-w-0 flex-1 truncate sm:text-xl" :title="$project->title">
            {{ $project->title }}
        </flux:heading>

        <x-projects.contributor-group
            :contributors="$project->experts()->get()->concat($project->users()->whereKeyNot(auth()->id())->get())"
            :label="__('chat.header.set_contributors')"
            @click="$wire.dispatch('select_contributors')"
        />
    </header>

    <!-- Chat -->
    <div id="chat" class="relative min-h-0 flex-1 overflow-y-auto overscroll-contain"
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

                // Keep the pipeline indicator (thinking/typing bubble) in
                // view when it appears or grows, same near-bottom rule.
                window.addEventListener('pipeline_stage_changed', () => {
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
        <div class="mx-auto w-full max-w-3xl space-y-6 px-4 py-6 sm:px-6">
            @foreach ($messages as $msg)
                <x-projects.chat-message :id="$msg->id" :msg="$msg" />
            @endforeach

            <!-- Denkblase / Tipp-Indikator der Generierungs-Pipeline -->
            <x-projects.pipeline-indicator :project="$project" />
        </div>
    </div>

    <!-- Chat Control -->
    <livewire:projects.control-chat :project="$project" />
</div>
