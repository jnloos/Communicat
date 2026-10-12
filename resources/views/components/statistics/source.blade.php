@props([
    'label', {{-- where the listed item comes from: "Project tag", "Expert tag", "Run setting" --}}
])

{{-- The one look of a source badge on the statistics page. --}}
<flux:badge size="sm" variant="pill" color="zinc" {{ $attributes->class('shrink-0 align-middle') }}>{{ $label }}</flux:badge>
