@props([
    'name' => 'John Doe',
    'job' => 'Mannequin',
    'description' => null,
    'avatarUrl' => null,
    'selected' => null, {{-- null: plain card; bool: selectable card with state indicator --}}
    'dimmed' => false,
])

@php $selectable = ! is_null($selected); @endphp

<button type="button"
    @if ($selectable) aria-pressed="{{ $selected ? 'true' : 'false' }}" @endif
    {{ $attributes->merge([
        'class' => 'group block w-full h-full text-start rounded-xl cursor-pointer transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-accent' . ($dimmed ? ' opacity-50' : '')
    ]) }}>
    <flux:card size="sm" @class([
        'h-full transition-colors group-hover:bg-zinc-50 dark:group-hover:bg-zinc-700/60',
        'border-accent! dark:border-accent!' => $selected,
    ])>
        <div class="flex items-center gap-3">
            <x-contributors.contributors-avatar :name="$name" :avatar-url="$avatarUrl" class="size-10 shrink-0 sm:size-12"/>

            <div class="min-w-0 flex-1">
                <div class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $name }}</div>
                <div class="line-clamp-2 text-sm text-zinc-500 dark:text-zinc-400">{{ $job }}</div>
            </div>

            @if ($selectable)
                @if ($selected)
                    <flux:icon.check-circle variant="solid" class="size-6 shrink-0 text-accent" aria-hidden="true" />
                @else
                    <flux:icon.plus-circle class="size-6 shrink-0 text-zinc-300 transition-colors group-hover:text-zinc-500 dark:text-zinc-600 dark:group-hover:text-zinc-400" aria-hidden="true" />
                @endif
            @endif
        </div>

        @if (filled($description))
            <p class="mt-3 text-sm leading-relaxed text-zinc-500 dark:text-zinc-400">{{ $description }}</p>
        @endif
    </flux:card>
</button>
