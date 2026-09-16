@props([
    'addressed',
    'isExpert' => false,
])

{{-- "speaks to" indicator: arrow plus the addressed participant. Display-only for
     experts and users alike; the memory flyout opens from the sender avatar. --}}
<span class="relative inline-flex min-w-0 items-center gap-1.5" data-addressed-{{ $isExpert ? 'expert' : 'user' }}-id="{{ $addressed->id }}">
    <flux:icon.arrow-long-right variant="micro" class="size-3.5 shrink-0 text-zinc-400 dark:text-zinc-500" aria-hidden="true" />
    <span class="sr-only">{{ __('chat.message.addressed') }}</span>

    <span class="inline-flex max-w-full items-center gap-1.5 rounded-full border border-zinc-200 py-0.5 ps-1 pe-2.5 text-xs text-zinc-600 dark:border-zinc-600 dark:text-zinc-300">
        <x-contributors.contributors-avatar :name="$addressed->name" :avatar-url="$addressed->avatar_url ?? null" class="size-5"/>
        <span class="truncate">{{ $addressed->name }}</span>
    </span>
</span>
