@props([
    'contributors' => [],
    'label' => null,
])

@php
    $contributors = collect($contributors);
    $visible      = $contributors->take(3);
    $remaining    = $contributors->count() - $visible->count();
@endphp

<div class="flex shrink-0 items-center gap-2 sm:gap-3">
    @if ($contributors->isNotEmpty())
        <flux:avatar.group class="hidden sm:flex **:ring-white dark:**:ring-zinc-800">
            @foreach ($visible as $contributor)
                <x-contributors.contributors-avatar :name="$contributor->name" :avatar-url="$contributor->avatar_url" class="size-9"/>
            @endforeach

            @if ($remaining > 0)
                <flux:avatar circle class="size-9 text-xs">+{{ $remaining }}</flux:avatar>
            @endif
        </flux:avatar.group>
    @endif

    <flux:button
        variant="primary"
        icon="user-group"
        {{ $attributes->merge(['type' => 'button']) }}
        class="shrink-0 cursor-pointer"
        :aria-label="$label"
    >
        @if (! is_null($label))
            <span class="hidden sm:inline">{{ $label }}</span>
            @if ($contributors->isNotEmpty())
                <span class="sm:hidden">{{ $contributors->count() }}</span>
            @endif
        @endif
    </flux:button>
</div>
