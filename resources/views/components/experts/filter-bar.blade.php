@props([
    'searchModel' => 'search',
    'tagCategories' => [],
    'activeTagCount' => 0,
])

<div {{ $attributes->merge(['class' => 'flex flex-col gap-3 sm:flex-row']) }}>
    <flux:input
        class="flex-1"
        type="search"
        icon="magnifying-glass"
        :placeholder="__('experts.filter.search_placeholder')"
        wire:model.live.debounce.300ms="{{ $searchModel }}"
    />

    @if (count($tagCategories) > 0)
        <x-tags.filter-dropdown
            wire:key="expert-tag-filter-dropdown"
            :categories="$tagCategories"
            :active-count="$activeTagCount"
            :label="__('experts.filter.button')"
            :active-label="__('experts.filter.button_active', ['count' => $activeTagCount])"
            :merge-description="__('experts.filter.modes.merge')"
            :cut-description="__('experts.filter.modes.cut')"
        >{{ $activeTagCount > 0 ? __('experts.filter.tags_active', ['count' => $activeTagCount]) : __('experts.filter.tags') }}</x-tags.filter-dropdown>
    @endif
</div>
