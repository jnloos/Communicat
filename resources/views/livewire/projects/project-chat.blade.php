@props([
    'project',
    'messages'
])

<div class="w-full">
    <!-- Modals -->
    <livewire:projects.select-contributors :project="$project" />
    <livewire:projects.edit-project :project="$project" />

    <!-- Project Management -->
    <div class="flex items-center justify-between">
        <div class="flex">
            <flux:button variant="primary" icon="cog" class="me-3 cursor-pointer" @click="$wire.dispatch('edit_project')"/>
            <flux:heading size="xl" class="my-auto">
                {{ __('Project') . ': ' . $project->title }}
            </flux:heading>
        </div>

        <x-projects.contributor-group :contributors="$project->contributors" :label="__('Set Contributors')" @click="$wire.dispatch('select_contributors')">
            {{ __('Add ') }}
        </x-projects.contributor-group>
    </div>

    <!-- Chat -->
    <div class="py-6">
        <div id="chat" class="relative w-full mx-auto overflow-y-auto marker" style="max-height: 84vh;"
            x-data="{
                loading: false,
                hasMore: @entangle('hasMore'),
                init() {
                    const el    = this.$el;
                    let locked  = false;

                    // Beim ersten Render ganz nach unten scrollen
                    this.$nextTick(() => {
                        el.scrollTop = el.scrollHeight;
                    });

                    const nearTop = () => el.scrollTop <= 50; // kleiner Puffer oben

                    // Scrollback Pagination: ältere Nachrichten laden
                    el.addEventListener('scroll', async () => {
                        if (!locked && this.hasMore && nearTop()) {
                            locked       = true;
                            this.loading = true;
                            const before = el.scrollHeight;

                            await $wire.loadMore();

                            this.$nextTick(() => {
                                // Scroll-Position halten
                                el.scrollTop = el.scrollHeight - before;
                                this.loading = false;
                                locked       = false;
                            });
                        }
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
