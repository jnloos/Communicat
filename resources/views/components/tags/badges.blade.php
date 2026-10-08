@props([
    'values', {{-- Collection<TagValue> with category loaded --}}
])

@if ($values->isNotEmpty())
    <div {{ $attributes->class('flex flex-wrap gap-1') }}>
        @foreach ($values->sortBy(fn ($value) => [$value->category->position, $value->category->id]) as $value)
            <flux:badge size="sm" color="zinc" :title="$value->category->name">{{ $value->name }}</flux:badge>
        @endforeach
    </div>
@endif
