@props([
    'text',   {{-- the group's name --}}
    'source', {{-- where it comes from --}}
])

{{-- A group heading with its source badge. Flux drops markup passed as a label, so it is built here. --}}
<div class="mb-2 flex items-center gap-2">
    <flux:label>{{ $text }}</flux:label>
    <x-statistics.source :label="$source" />
</div>
