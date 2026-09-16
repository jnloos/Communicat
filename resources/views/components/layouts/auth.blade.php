@props([
    'title'
])

<x-layouts.auth.simple :title="$title ?? __('login.layout_title')">
    {{ $slot }}
</x-layouts.auth.simple>
