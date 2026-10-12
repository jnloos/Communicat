@props([
    'title',    {{-- the question's title; null while it has none --}}
    'sentence', {{-- the question in words --}}
])

{{-- A list entry in two lines: the title, then the sentence small and grey. --}}
<span class="block min-w-0">
    <span @class(['block truncate', 'italic text-zinc-500 dark:text-zinc-400' => $title === null])>{{ $title ?? __('questions.untitled') }}</span>
    <span class="line-clamp-2 whitespace-normal text-xs font-normal text-zinc-500 dark:text-zinc-400" title="{{ $sentence }}">{{ $sentence }}</span>
</span>
