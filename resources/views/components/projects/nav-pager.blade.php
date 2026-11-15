@props([
    'paginator',
    'pages', {{-- last page of this list, from ProjectNavigation's one grouped count --}}
    'list', {{-- the list's name, for the screen-reader label --}}
])

{{--
    The prev/next footer of one sidebar list (a drawer or the ungrouped chats).

    Not flux:pagination: its simple variant has no labels on its buttons, no
    page count and a top border a class cannot take off. This is the same idea
    in the sidebar's own parts: two xs subtle square buttons (the funnel's
    size), the "2 / 12" position between them, aligned with the "…" buttons
    at the row's end. A flux:button with wire:click shows its own spinner
    while the page loads, so each click answers where it was made.
--}}
@php
    $page = $paginator->currentPage();
    // A page left behind by a deletion can lie past the count; never show "3 / 2".
    $pages = max($page, $pages);
@endphp

<nav aria-label="{{ __('groups.pager.label', ['list' => $list]) }}" class="flex h-8 items-center justify-end gap-0.5 pe-1">
    <flux:button
        size="xs"
        variant="subtle"
        square
        icon="chevron-left"
        :disabled="$paginator->onFirstPage()"
        wire:click="previousPage('{{ $paginator->getPageName() }}')"
        :aria-label="__('groups.pager.previous')"
    />

    <span class="min-w-10 text-center text-xs tabular-nums text-zinc-500 dark:text-zinc-400">
        <span aria-hidden="true">{{ $page }} / {{ $pages }}</span>
        <span class="sr-only">{{ __('groups.pager.position', ['page' => $page, 'pages' => $pages]) }}</span>
    </span>

    <flux:button
        size="xs"
        variant="subtle"
        square
        icon="chevron-right"
        :disabled="! $paginator->hasMorePages()"
        wire:click="nextPage('{{ $paginator->getPageName() }}')"
        :aria-label="__('groups.pager.next')"
    />
</nav>
