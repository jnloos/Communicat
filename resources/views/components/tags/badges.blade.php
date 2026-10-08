@props([
    'values', {{-- Collection<TagValue> with category loaded --}}
])

{{-- One badge per tag: the value, with category and value in the tooltip. A
     yes/no category shows its own name for "true" and nothing for "false"
     (TagValue::badgeLabel()). --}}
@php
    $badges = $values
        ->sortBy(fn ($value) => [$value->category->position, $value->category->id])
        ->filter(fn ($value) => $value->badgeLabel() !== null);
@endphp

@if ($badges->isNotEmpty())
    <div {{ $attributes->class('flex flex-wrap gap-1') }}>
        @foreach ($badges as $value)
            <flux:badge size="sm" color="zinc" icon="tag" title="{{ $value->fullLabel() }}">{{ $value->badgeLabel() }}</flux:badge>
        @endforeach
    </div>
@endif
