@props([
    'title' => 'Laravel'
])

@use(App\Models\Project)

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky stashable class="border-r border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 w-[15vw] min-w-[15vw]">
            <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

            <a href="{{ route('dashboard') }}" class="me-5 flex items-center space-x-2 rtl:space-x-reverse" wire:navigate>
                <x-app-logo />
            </a>

            <flux:navlist variant="outline">
                <flux:navlist.group class="grid mt-5">
                    <flux:navlist.item icon="squares-plus" :href="route('project.new')" :current="request()->routeIs('project.new')" wire:navigate>{{ __('New Project') }}</flux:navlist.item>
                    <flux:navlist.item icon="user-circle" :href="route('experts')" :current="request()->routeIs('experts')" wire:navigate>{{ __('Edit Experts') }}</flux:navlist.item>
                </flux:navlist.group>

                @php
                    $projects = Project::whereHas('contributors', function ($q) {
                        $q->where('user_id', auth()->id());
                    })->orderBy('updated_at', 'desc')->get()
                @endphp
                @if ($projects->isNotEmpty())
                    <flux:navlist.group :heading="__('Projects')" class="grid mt-5">
                        @foreach ($projects as $project)
                            @php($isCurr = request()->routeIs('project.show') && request()->route('project')->id == $project->id)
                            <flux:navlist.item :href="route('project.show', $project)" :current="$isCurr" wire:navigate>{{ $project->title }}</flux:navlist.item>
                        @endforeach
                    </flux:navlist.group>
                @endif
            </flux:navlist>

            <flux:spacer />

            <flux:navlist variant="outline">
                <flux:navlist.item icon="folder-git-2" href="https://gitlab.uni-trier.de/s4jaloos/specificat" target="_blank">
                    {{ __('Repository') }}
                </flux:navlist.item>

                <flux:navlist.item icon="cog" :href="route('settings.profile')" wire:navigate>
                    {{ __('Settings') }}
                </flux:navlist.item>

                <form method="POST" action="{{ route('logout') }}" class="w-full">
                    @csrf
                    <flux:navlist.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                        {{ __('Log Out') }}
                    </flux:navlist.item>
                </form>
            </flux:navlist>
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile :initials="auth()->user()->initials()" icon-trailing="chevron-down"/>
                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <span class="relative flex h-8 w-8 shrink-0 overflow-hidden rounded-lg">
                                    <span class="flex h-full w-full items-center justify-center rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white">
                                        {{ auth()->user()->initials() }}
                                    </span>
                                </span>
                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                    <span class="truncate text-xs">{{ auth()->user()->email }}</span>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('settings.profile')" icon="cog" wire:navigate>{{ __('Settings') }}</flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                            {{ __('Log Out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @fluxScripts
    </body>
</html>
