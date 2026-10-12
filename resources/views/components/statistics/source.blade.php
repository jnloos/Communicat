@props([
    'label', {{-- where the listed item comes from: "Project tag", "Expert tag", "Run setting" --}}
])

{{--
    The one look of a source badge: square-cornered and outlined, so it never
    reads as one of the rounded, filled pills the user can pick.
--}}
<flux:badge size="sm" color="zinc" {{ $attributes->class('shrink-0 align-middle border border-zinc-300 bg-transparent! py-0! dark:border-zinc-500') }}>{{ $label }}</flux:badge>
