<div>
    <flux:modal :name="\App\Livewire\Debug\JobDebugFlyout::MODAL" variant="flyout" class="flex w-full flex-col p-5! sm:p-8! md:w-[840px]">
        <x-modal-header :heading="__('debug.title')" :subheading="__('debug.subheading')">
            <x-slot:actions>
                <flux:tooltip :content="$live ? __('debug.live.pause_hint') : __('debug.live.resume_hint')" position="bottom">
                    <flux:button
                        size="sm"
                        variant="ghost"
                        wire:click="togglePause"
                        :icon="$live ? 'pause' : 'play'"
                        class="cursor-pointer"
                        :aria-label="$live ? __('debug.live.pause_hint') : __('debug.live.resume_hint')"
                    />
                </flux:tooltip>
            </x-slot:actions>
        </x-modal-header>

        <div class="mt-6 flex-1 overflow-y-auto">
            <x-debug.job-report :logs="$logs" :selected="$selected" :selected-job-id="$selectedJobId" />
        </div>
    </flux:modal>
</div>
