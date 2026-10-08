{{-- A section of the comparison ("Series", "View", "Result"): small and
     uppercase, a level below the comparison's name, like the sidebar's
     "Projects" label. --}}
<h3 {{ $attributes->class('text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400') }}>{{ $slot }}</h3>
