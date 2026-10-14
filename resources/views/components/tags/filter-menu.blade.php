@props([
    'categories',
    'activeCount' => 0,
    'mergeDescription' => '',
    'cutDescription' => '',
])

{{-- The tag filter's menu, shared by the sidebar and the experts page; each
     keeps its own trigger. The host component uses FiltersByTags. A plain
     flux:menu with keep-open, so ticking a value or switching the mode
     re-renders the component without closing it. That only holds while
     Livewire morphs the same element instead of replacing it, hence the fixed
     wire:key (and one on the dropdown around it, set by the trigger). --}}
<flux:menu keep-open wire:key="tag-filter-menu" {{ $attributes }}>
    {{-- Each mode explains itself in its tooltip. The values are the trait's
         FILTER_MERGE and FILTER_CUT; a trait constant cannot be read through
         the trait itself, so they are spelled out here. --}}
    <flux:menu.radio.group wire:model.live="tagFilterMode">
        <flux:menu.radio :value="'merge'" :title="$mergeDescription">{{ __('tags.filter.modes.merge.label') }}</flux:menu.radio>
        <flux:menu.radio :value="'cut'" :title="$cutDescription">{{ __('tags.filter.modes.cut.label') }}</flux:menu.radio>
    </flux:menu.radio.group>

    {{-- One group over every category: Flux's checkbox group collects every
         menu checkbox below it, headings and separators in between or not, so
         all values write the one flat $tagFilter list. --}}
    <flux:menu.checkbox.group wire:model.live="tagFilter">
        @foreach ($categories as $category)
            <flux:menu.separator />
            <flux:menu.heading>{{ $category->label() }}</flux:menu.heading>

            @foreach ($category->values as $value)
                <flux:menu.checkbox :value="(string) $value->id" wire:key="tag-filter-{{ $value->id }}">{{ $value->name }}</flux:menu.checkbox>
            @endforeach
        @endforeach
    </flux:menu.checkbox.group>

    <flux:menu.separator />

    <flux:menu.item icon="x-mark" wire:click="clearTagFilter" :disabled="$activeCount === 0">{{ __('tags.filter.clear_label') }}</flux:menu.item>
</flux:menu>
