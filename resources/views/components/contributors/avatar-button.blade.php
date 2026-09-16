@props([
    'name',
    'avatarUrl' => null,
    'size' => 'md', {{-- xs | md | xl --}}
])

{{--
    The one pattern for a clickable round avatar across the app: neutral ring on
    hover, accent ring on keyboard focus, never a zoom. The corner badge names
    the action (brain = memory, pencil = change avatar), so the same action
    always looks the same.
--}}
@php
    [$avatarClass, $badgeClass] = match ($size) {
        'xs' => ['size-7', 'size-4 -end-1 -bottom-1 [&_svg]:size-2.5'],
        'xl' => ['size-28', 'size-8 end-1 bottom-1 [&_svg]:size-4'],
        default => ['size-9 sm:size-10', 'size-5 -end-1 -bottom-1 [&_svg]:size-3'],
    };
@endphp

<button type="button" {{ $attributes->merge([
    'class' => 'relative inline-flex shrink-0 cursor-pointer rounded-full transition-shadow hover:ring-2 hover:ring-zinc-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent dark:hover:ring-zinc-500',
]) }}>
    <x-contributors.contributors-avatar :name="$name" :avatar-url="$avatarUrl" :class="$avatarClass"/>

    @isset($badge)
        <span class="{{ $badgeClass }} absolute inline-flex items-center justify-center rounded-full bg-white text-zinc-500 ring-2 ring-white dark:bg-zinc-800 dark:text-zinc-300 dark:ring-zinc-800" aria-hidden="true">
            {{ $badge }}
        </span>
    @endisset
</button>
