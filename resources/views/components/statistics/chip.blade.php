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
{{-- Flux wraps the label in a bare span; min-w-0 + truncate on it cuts a long label with an ellipsis, the chevron keeps its place, the title carries the whole text. --}}
@php($variant = $muted ? 'ghost' : 'filled')
@php($cut = 'max-w-64 [&>span]:min-w-0 [&>span]:truncate [&>svg]:shrink-0')

@if ($readOnly)
    <flux:button size="sm" :variant="$variant" icon:trailing="chevron-down" disabled :title="$label" :class="$cut" data-statistics-trigger>{{ $label }}</flux:button>
@else
    <flux:dropdown position="bottom" align="start">
        <flux:button size="sm" :variant="$variant" icon:trailing="chevron-down" :title="$label" :class="$cut" data-statistics-trigger>{{ $label }}</flux:button>

        {{ $slot }}
    </flux:dropdown>
@endif
