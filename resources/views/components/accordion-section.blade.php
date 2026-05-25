@props([
    'heading' => '',
    'icon' => null,
    'expanded' => true,
    'errorFields' => [],
])

@php
    $hasError = ! empty($errorFields) && $errors->hasAny((array) $errorFields);
@endphp

<flux:accordion.item :expanded="$expanded" transition>
    <flux:accordion.heading>
        <div @class([
            'flex items-center gap-2',
            'text-red-500 dark:text-red-400' => $hasError,
        ])>
            @if ($icon)
                <flux:icon :name="$icon" class="size-5" />
            @endif
            {{ $heading }}
        </div>
    </flux:accordion.heading>
    <flux:accordion.content class="space-y-4 my-4">
        {{ $slot }}
    </flux:accordion.content>
</flux:accordion.item>
