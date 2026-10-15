@props([
    'entry',             {{-- Question: a template or one of the user's own questions --}}
    'sentence',          {{-- the question in words --}}
    'current' => false,
    'icon' => null,
    'sortable' => false, {{-- the list is sorted by dragging; the menu's move items do the same without a pointer --}}
])

{{--
    One row of the statistics page's lists: the entry opens the question, the
    "…" menu over its right edge (the slot) acts on it. The whole row drags
    where the list sorts; the menu is left out of the drag so it still opens.
--}}
<div class="relative" wire:key="{{ $entry->is_template ? 'template' : 'question' }}-{{ $entry->id }}" @if ($sortable) wire:sort:item="{{ $entry->id }}" @endif>
    <x-statistics.entry :title="$entry->name" :notes="$entry->notes" :sentence="$sentence" :current="$current"
        :icon="$icon" with-menu wire:click="open({{ $entry->id }})" />

    <div class="absolute end-1 top-1.5" wire:sort:ignore>
        <flux:dropdown position="bottom" align="end">
            <x-more-button />
            <flux:menu>
                {{ $slot }}
            </flux:menu>
        </flux:dropdown>
    </div>
</div>
