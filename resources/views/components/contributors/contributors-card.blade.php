@props([
    'name' => 'John Doe',
    'job' => 'Mannequin',
    'description' => null,
    'avatarUrl' => null,
    'suggested' => false,
    'suggestionReason' => null,
])

@php use Illuminate\Support\Str; @endphp

<button type="button"
    {{ $attributes->merge([
        'class' => 'w-full h-full text-left rounded-xl transition hover:bg-zinc-50 dark:hover:bg-zinc-700 focus:outline-none cursor-pointer active:scale-[.98]'
    ]) }}>
    <flux:card size="sm" class="h-full relative">
        @if($suggested)
            <div class="absolute top-2 end-2">
                <flux:badge color="blue" size="sm" icon="sparkles">{{ __('Suggested') }}</flux:badge>
            </div>
        @endif
        <div class="flex flex-col justify-between p-2">
            <div class="flex items-center gap-4">
                <x-contributors.contributors-avatar :name="$name" :avatar-url="$avatarUrl" class="h-16 w-16"/>
                <div>
                    <div class="text-sm font-medium text-zinc-900 dark:text-white">
                        {{ $name }}
                    </div>
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">
                        {{ $job }}
                    </div>
                </div>
            </div>
            <div class="h-full"/>

            @if($suggested && !empty($suggestionReason))
                <div class="mt-3 text-xs italic text-blue-600 dark:text-blue-400">
                    {{ $suggestionReason }}
                </div>
            @endif

            @if(isset($description))
                <div class="mt-4 text-sm text-zinc-500 dark:text-zinc-400">
                    {{ $description }}
                </div>
            @endif
        </div>
    </flux:card>
</button>

