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

    // One list for both navigations, so the mobile tabs and the sidebar cannot drift apart.
    $isAdmin = (bool) auth()->user()?->is_admin;
    $items = collect([
        ['route' => 'settings.profile', 'label' => 'settings.nav.profile', 'admin' => false],
        ['route' => 'settings.password', 'label' => 'settings.nav.password', 'admin' => false],
        ['route' => 'settings.appearance', 'label' => 'settings.nav.appearance', 'admin' => false],
        ['route' => 'settings.users', 'label' => 'settings.nav.users', 'admin' => true],
        ['route' => 'settings.tags', 'label' => 'settings.nav.tags', 'admin' => true],
        ['route' => 'settings.attributes', 'label' => 'settings.nav.attributes', 'admin' => true],
        ['route' => 'settings.transfer', 'label' => 'settings.nav.transfer', 'admin' => true],
    ])->filter(fn (array $item): bool => $isAdmin || ! $item['admin']);
@endphp

{{-- Every settings page shares this frame: the page header, then its own navigation and content. --}}
<section class="mx-auto w-full max-w-6xl">
    <x-page-header :heading="__('settings.heading')" :subheading="__('settings.subheading')" class="mb-6" />

    <div class="flex items-start max-md:flex-col">
        {{-- Mobile: compact horizontal tabs instead of a tall vertical list --}}
        <div class="-mx-2 mb-6 w-full md:hidden">
            <flux:navbar scrollable class="[scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                @foreach ($items as $item)
                    <flux:navbar.item :href="route($item['route'])" :current="$isCurrent($item['route'])" wire:navigate>{{ __($item['label']) }}</flux:navbar.item>
                @endforeach
            </flux:navbar>
        </div>

        <div class="me-10 hidden w-[220px] shrink-0 md:block">
            <flux:navlist>
                @foreach ($items as $item)
                    <flux:navlist.item :href="route($item['route'])" :current="$isCurrent($item['route'])" wire:navigate>{{ __($item['label']) }}</flux:navlist.item>
                @endforeach
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
</section>
