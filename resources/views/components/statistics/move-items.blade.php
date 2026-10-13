@props([
    'action', {{-- the Livewire call with %d where the steps go, e.g. "moveQuestion(4, %d)" --}}
    'first' => false,
    'last' => false,
])

{{-- One place up or down in a sortable list: the way to reorder without dragging. --}}
<flux:menu.item icon="arrow-up" :disabled="$first" wire:click="{{ sprintf($action, -1) }}">{{ __('questions.actions.move_up') }}</flux:menu.item>
<flux:menu.item icon="arrow-down" :disabled="$last" wire:click="{{ sprintf($action, 1) }}">{{ __('questions.actions.move_down') }}</flux:menu.item>
