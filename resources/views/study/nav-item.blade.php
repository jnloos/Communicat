{{-- Thesis study, see docs/study.md. Pulled into the sidebar while STUDY_MODULE is on. --}}
@can('admin')
    <flux:sidebar.item icon="beaker" :href="route('study.progress')" :current="request()->routeIs('study.*')" wire:navigate>
        {{ __('study.progress.nav') }}
    </flux:sidebar.item>
@endcan
