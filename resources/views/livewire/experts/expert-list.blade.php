@props([
    'experts' => [],
    'hasFilters' => false,
])

<div class="mx-auto w-full max-w-6xl">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="xl" level="1">{{ __('experts.list.heading') }}</flux:heading>
            <flux:text class="mt-1">{{ trans_choice('experts.list.count', $experts->count()) }}</flux:text>
        </div>
        <flux:button variant="primary" icon="plus" @click="$wire.dispatch('edit_expert')" class="cursor-pointer">
            {{ __('experts.list.create') }}
        </flux:button>
    </div>

    <div class="my-6 space-y-5">
        <livewire:experts.expert-editor/>

        <x-experts.filter-bar />

        @if($experts->isEmpty())
            <x-empty-state :icon="$hasFilters ? 'magnifying-glass' : 'user-circle'">
                @if($hasFilters)
                    {{ __('experts.list.no_matches') }}
                @else
                    {{ __('experts.list.empty') }}
                @endif
            </x-empty-state>
        @else
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($experts as $expert)
                    <x-contributors.contributors-card
                        wire:key="expert-{{ $expert->id }}"
                        @click="$wire.dispatch('edit_expert', { id: {{ $expert->id }} })"
                        :name="$expert->name"
                        :job="$expert->job"
                        :avatar-url="$expert->avatar_url ?? null"
                        :description="Str::limit($expert->description, 140)"
                    />
                @endforeach
            </div>
        @endif
    </div>
</div>
