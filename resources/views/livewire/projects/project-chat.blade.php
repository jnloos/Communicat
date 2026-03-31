@props([
    'project',
    'messages'
])

<div class="w-full">
    <!-- Modals -->
    <livewire:projects.select-contributors :project="$project" />
    <livewire:projects.edit-project :project="$project" />

    <!-- Project Management -->
    <div class="flex items-center justify-between flex-wrap gap-2">
        <div class="flex">
            @can('manage-project', $project)
                <flux:button variant="primary" icon="cog" class="me-3 cursor-pointer" @click="$wire.dispatch('edit_project')"/>
            @else
                <flux:button variant="primary" icon="arrow-left-end-on-rectangle" class="me-3 cursor-pointer" wire:click="leaveProject" wire:confirm="{{ __('Are you sure you want to leave this project?') }}"/>
            @endcan
            <flux:heading size="xl" class="my-auto">
                {{ __('Project') . ': ' . $project->title }}
            </flux:heading>
        </div>

        <x-projects.contributor-group :contributors="$project->experts()->get()->concat($project->users()->get())" :label="__('Set Contributors')" @click="$wire.dispatch('select_contributors')">
            {{ __('Add ') }}
        </x-projects.contributor-group>
    </div>

    <!-- Chat -->
    <div class="py-12">
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
            <div class="space-y-8 pb-36 max-w-[1080px] mx-auto">
                @foreach ($messages as $msg)
                    <x-projects.chat-message :id="$msg->id" :msg="$msg" />
                @endforeach
            </div>
        </div>
    </div>

    <!-- Chat Control -->
    <livewire:projects.control-chat :project="$project" />
</div>
