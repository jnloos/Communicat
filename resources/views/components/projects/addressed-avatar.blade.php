@props([
    'addressed',
    'isExpert' => false,
])

{{-- The entity a message speaks to: an expert (clickable, opens its thoughts
     flyout, with a memory badge) or a user (plain avatar, no flyout).
     Identified by id (FK), name is display-only. --}}
<div
    class="relative group"
    @if ($isExpert) data-addressed-expert-id="{{ $addressed->id }}" @else data-addressed-user-id="{{ $addressed->id }}" @endif
>
    @if ($isExpert)
        <button
            type="button"
            title="{{ __('Angesprochen') }}: {{ $addressed->name }}"
            @click="$dispatch('open-expert-thoughts', { expertId: {{ $addressed->id }} })"
            class="rounded-full cursor-pointer transition-transform hover:scale-105 group-hover:scale-105 group-active:scale-105 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
        >
            <x-contributors.contributors-avatar :name="$addressed->name" :avatar-url="$addressed->avatar_url" class="w-9 h-9 opacity-90"/>
        </button>
        <button
            type="button"
            title="{{ __('Gedächtnis anzeigen') }}"
            @click="$dispatch('open-expert-thoughts', { expertId: {{ $addressed->id }} })"
            class="absolute -bottom-0.5 -right-0.5 inline-flex items-center justify-center
                   w-4 h-4 rounded-full
                   bg-white dark:bg-zinc-800
                   ring-2 ring-white dark:ring-zinc-800
                   text-zinc-500 dark:text-zinc-300
                   hover:text-amber-600 dark:hover:text-amber-400
                   group-hover:text-amber-600 dark:group-hover:text-amber-400
                   cursor-pointer transition-colors
                   focus:outline-none focus-visible:ring-1 focus-visible:ring-amber-400"
            aria-label="{{ __('Gedächtnis anzeigen') }}"
        >
            <x-icons.brain class="w-2.5 h-2.5"/>
        </button>
    @else
        <div title="{{ __('Angesprochen') }}: {{ $addressed->name }}">
            <x-contributors.contributors-avatar :name="$addressed->name" :avatar-url="$addressed->avatar_url ?? null" class="w-9 h-9 opacity-90"/>
        </div>
    @endif
</div>
