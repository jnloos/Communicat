@props([
    'addressed',
])

{{-- "speaks to" hint: a quiet arrow and the addressed expert's name as plain text, no border,
     no avatar (the sender's avatar stays the only one in the row). Display-only; the memory
     flyout opens from the sender avatar. --}}
<span class="inline-flex min-w-0 items-center gap-1 text-zinc-500 dark:text-white/70" data-addressed-expert-id="{{ $addressed->id }}">
    <flux:icon.arrow-long-right variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />
    <span class="sr-only">{{ __('chat.message.addressed') }}</span>
    <span class="truncate font-medium text-zinc-700 dark:text-zinc-200" title="{{ $addressed->name }}">{{ $addressed->name }}</span>
</span>
