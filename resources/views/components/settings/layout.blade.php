@props([
    'heading' => '',
    'subheading' => '',
    'wide' => false, {{-- tables need more room than single-column forms --}}
])

<div class="flex items-start max-md:flex-col">
    {{-- Mobile: compact horizontal tabs instead of a tall vertical list --}}
    <div class="-mx-2 mb-6 w-full md:hidden">
        <flux:navbar scrollable class="[scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            <flux:navbar.item :href="route('settings.profile')" :current="request()->routeIs('settings.profile')" wire:navigate>{{ __('settings.nav.profile') }}</flux:navbar.item>
            <flux:navbar.item :href="route('settings.password')" :current="request()->routeIs('settings.password')" wire:navigate>{{ __('settings.nav.password') }}</flux:navbar.item>
            <flux:navbar.item :href="route('settings.appearance')" :current="request()->routeIs('settings.appearance')" wire:navigate>{{ __('settings.nav.appearance') }}</flux:navbar.item>
            @if(auth()->user()?->is_admin)
                <flux:navbar.item :href="route('settings.users')" :current="request()->routeIs('settings.users')" wire:navigate>{{ __('settings.nav.users') }}</flux:navbar.item>
            @endif
        </flux:navbar>
    </div>

    <div class="me-10 hidden w-[220px] shrink-0 md:block">
        <flux:navlist>
            <flux:navlist.item :href="route('settings.profile')" :current="request()->routeIs('settings.profile')" wire:navigate>{{ __('settings.nav.profile') }}</flux:navlist.item>
            <flux:navlist.item :href="route('settings.password')" :current="request()->routeIs('settings.password')" wire:navigate>{{ __('settings.nav.password') }}</flux:navlist.item>
            <flux:navlist.item :href="route('settings.appearance')" :current="request()->routeIs('settings.appearance')" wire:navigate>{{ __('settings.nav.appearance') }}</flux:navlist.item>
            @if(auth()->user()?->is_admin)
                <flux:navlist.item :href="route('settings.users')" :current="request()->routeIs('settings.users')" wire:navigate>{{ __('settings.nav.users') }}</flux:navlist.item>
            @endif
        </flux:navlist>
    </div>

    <div class="w-full min-w-0 flex-1 self-stretch">
        <flux:heading size="lg">{{ $heading }}</flux:heading>
        <flux:subheading>{{ $subheading }}</flux:subheading>

        <div @class(['mt-5 w-full', 'max-w-lg' => ! $wide, 'max-w-3xl' => $wide])>
            {{ $slot }}
        </div>
    </div>
</div>
