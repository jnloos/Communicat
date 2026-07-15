@props([
    'project',
    'experts',
    'users',
    'hasFilters' => false,
    'suggestedIdSet' => [],
    'suggestionReasons' => [],
    'hasSuggestions' => false,
    'canAddExpert' => true,
    'expertLimit' => 5,
    'limitWarning' => null,
])

<flux:modal name="select-contributors" variant="flyout" class="md:w-[32rem]">
    <flux:heading size="lg">
        {{ __('Choose Contributors') }}
    </flux:heading>
    <flux:spacer/>

    @can('manage-contributors', $project)
        <flux:tab.group class="mt-5 w-full">
            <flux:tabs variant="segmented" class="w-full -mb-5 cursor-pointer">
                <flux:tab name="experts" selected>{{ __('Experts') }}</flux:tab>
                <flux:tab name="users">{{ __('Users') }}</flux:tab>
            </flux:tabs>

            <flux:tab.panel name="experts" selected>
                @include('livewire.projects.partials.expert-picker')
            </flux:tab.panel>

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
        </flux:tab.group>
    @else
        {{-- Non-admins have no Users tab; instead of a lone "Experts" tab they
             see the avatars of the currently selected experts. --}}
        <div class="mt-5 space-y-5">
            @php($selectedExperts = $project->experts()->get())
            <div class="flex items-center gap-2 flex-wrap min-h-9">
                @forelse ($selectedExperts as $selected)
                    <x-contributors.contributors-avatar
                        :name="$selected->name"
                        :avatar-url="$selected->avatar_url"
                        title="{{ $selected->name }}"
                        class="w-9 h-9"
                    />
                @empty
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        {{ __('Noch keine Experten ausgewählt.') }}
                    </p>
                @endforelse
            </div>

            @include('livewire.projects.partials.expert-picker')
        </div>
    @endcan
</flux:modal>
