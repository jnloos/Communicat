@props([
    // 'flyout' for the full-height variants (p-5!/sm:p-8!), 'dialog' for the
    // centred ones, which keep Flux's own p-6.
    'padding' => 'flyout',
])

@php
    // A footer has to sit flush against the modal's edge, so it cancels the
    // modal's padding with negative margins and brings its own back.
    $edges = $padding === 'dialog'
        ? '-mx-6 -mb-6 px-6 py-4'
        : '-mx-5 -mb-5 px-5 py-4 sm:-mx-8 sm:-mb-8 sm:px-8';
@endphp

{{--
    The band that closes a modal, carrying only the actions that end it:
    cancel and confirm, never more than two. Anything a modal does to its
    object — exporting, deleting — belongs in the overflow menu in its header,
    which is what keeps this footer identical everywhere.

    mt-auto drops it to the floor wherever the modal is a flex column (see
    edit-project) and is inert elsewhere — a centred modal is only as tall as
    its content anyway.

    The top border is the reason this is a component: on its own, mid-form, such
    a line is indistinguishable from an accordion item's border. Closing a
    footer is what it is for, and that reading only holds if every modal uses it
    the same way.
--}}
<div {{ $attributes->class([
    'mt-auto flex flex-col gap-2 border-t border-zinc-200 bg-zinc-50',
    'sm:flex-row sm:items-center sm:justify-end',
    'dark:border-zinc-700 dark:bg-zinc-800/50',
    $edges,
]) }}>
    {{ $slot }}
</div>
