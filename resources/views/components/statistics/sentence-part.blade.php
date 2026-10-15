@props([
    'connector' => null, {{-- the word that introduces this part ("of", "in", "compared by"); none for the first --}}
])

{{-- One part of the question's sentence: its connector word and the trigger
     after it travel together and break onto the next line only as a whole. --}}
<span {{ $attributes->class('inline-flex max-w-full shrink-0 items-center gap-1.5 whitespace-nowrap [&>*]:min-w-0') }}>
    @if ($connector !== null)
        <span class="text-zinc-500 dark:text-zinc-400">{{ $connector }}</span>
    @endif
    {{ $slot }}
</span>
