@props([
    'title',    {{-- the question's title; null while it has none --}}
    'sentence', {{-- the question in words --}}
    'notes' => null, {{-- the researcher's notes; their first line replaces the sentence in the list --}}
    'current' => false,
    'icon' => null,
    'withMenu' => false, {{-- a "…" menu sits at the end of the row and needs its room --}}
])

{{-- A list entry of auto height: the title on up to two lines, so titles that share a long start stay apart, then the notes' first line or the sentence on one line, small and grey. --}}
@php($firstNote = filled($notes) ? trim(strtok($notes, "\n")) : null)
<button type="button" @if ($current) aria-current="page" @endif title="{{ collect([$title ?? __('questions.untitled'), $notes, $sentence])->filter()->implode("\n") }}"
    {{ $attributes->class(['block w-full rounded-lg px-3 py-1.5 text-start hover:bg-zinc-800/5 dark:hover:bg-white/[7%]', 'pe-8' => $withMenu, 'bg-zinc-800/5 dark:bg-white/[7%]' => $current]) }}>
    <span @class(['flex items-start gap-2 text-sm font-medium', 'text-(--color-accent-content)' => $current, 'italic text-zinc-500 dark:text-zinc-400' => $title === null && ! $current])>
        @if ($icon)
            <flux:icon :name="$icon" class="mt-0.5 size-4 shrink-0" />
        @endif
        <span class="line-clamp-2 min-w-0 break-words">{{ $title ?? __('questions.untitled') }}</span>
    </span>
    <span class="line-clamp-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $firstNote ?: $sentence }}</span>
</button>
