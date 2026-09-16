@props([
    'title',
    'flush' => false, {{-- true: page manages its own padding and full-height layout (chat) --}}
])

<x-layouts.app.sidebar :title="$title ?? null">
    <flux:main @class(['p-0! lg:p-0!' => $flush])>
        {{ $slot }}
    </flux:main>
</x-layouts.app.sidebar>
