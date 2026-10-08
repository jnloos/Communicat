@props([
    'submit',      {{-- Livewire call that creates the entry --}}
    'model',       {{-- property the name is typed into --}}
    'button',      {{-- label of the quiet button that reveals the input --}}
    'placeholder',
    'open' => false, {{-- start revealed, e.g. to show a validation error --}}
])

{{-- A quiet "+ Add …" button that turns into the input, so the tag list reads
     as a list and not as a form. The state is Alpine's: Livewire's morph keeps
     it, so the input stays open after an entry is added, ready for the next. --}}
<div x-data="{ adding: @js((bool) $open) }" {{ $attributes }}>
    <flux:button
        size="sm"
        variant="ghost"
        icon="plus"
        x-show="! adding"
        x-on:click="adding = true; $nextTick(() => $root.querySelector('input')?.focus())"
    >{{ $button }}</flux:button>

    <form x-show="adding" x-cloak wire:submit="{{ $submit }}" x-on:keydown.escape="adding = false" class="flex items-start gap-2">
        <flux:input wire:model="{{ $model }}" size="sm" :placeholder="$placeholder" :aria-label="$placeholder" class="flex-1" />
        <flux:button type="submit" size="sm">{{ __('tags.actions.add') }}</flux:button>
        <flux:button size="sm" variant="ghost" x-on:click="adding = false">{{ __('common.actions.cancel') }}</flux:button>
    </form>
</div>
