@props([
    'experts' => [],
])

<div>
    <div class="flex items-center justify-between">
        <flux:heading size="xl">{{ __('Edit Experts') }}</flux:heading>
        <flux:button variant="primary" @click="$wire.dispatch('edit_expert')" class="cursor-pointer">
            {{ __('Create Expert') }}
        </flux:button>
    </div>

    <div class="my-5">
        <livewire:experts.expert-editor/>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach ($experts as $expert)
                <x-contributors.contributors-card @click="$wire.dispatch('edit_expert', { id: {{ $expert->id }} })"
                    :name="$expert->name"
                    :job="$expert->job"
                    :avatar-url="$expert->avatar_url ?? null"
                    :description="$expert->description"
                    :seed="$expert->id"
                />
            @endforeach
        </div>
    </div>
</div>
