<div>
    <x-flyout :name="\App\Livewire\Debug\JobDebugFlyout::MODAL" width="840px">
        <x-modal-header :heading="__('debug.title')" :subheading="__('debug.subheading')" />

        {{-- The scrollbar sits in the modal's own padding at its edge, not against the table's last column. --}}
        <div class="-me-5 mt-6 flex-1 overflow-y-auto pe-5 sm:-me-8 sm:pe-8">
            @if ($opened)
                <x-debug.job-report :logs="$logs" :selected="$selected" :selected-job-id="$selectedJobId" />
            @else
                <x-debug.job-report-skeleton />
            @endif
        </div>
    </x-flyout>
</div>
