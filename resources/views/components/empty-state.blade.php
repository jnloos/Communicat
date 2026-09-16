@props([
    'icon' => null,
])

{{-- The one look for "nothing here yet / no matches" across lists, dialogs and panels. --}}
<div {{ $attributes->merge(['class' => 'flex flex-col items-center gap-2 rounded-xl border border-dashed border-zinc-300 px-6 py-10 text-center text-sm text-zinc-500 dark:border-zinc-600 dark:text-zinc-400']) }}>
    @if ($icon)
        <flux:icon :icon="$icon" class="size-6 text-zinc-400 dark:text-zinc-500" />
    @endif
    <div>{{ $slot }}</div>
</div>
