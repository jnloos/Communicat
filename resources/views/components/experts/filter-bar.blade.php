@props([
    'searchModel' => 'search',
])

<div {{ $attributes->merge(['class' => 'space-y-3']) }}>
    <flux:input
        type="search"
        icon="magnifying-glass"
        :placeholder="__('Search experts by name, job, or description...')"
        wire:model.live.debounce.300ms="{{ $searchModel }}"
    />
</div>
