@props([
    'heading',
    'subheading' => null,
    'align' => 'start', {{-- how the actions line up with the title block: start, center or end --}}
])

{{--
    The head of every page: the title (the page's only h1), one line that says
    what the page is for, and on the right whatever acts on the whole page.
    The actions wrap under the title on narrow screens.
--}}
<div {{ $attributes->class([
    'flex flex-wrap justify-between gap-3',
    match ($align) {
        'center' => 'items-center',
        'end' => 'items-end',
        default => 'items-start',
    },
]) }}>
    <div>
        <flux:heading size="xl" level="1">{{ $heading }}</flux:heading>

        @if (filled($subheading))
            <flux:text class="mt-1">{{ $subheading }}</flux:text>
        @endif
    </div>

    {{ $actions ?? '' }}
</div>
