@props([
    'label' => null, {{-- aria-label; defaults to "More actions" --}}
    'size' => 'xs',
])

{{--
    The "…" trigger of every overflow menu: sidebar rows, list rows, modal
    headers and the question editor. Muted until hovered, so a column of them
    does not compete with the rows they belong to. Put it first inside a
    flux:dropdown, followed by the flux:menu.
--}}
<flux:button
    variant="ghost"
    :size="$size"
    icon="ellipsis-horizontal"
    :aria-label="$label ?? __('common.actions.more')"
    {{ $attributes->class('text-zinc-400! hover:text-zinc-800! dark:text-zinc-500! dark:hover:text-white!') }}
/>
