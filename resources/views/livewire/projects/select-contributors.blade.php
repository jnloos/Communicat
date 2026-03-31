@props([
    'project',
    'experts',
    'users'
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
                @if($experts->isEmpty())
                    <p class="text-sm text-zinc-500 dark:text-zinc-400 text-center py-6">Keine Einträge</p>
                @else
                    @foreach ($experts as $expert)
                        @php($active = $expert->isContributing($project))
                        <x-contributors.contributors-card class="cursor-pointer {{ $active ? 'ring-2 ring-primary' : '' }}"
                            :name="$expert->name"
                            :job="$expert->job"
                            :avatar-url="$expert->avatar_url ?? null"
                            :seed="$expert->id"
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
                    @if($users->isEmpty())
                        <p class="text-sm text-zinc-500 dark:text-zinc-400 text-center py-6">Keine Einträge</p>
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
