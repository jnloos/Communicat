@props([
    'project',
    'experts',
    'users',
    'hasFilters' => false,
    'canAddExpert' => true,
    'expertLimit' => 5,
    'limitWarning' => null,
])

<flux:modal name="select-contributors" variant="flyout" class="w-full p-5! sm:p-8! md:w-[32rem]">
    <div class="space-y-1 pe-8">
        <flux:heading size="lg">{{ __('projects.contributors.heading') }}</flux:heading>
        <flux:text size="sm">{{ trans_choice('projects.contributors.subheading', $expertLimit) }}</flux:text>
    </div>

    <flux:tab.group class="mt-5 w-full">
        <flux:tabs variant="segmented" class="w-full -mb-5 cursor-pointer">
            <flux:tab name="experts" selected>{{ __('projects.contributors.tab_experts') }}</flux:tab>
            @can('manage-contributors', $project)
                <flux:tab name="users">{{ __('projects.contributors.tab_users') }}</flux:tab>
            @endcan
        </flux:tabs>

        <flux:tab.panel name="experts" selected>
            <div class="space-y-4">
                @if($limitWarning)
                    <p class="text-xs rounded-md bg-amber-100 dark:bg-amber-900/30 border border-amber-300 dark:border-amber-700/60 text-amber-800 dark:text-amber-200 px-3 py-2">
                        {{ $limitWarning }}
                    </p>
                @elseif(!$canAddExpert)
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">
                        {{ trans_choice('projects.contributors.limit_reached', $expertLimit) }}
                    </p>
                @endif

                <x-experts.filter-bar />

                @if($experts->isEmpty())
                    <x-empty-state :icon="$hasFilters ? 'magnifying-glass' : 'user-circle'">
                        @if($hasFilters)
                            {{ __('projects.contributors.no_expert_matches') }}
                        @else
                            {{ __('common.no_entries') }}
                        @endif
                    </x-empty-state>
                @else
                    @foreach ($experts as $expert)
                        @php($active = $expert->isContributing($project))
                        @php($limitBlocked = !$active && !$canAddExpert)
                        <x-contributors.contributors-card
                            :selected="$active"
                            :dimmed="$limitBlocked"
                            :name="$expert->name"
                            :job="$expert->job"
                            :avatar-url="$expert->avatar_url ?? null"
                            wire:loading.attr="disabled"
                            wire:click="{{ $active ? 'removeExpert' : 'addExpert' }}({{ $expert->id }})"
                        />
                    @endforeach
                @endif
            </div>
        </flux:tab.panel>

        @can('manage-contributors', $project)
            <flux:tab.panel name="users">
                <div class="space-y-4">
                    <flux:input
                        type="search"
                        icon="magnifying-glass"
                        :placeholder="__('projects.contributors.search_users')"
                        wire:model.live.debounce.300ms="userSearch"
                    />

                    @if($users->isEmpty())
                        <x-empty-state :icon="trim($userSearch) !== '' ? 'magnifying-glass' : 'users'">
                            @if(trim($userSearch) !== '')
                                {{ __('projects.contributors.no_user_matches') }}
                            @else
                                {{ __('common.no_entries') }}
                            @endif
                        </x-empty-state>
                    @else
                        @foreach ($users as $user)
                            @php($active = $project->users()->whereKey($user->id)->exists())
                            <x-contributors.contributors-card
                                :selected="$active"
                                :name="$user->name"
                                :job="$user->email"
                                :avatar-url="$user->avatar_url ?? null"
                                wire:loading.attr="disabled"
                                wire:click="{{ $active ? 'removeUser' : 'addUser' }}({{ $user->id }})"
                            />
                        @endforeach
                    @endif
                </div>
            </flux:tab.panel>
        @endcan
    </flux:tab.group>
</flux:modal>
