@props([
    'label',             {{-- what the sentence says for this part, without its source --}}
    'muted' => false,    {{-- the part filters nothing ("everyone", "all runs", "nothing") --}}
    'readOnly' => false, {{-- a template: the same trigger, disabled --}}
])

{{--
    The trigger of one part of the question's sentence: a plain Flux button
    that opens whatever the slot holds, a flux:menu or a flux:popover. A part
    that filters nothing is ghost, one that sets something is filled.
--}}
@php($variant = $muted ? 'ghost' : 'filled')

@if ($readOnly)
    <flux:button size="sm" :variant="$variant" icon:trailing="chevron-down" disabled data-statistics-trigger>{{ $label }}</flux:button>
@else
    <flux:dropdown position="bottom" align="start">
        <flux:button size="sm" :variant="$variant" icon:trailing="chevron-down" data-statistics-trigger>{{ $label }}</flux:button>

        {{ $slot }}
    </flux:dropdown>
@endif
