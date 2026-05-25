@props([
    'addressed',
    'isExpert' => false,
    'flip' => false, {{-- true for right-aligned (own) messages: arrow points left --}}
])

{{-- "speaks to" indicator: an arrow plus the addressed entity's avatar. --}}
<div class="flex items-center gap-2">
    @if ($flip)
        <x-projects.addressed-avatar :addressed="$addressed" :is-expert="$isExpert" />
    @endif

    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-zinc-400 dark:text-zinc-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        @if ($flip)
            <line x1="19" y1="12" x2="5" y2="12"/>
            <polyline points="11 6 5 12 11 18"/>
        @else
            <line x1="5" y1="12" x2="19" y2="12"/>
            <polyline points="13 6 19 12 13 18"/>
        @endif
    </svg>

    @unless ($flip)
        <x-projects.addressed-avatar :addressed="$addressed" :is-expert="$isExpert" />
    @endunless
</div>
