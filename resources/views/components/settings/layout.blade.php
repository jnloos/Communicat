@props([
    'heading' => '',
    'subheading' => '',
    'wide' => false, {{-- tables need more room than single-column forms --}}
])

@php
    // A Livewire update (switching the Tags scope, saving a form) re-renders
    // this layout from /livewire/update, where routeIs() no longer knows the
    // page; the path the component was loaded on still does.
    $pagePath = '/'.ltrim(\Livewire\Livewire::originalPath(), '/');
    $isCurrent = fn (string $route): bool => request()->routeIs($route) || parse_url(route($route), PHP_URL_PATH) === $pagePath;
@endphp

<div class="flex items-start max-md:flex-col">
    {{-- Mobile: compact horizontal tabs instead of a tall vertical list --}}
    <div class="-mx-2 mb-6 w-full md:hidden">
        <flux:navbar scrollable class="[scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            <flux:navbar.item :href="route('settings.profile')" :current="$isCurrent('settings.profile')" wire:navigate>{{ __('settings.nav.profile') }}</flux:navbar.item>
            <flux:navbar.item :href="route('settings.password')" :current="$isCurrent('settings.password')" wire:navigate>{{ __('settings.nav.password') }}</flux:navbar.item>
            <flux:navbar.item :href="route('settings.appearance')" :current="$isCurrent('settings.appearance')" wire:navigate>{{ __('settings.nav.appearance') }}</flux:navbar.item>
            @if(auth()->user()?->is_admin)
                <flux:navbar.item :href="route('settings.users')" :current="$isCurrent('settings.users')" wire:navigate>{{ __('settings.nav.users') }}</flux:navbar.item>
                <flux:navbar.item :href="route('settings.tags')" :current="$isCurrent('settings.tags')" wire:navigate>{{ __('settings.nav.tags') }}</flux:navbar.item>
                <flux:navbar.item :href="route('settings.transfer')" :current="$isCurrent('settings.transfer')" wire:navigate>{{ __('settings.nav.transfer') }}</flux:navbar.item>
            @endif
        </flux:navbar>
    </div>

    <div class="me-10 hidden w-[220px] shrink-0 md:block">
        <flux:navlist>
            <flux:navlist.item :href="route('settings.profile')" :current="$isCurrent('settings.profile')" wire:navigate>{{ __('settings.nav.profile') }}</flux:navlist.item>
            <flux:navlist.item :href="route('settings.password')" :current="$isCurrent('settings.password')" wire:navigate>{{ __('settings.nav.password') }}</flux:navlist.item>
            <flux:navlist.item :href="route('settings.appearance')" :current="$isCurrent('settings.appearance')" wire:navigate>{{ __('settings.nav.appearance') }}</flux:navlist.item>
            @if(auth()->user()?->is_admin)
                <flux:navlist.item :href="route('settings.users')" :current="$isCurrent('settings.users')" wire:navigate>{{ __('settings.nav.users') }}</flux:navlist.item>
                <flux:navlist.item :href="route('settings.tags')" :current="$isCurrent('settings.tags')" wire:navigate>{{ __('settings.nav.tags') }}</flux:navlist.item>
                <flux:navlist.item :href="route('settings.transfer')" :current="$isCurrent('settings.transfer')" wire:navigate>{{ __('settings.nav.transfer') }}</flux:navlist.item>
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
