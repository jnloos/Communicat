@props([
    'categories',
    'activeCount' => 0,
    'label',             {{-- tooltip (and, icon only, the accessible name) while nothing is ticked --}}
    'activeLabel',       {{-- the same with the number of ticked values --}}
    'iconOnly' => false, {{-- the sidebar's small solid funnel; otherwise a full button with the slot as its text --}}
    'align' => 'end',
    'mergeDescription' => '',
    'cutDescription' => '',
])

@php
    $active = $activeCount > 0;
    $tooltip = $active ? $activeLabel : $label;
@endphp

{{--
    The tag filter's trigger and menu, shared by the sidebar and the experts
    page. The funnel is the only sign of a filter: always the same icon, in
    the accent colour once a value is ticked (data-filter-active), never a
    badge. The host passes a fixed wire:key, so Livewire morphs the open
    dropdown instead of replacing it (see x-tags.filter-menu).
--}}
<flux:dropdown position="bottom" :align="$align" {{ $attributes }}>
    @if ($iconOnly)
        <flux:button
            size="xs"
            variant="subtle"
            icon="funnel"
            icon:variant="solid"
            :aria-label="$tooltip"
            :tooltip="$tooltip"
            :data-filter-active="$active ? 'true' : null"
            :class="'[&_svg]:size-3.5 '.($active ? 'text-(--color-accent-content)!' : 'text-current!')"
        />
    @else
        <flux:button
            icon="funnel"
            :tooltip="$tooltip"
            :data-filter-active="$active ? 'true' : null"
            :class="$active ? 'text-(--color-accent-content)!' : ''"
        >{{ $slot }}</flux:button>
    @endif

    <x-tags.filter-menu
        :categories="$categories"
        :active-count="$activeCount"
        :merge-description="$mergeDescription"
        :cut-description="$cutDescription"
    />
</flux:dropdown>
