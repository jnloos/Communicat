@props([
    'label',  {{-- aria-label of the "…" button --}}
    'rename', {{-- Livewire call for the rename item, e.g. "startRenamingValue(3)" --}}
    'delete', {{-- Livewire call for the delete item; it asks first --}}
    'move',   {{-- Livewire call with %d for the steps, e.g. "moveValue(3, %d)": reordering without dragging --}}
    'first' => false,
    'last' => false,
])

{{-- The "…" menu of one row in Settings → Tags, the pattern of the sidebar
     groups and the saved questions: rename, move up/down (the keyboard and
     touch way to sort, WCAG 2.5.7), and delete as the only red. --}}
<flux:dropdown position="bottom" align="end">
    <flux:button
        variant="ghost"
        size="xs"
        icon="ellipsis-horizontal"
        :aria-label="$label"
        class="text-zinc-400! hover:text-zinc-800! dark:text-zinc-500! dark:hover:text-white!"
    />

    <flux:menu>
        <flux:menu.item icon="pencil-square" wire:click="{{ $rename }}">{{ __('tags.actions.rename') }}</flux:menu.item>
        <flux:menu.separator />
        <x-statistics.move-items :action="$move" :first="$first" :last="$last" />
        <flux:menu.separator />
        <flux:menu.item variant="danger" icon="trash" wire:click="{{ $delete }}">{{ __('tags.actions.delete') }}</flux:menu.item>
    </flux:menu>
</flux:dropdown>
