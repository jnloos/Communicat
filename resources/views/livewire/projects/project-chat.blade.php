<div class="flex h-[calc(100dvh-3.5rem-1px)] flex-col lg:h-dvh">
    {{-- Modals --}}
    <livewire:projects.select-contributors :project="$this->project" />
    <livewire:projects.edit-project :project="$this->project" />
    <livewire:projects.expert-thoughts-flyout :project="$this->project" />
    <livewire:projects.statistics-flyout :project="$this->project" />
    @if (config('app.debug'))
        <livewire:debug.job-debug-flyout :project="$this->project" />
    @endif

    {{-- Project header --}}
    <header class="flex shrink-0 items-center gap-2 border-b border-zinc-200 px-4 py-3 sm:gap-3 sm:px-6 dark:border-zinc-700">
        @can('update', $this->project)
            <flux:button
                variant="subtle"
                icon="cog-6-tooth"
                class="shrink-0"
                :tooltip="__('chat.header.project_settings')"
                tooltip:position="bottom"
                :aria-label="__('chat.header.project_settings')"
                @click="$wire.dispatch('edit_project')"
            />
        @else
            <flux:button
                variant="subtle"
                icon="arrow-left-end-on-rectangle"
                class="shrink-0"
                :tooltip="__('chat.header.leave_project')"
                tooltip:position="bottom"
                :aria-label="__('chat.header.leave_project')"
                wire:click="confirmLeave"
            />
        @endcan

        <flux:heading size="lg" level="1" class="min-w-0 flex-1 truncate sm:text-xl" :title="$this->project->title">
            {{ $this->project->title }}
        </flux:heading>

        <x-projects.contributor-group
            :contributors="$this->contributors"
            :label="__('chat.header.set_contributors')"
            @click="$wire.dispatch('select_contributors')"
        />
    </header>

    {{-- Chat: scrolling and loading older messages live in Alpine.data('chatScroll') (resources/js/app.js). --}}
    <div
        id="chat"
        class="relative min-h-0 flex-1 overflow-y-auto overscroll-contain"
        x-data="chatScroll"
        data-has-more="{{ $this->hasMore ? 'true' : 'false' }}"
    >
        <div x-show="loading" x-cloak class="flex justify-center py-8" role="status">
            <flux:icon.loading class="size-5 text-zinc-500 dark:text-zinc-400" />
            <span class="sr-only">{{ __('chat.loading_older') }}</span>
        </div>

        {{-- Messages --}}
        <div class="mx-auto w-full max-w-3xl space-y-6 px-4 py-6 sm:px-6">
            @foreach ($this->messages as $msg)
                <x-projects.chat-message :id="$msg->id" :msg="$msg" />
            @endforeach

            {{-- Thinking / typing bubble of the generation pipeline --}}
            <x-projects.pipeline-indicator :project="$this->project" />
        </div>
    </div>

    {{-- Chat control --}}
    <livewire:projects.control-chat :project="$this->project" />
</div>
