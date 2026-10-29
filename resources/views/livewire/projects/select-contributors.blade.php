<x-flyout name="select-contributors" width="32rem" :flex="false">
    <x-modal-header
        :heading="__('projects.contributors.heading')"
        :subheading="trans_choice('projects.contributors.subheading', $expertLimit)"
    />

    <flux:tab.group class="mt-5 w-full">
        <flux:tabs variant="segmented" class="w-full -mb-5">
            <flux:tab name="experts" selected>{{ __('projects.contributors.tab_experts') }}</flux:tab>
            @if ($mayManage)
                <flux:tab name="users">{{ __('projects.contributors.tab_users') }}</flux:tab>
            @endif
        </flux:tabs>

        <flux:tab.panel name="experts" selected>
            <div class="space-y-4">
                @if($limitWarning)
                    <flux:callout variant="warning" icon="exclamation-triangle" :text="$limitWarning" />
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
                        @php($active = isset($seatedExpertIds[$expert->id]))
                        <x-contributors.contributors-card
                            wire:key="expert-{{ $expert->id }}"
                            :selected="$active"
                            :dimmed="! $active && ! $canAddExpert"
                            :disabled="! $mayManage"
                            :name="$expert->name"
                            :subtitle="$expert->attributeSummary"
                            :avatar-url="$expert->avatar_url"
                            wire:loading.attr="disabled"
                            wire:click="{{ $active ? 'removeExpert' : 'addExpert' }}({{ $expert->id }})"
                        />
                    @endforeach
                @endif
            </div>
        </flux:tab.panel>

        @if ($mayManage)
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
                            @php($active = isset($invitedUserIds[$user->id]))
                            <x-contributors.contributors-card
                                wire:key="user-{{ $user->id }}"
                                :selected="$active"
                                :name="$user->name"
                                :subtitle="$user->email"
                                :avatar-url="$user->avatar_url"
                                wire:loading.attr="disabled"
                                wire:click="{{ $active ? 'removeUser' : 'addUser' }}({{ $user->id }})"
                            />
                        @endforeach
                    @endif
                </div>
            </flux:tab.panel>
        @endif
    </flux:tab.group>

    <x-modal-footer class="sticky bottom-0 z-10 mt-6 dark:bg-zinc-800!">
        <flux:modal.close>
            <flux:button variant="primary">{{ __('common.actions.done') }}</flux:button>
        </flux:modal.close>
    </x-modal-footer>
</x-flyout>
