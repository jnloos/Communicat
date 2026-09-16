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
        <flux:sidebar sticky stashable class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <a href="{{ route('dashboard') }}" class="ms-1 flex min-w-0 items-center space-x-2 rtl:space-x-reverse" wire:navigate>
                    <x-app-logo />
                </a>
                <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.item icon="squares-plus" :href="route('project.new')" :current="request()->routeIs('project.new')" wire:navigate>{{ __('navigation.new_project') }}</flux:sidebar.item>
                <flux:sidebar.item icon="user-circle" :href="route('experts')" :current="request()->routeIs('experts')" wire:navigate>{{ __('navigation.edit_experts') }}</flux:sidebar.item>
            </flux:sidebar.nav>

            @php
                $projects = Project::whereHas('users', function ($q) {
                    $q->where('users.id', auth()->id());
                })->orderBy('updated_at', 'desc')->get()
            @endphp

            @if ($projects->isNotEmpty())
                <div class="-mx-2 min-h-0 flex-1 overflow-y-auto px-2">
                    <flux:sidebar.group :heading="__('navigation.projects')">
                        @foreach ($projects as $project)
                            @php($isCurr = request()->routeIs('project.show') && request()->route('project')->id == $project->id)
                            <flux:sidebar.item :href="route('project.show', $project)" :current="$isCurr" :title="$project->title" wire:navigate>{{ $project->title }}</flux:sidebar.item>
                        @endforeach
                    </flux:sidebar.group>
                </div>
            @else
                <flux:sidebar.spacer />
            @endif

            <flux:sidebar.nav>
                <flux:sidebar.item icon="folder-git-2" href="https://github.com/jnloos/Communicat" target="_blank">
                    {{ __('navigation.repository') }}
                </flux:sidebar.item>

                @if (config('app.debug'))
                    <flux:sidebar.item
                        icon="bug-ant"
                        :href="route('debug.jobs', array_filter(['project' => request()->route('project')?->id]))"
                        :current="request()->routeIs('debug.jobs')"
                        wire:navigate
                    >
                        {{ __('navigation.job_debug') }}
                    </flux:sidebar.item>
                @endif

                <flux:sidebar.item icon="cog" :href="route('settings.profile')" :current="request()->routeIs('settings.*')" wire:navigate>
                    {{ __('navigation.settings') }}
                </flux:sidebar.item>

                <form method="POST" action="{{ route('logout') }}" class="w-full">
                    @csrf
                    <flux:sidebar.item as="button" type="submit" icon="arrow-right-start-on-rectangle">
                        {{ __('navigation.log_out') }}
                    </flux:sidebar.item>
                </form>
            </flux:sidebar.nav>
        </flux:sidebar>

        <!-- Mobile Header -->
        <flux:header class="border-b border-zinc-200 bg-zinc-50 lg:hidden dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <a href="{{ route('dashboard') }}" class="ms-2 flex min-w-0 items-center" wire:navigate>
                <x-app-logo />
            </a>

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
                        <flux:menu.item :href="route('settings.profile')" icon="cog" wire:navigate>{{ __('navigation.settings') }}</flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                            {{ __('navigation.log_out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        <x-confirm-modal />
        @fluxScripts
    </body>
</html>
