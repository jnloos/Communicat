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
        @php
            $label = $activeTagCount > 0
                ? __('experts.filter.button_active', ['count' => $activeTagCount])
                : __('experts.filter.button');
        @endphp

        <flux:dropdown position="bottom" align="end" wire:key="expert-tag-filter-dropdown">
            <flux:button
                icon="funnel"
                :tooltip="$label"
                :data-filter-active="$activeTagCount > 0 ? 'true' : null"
                :class="$activeTagCount > 0 ? 'text-(--color-accent-content)!' : ''"
            >
                {{ $activeTagCount > 0 ? __('experts.filter.tags_active', ['count' => $activeTagCount]) : __('experts.filter.tags') }}
            </flux:button>

            <x-tags.filter-menu
                :categories="$tagCategories"
                :active-count="$activeTagCount"
                :merge-description="__('experts.filter.modes.merge')"
                :cut-description="__('experts.filter.modes.cut')"
            />
        </flux:dropdown>
    @endif
</div>
