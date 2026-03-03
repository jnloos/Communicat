@props([
    'title'
])

<x-layouts.auth.simple :title="$title ?? __('Authentication')">
    {{ $slot }}
</x-layouts.auth.simple>
