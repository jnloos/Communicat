{{-- Study-only, removable: see app/Study/README.md. Pulled into the sidebar by @includeIf('study::nav-item'). --}}
@can('admin')
    <flux:sidebar.item icon="beaker" :href="route('study.progress')" :current="request()->routeIs('study.*')" wire:navigate>
        {{ __('study::progress.nav') }}
    </flux:sidebar.item>
@endcan
