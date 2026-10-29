@props([
    'name',
    'width',        {{-- the panel's width from md up, any CSS length: "32rem", "720px" --}}
    'flex' => true, {{-- a flex column, so an x-modal-footer drops to the floor and a flex-1 body scrolls --}}
    'guard' => false, {{-- ask before Escape, a click outside or the X drop unsaved changes (see x-unsaved-guard) --}}
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
{{--
    A guarded flyout routes every way out through $store.unsaved: Escape and a
    click outside arrive as the dialog's cancel event (Flux dispatches one for
    the click too), and the X is drawn here instead of by Flux, which closes
    without any event.
--}}
@php
    $guardAttributes = $guard
        ? ['x-on:cancel' => '$store.unsaved.request('.json_encode($name).', $event)']
        : [];
@endphp
<flux:modal :name="$name" variant="flyout" :closable="! $guard" style="--flyout-width: {{ $width }}"
    {{ $attributes->class(['w-full p-5! sm:p-8! md:w-(--flyout-width)', 'flex flex-col' => $flex])->merge($guardAttributes) }}>
    {{ $slot }}

    @if ($guard)
        <div class="absolute top-0 end-0 mt-4 me-4">
            <flux:button variant="ghost" icon="x-mark" size="sm" :aria-label="__('common.actions.close')"
                class="text-zinc-400! hover:text-zinc-800! dark:text-zinc-500! dark:hover:text-white!"
                x-on:click="$store.unsaved.request({{ json_encode($name) }})" />
        </div>
    @endif
</flux:modal>
