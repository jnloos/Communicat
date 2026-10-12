{{-- A section heading of the question list ("Templates", "Questions"): small
     and uppercase, like the sidebar's "Projects" label. --}}
<h3 {{ $attributes->class('text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400') }}>{{ $slot }}</h3>
