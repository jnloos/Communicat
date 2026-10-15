@props([
    'name',
    'width',        {{-- the panel's width from md up, any CSS length: "32rem", "720px" --}}
    'flex' => true, {{-- a flex column, so an x-modal-footer drops to the floor and a flex-1 body scrolls --}}
])

{{--
    The full-height panel that slides in from the right: one width per use,
    the same padding everywhere. That padding is the other half of
    x-modal-footer padding="flyout" (and of any body that bleeds into it, as
    the debug flyout's scroll area does): the footer cancels p-5/sm:p-8 with
    negative margins, so the two change together or not at all.

    The width travels as a custom property, not as a class built from the
    prop, because Tailwind only generates classes it finds written out.
--}}
<flux:modal :name="$name" variant="flyout" style="--flyout-width: {{ $width }}"
    {{ $attributes->class(['w-full p-5! sm:p-8! md:w-(--flyout-width)', 'flex flex-col' => $flex]) }}>
    {{ $slot }}
</flux:modal>
