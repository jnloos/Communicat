@props([
    'modal', {{-- the flyout's name --}}
    'fields', {{-- Livewire property names (dot paths allowed) whose values count as the form --}}
])

{{-- The other half of x-flyout guard: when the flyout opens, the fields' current
     values become the baseline, and $store.unsaved calls the form dirty as soon
     as they differ. Wire state is read, not the inputs, so a deferred
     wire:model counts the moment it is typed. Place it inside the flyout. --}}
<div
    hidden
    x-data
    x-on:modal-show.document="
        if ($event.detail.name === @js($modal)) {
            $nextTick(() => $store.unsaved.track(@js($modal), () => @js($fields).map((field) => $wire.$get(field))));
        }
    "
></div>
