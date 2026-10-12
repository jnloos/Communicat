@props([
    'value',
    'text',   {{-- the option's name --}}
    'source', {{-- where it comes from --}}
])

{{-- A radio option followed by its source badge, for use inside a flux:radio.group. --}}
<flux:field variant="inline" {{ $attributes }}>
    <flux:radio :$value />
    <flux:label>{{ $text }}</flux:label>
    <x-statistics.source :label="$source" />
</flux:field>
