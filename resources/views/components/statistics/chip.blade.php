@props([
    'label',          {{-- what the sentence says for this part --}}
    'muted' => false, {{-- the part filters nothing ("everyone", "all runs", "nothing") --}}
])

{{-- One part of the question's sentence: the words as a button, its controls in a popover. --}}
<flux:dropdown position="bottom" align="start" class="inline-block">
    <button type="button" @class([
        'inline-flex max-w-full items-center gap-1 rounded-md border px-2 py-0.5 align-baseline text-sm',
        'border-[var(--color-accent)] bg-[color-mix(in_oklab,var(--color-accent)_12%,transparent)] text-zinc-900 dark:text-white' => ! $muted,
        'border-dashed border-zinc-300 text-zinc-600 dark:border-zinc-600 dark:text-zinc-300' => $muted,
    ])>
        <span class="truncate">{{ $label }}</span>
        <flux:icon.chevron-down variant="micro" class="shrink-0" />
    </button>

    <flux:popover class="w-80 max-w-[calc(100vw-2rem)] space-y-4">
        {{ $slot }}
    </flux:popover>
</flux:dropdown>
