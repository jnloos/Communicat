@props([
    'values', {{-- Collection<TagValue> with category loaded --}}
])

@if ($values->isNotEmpty())
    <div {{ $attributes->class('flex flex-wrap gap-1') }}>
        @foreach ($values->sortBy(fn ($value) => [$value->category->position, $value->category->id]) as $value)
            <flux:badge size="sm" color="zinc"><span class="text-zinc-400">{{ $value->category->name }}:</span>&nbsp;{{ $value->name }}</flux:badge>
        @endforeach
    </div>
@endif
