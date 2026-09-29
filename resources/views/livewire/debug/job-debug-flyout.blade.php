<div>
    <flux:modal :name="\App\Livewire\Debug\JobDebugFlyout::MODAL" variant="flyout" class="flex w-full flex-col p-5! sm:p-8! md:w-[840px]">
        <x-modal-header :heading="__('debug.title')" :subheading="__('debug.subheading')" />

        <div class="mt-6 flex-1 overflow-y-auto">
            <x-debug.job-report :logs="$logs" :selected="$selected" :selected-job-id="$selectedJobId" />
        </div>
    </flux:modal>
</div>
