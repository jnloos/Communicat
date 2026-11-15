<x-flyout name="select-contributors" width="32rem">
    <x-modal-header
        :heading="__('projects.contributors.heading')"
        :subheading="trans_choice('projects.contributors.subheading', $expertLimit)"
    />

    {{-- Only the lists scroll: header and footer stay put, so the footer never floats over a card.
         The scrollbar sits in the flyout's padding, as in the debug flyout. --}}
    <div class="-me-5 mt-5 min-h-0 flex-1 overflow-y-auto pe-5 sm:-me-8 sm:pe-8">
        <flux:tab.group class="w-full">
            <flux:tabs variant="segmented" class="w-full -mb-5">
                <flux:tab name="experts" selected>{{ __('projects.contributors.tab_experts') }}</flux:tab>
                @if ($mayManage)
                    <flux:tab name="users">{{ __('projects.contributors.tab_users') }}</flux:tab>
                @endif
            </flux:tabs>

            <flux:tab.panel name="experts" selected>
                <div class="space-y-6">
                    @if($limitWarning)
                        <flux:callout variant="warning" icon="exclamation-triangle" :text="$limitWarning" />
                    @endif

                    {{-- Seated experts in seat order: the seat is what the study logs, so it is shown. --}}
                    <section aria-labelledby="contributors-seated-heading" class="space-y-3">
                        <div class="flex items-baseline justify-between gap-3">
                            <flux:heading size="sm" id="contributors-seated-heading">{{ __('projects.contributors.seated_heading') }}</flux:heading>
                            <flux:text size="sm" class="tabular-nums">{{ __('projects.contributors.seated_count', ['count' => $seated->count(), 'max' => $expertLimit]) }}</flux:text>
                        </div>

                        @forelse ($seated as $expert)
                            <x-contributors.contributors-card
                                wire:key="expert-{{ $expert->id }}"
                                :selected="true"
                                :disabled="! $mayManage"
                                :name="$expert->name"
                                :subtitle="$expert->attributeSummary"
                                :avatar-url="$expert->avatar_url"
                                wire:loading.attr="disabled"
                                wire:loading.delay.class="opacity-60"
                                wire:target="addExpert,removeExpert"
                                wire:click="removeExpert({{ $expert->id }})"
                            >
                                <x-slot:badges>
                                    <flux:badge size="sm" color="zinc" class="tabular-nums">{{ __('projects.contributors.seat', ['seat' => $expert->pivot->seat]) }}</flux:badge>
                                </x-slot:badges>
                            </x-contributors.contributors-card>
                        @empty
                            <flux:text size="sm">{{ __('projects.contributors.seated_empty') }}</flux:text>
                        @endforelse
                    </section>

                    <section aria-labelledby="contributors-available-heading" class="space-y-3">
                        <flux:heading size="sm" id="contributors-available-heading">{{ __('projects.contributors.available_heading') }}</flux:heading>

                        {{-- The search filters only this list; the seats above always stay. --}}
                        <x-experts.filter-bar />

                        @if (! $canAddExpert)
                            <flux:text size="sm">{{ trans_choice('projects.contributors.limit_reached', $expertLimit) }}</flux:text>
                        @endif

                        @if ($experts->isEmpty())
                            <x-empty-state :icon="$hasFilters ? 'magnifying-glass' : 'user-circle'">
                                @if($hasFilters)
                                    {{ __('projects.contributors.no_expert_matches') }}
                                @else
                                    {{ __('common.no_entries') }}
                                @endif
                            </x-empty-state>
                        @else
                            @foreach ($experts as $expert)
                                <x-contributors.contributors-card
                                    wire:key="expert-{{ $expert->id }}"
                                    :dimmed="! $canAddExpert"
                                    :disabled="! $mayManage"
                                    :name="$expert->name"
                                    :subtitle="$expert->attributeSummary"
                                    :avatar-url="$expert->avatar_url"
                                    wire:loading.attr="disabled"
                                    wire:loading.delay.class="opacity-60"
                                    wire:target="addExpert,removeExpert,search,gotoPage,nextPage,previousPage"
                                    wire:click="addExpert({{ $expert->id }})"
                                />
                            @endforeach

                            @if ($experts->hasPages())
                                <flux:pagination class="pb-4" :paginator="$experts" />
                            @endif
                        @endif
                    </section>
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
                                    wire:loading.delay.class="opacity-60"
                                    wire:target="addUser,removeUser,userSearch"
                                    wire:click="{{ $active ? 'removeUser' : 'addUser' }}({{ $user->id }})"
                                />
                            @endforeach
                        @endif
                    </div>
                </flux:tab.panel>
            @endif
        </flux:tab.group>
    </div>

    <x-modal-footer class="mt-6">
        <flux:modal.close>
            <flux:button variant="primary">{{ __('common.actions.done') }}</flux:button>
        </flux:modal.close>
    </x-modal-footer>
</x-flyout>
