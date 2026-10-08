@props([
    'values', {{-- Collection<TagValue> with category loaded --}}
])

{{-- One badge per tag, always as "category: value" (TagValue::fullLabel()),
     true/false values included. --}}
@php($badges = $values->sortBy(fn ($value) => [$value->category->position, $value->category->id]))

@if ($badges->isNotEmpty())
    <div {{ $attributes->class('flex flex-wrap gap-1') }}>
        @foreach ($badges as $value)
            <flux:badge size="sm" color="zinc" icon="tag">{{ $value->fullLabel() }}</flux:badge>
        @endforeach
    </div>
@endif
