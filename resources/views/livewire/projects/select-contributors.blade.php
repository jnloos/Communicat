@props([
    'project',
    'experts',
    'users',
    'hasFilters' => false,
    'suggestedIdSet' => [],
    'suggestionReasons' => [],
    'hasSuggestions' => false,
])

<flux:modal name="select-contributors" variant="flyout" class="md:w-[28rem]">
    <flux:heading size="lg">
        {{ __('Choose Contributors') }}
    </flux:heading>
    <flux:spacer/>

    <flux:tab.group class="mt-5 w-full">
        <flux:tabs variant="segmented" class="w-full -mb-5 cursor-pointer">
            <flux:tab name="experts" selected>{{ __('Experts') }}</flux:tab>
            @can('manage-contributors', $project)
                <flux:tab name="users">{{ __('Users') }}</flux:tab>
            @endcan
        </flux:tabs>

        <flux:tab.panel name="experts" selected>
            <div class="space-y-4">
                <div class="flex items-center justify-between gap-2">
                    @if(!$hasSuggestions)
                        <flux:button
                            size="sm"
                            icon="sparkles"
                            wire:click="suggestExperts"
                            wire:loading.attr="disabled"
                            wire:target="suggestExperts"
                            class="cursor-pointer"
                        >
                            <span wire:loading.remove wire:target="suggestExperts">{{ __('Suggest experts') }}</span>
                            <span wire:loading wire:target="suggestExperts">{{ __('Suggesting...') }}</span>
                        </flux:button>
                    @else
                        <div class="flex items-center gap-2">
                            <flux:button
                                size="sm"
                                variant="ghost"
                                icon="arrow-path"
                                wire:click="suggestExperts"
                                wire:loading.attr="disabled"
                                wire:target="suggestExperts"
                                class="cursor-pointer"
                            >
                                <span wire:loading.remove wire:target="suggestExperts">{{ __('Refresh suggestions') }}</span>
                                <span wire:loading wire:target="suggestExperts">{{ __('Suggesting...') }}</span>
                            </flux:button>
                            <flux:button
                                size="sm"
                                variant="ghost"
                                icon="x-mark"
                                wire:click="clearSuggestions"
                                class="cursor-pointer"
                                :title="__('Clear suggestions')"
                            />
                        </div>
                    @endif
                </div>

                @if($suggestionError)
                    <p class="text-xs text-red-500 dark:text-red-400">{{ $suggestionError }}</p>
                @endif

                <x-experts.filter-bar />

                @if($experts->isEmpty())
                    <p class="text-sm text-zinc-500 dark:text-zinc-400 text-center py-6">
                        @if($hasFilters)
                            {{ __('No experts match the current filters.') }}
                        @else
                            {{ __('Keine Einträge') }}
                        @endif
                    </p>
                @else
                    @foreach ($experts as $expert)
                        @php($active = $expert->isContributing($project))
                        @php($isSuggested = isset($suggestedIdSet[$expert->id]))
                        <x-contributors.contributors-card
                            class="cursor-pointer {{ $active ? 'ring-2 ring-primary' : '' }}"
                            :name="$expert->name"
                            :job="$expert->job"
                            :avatar-url="$expert->avatar_url ?? null"
                            :seed="$expert->id"
                            :suggested="$isSuggested"
                            :suggestion-reason="$isSuggested ? ($suggestionReasons[$expert->id] ?? null) : null"
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
                        :placeholder="__('Search users by name or email...')"
                        wire:model.live.debounce.300ms="userSearch"
                    />

                    @if($users->isEmpty())
                        <p class="text-sm text-zinc-500 dark:text-zinc-400 text-center py-6">
                            @if(trim($userSearch) !== '')
                                {{ __('No users match the current filters.') }}
                            @else
                                {{ __('Keine Einträge') }}
                            @endif
                        </p>
                    @else
                        @foreach ($users as $user)
                            @php($active = $project->users()->whereKey($user->id)->exists())
                            <x-contributors.contributors-card
                                class="cursor-pointer {{ $active ? 'ring-2 ring-primary' : '' }}"
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
