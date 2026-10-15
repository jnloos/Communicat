@props([
    'categories', {{-- Collection<TagCategory> with their values --}}
    'source',     {{-- DimensionSource: where the values come from, named in each heading --}}
    'model',      {{-- the definition path the ticks write to, e.g. "definition.projects.tags"; one list per category id --}}
])

{{-- A menu group of checkboxes per tag category; ticks within a category are
     OR, across categories AND (QuestionDefinition). --}}
@foreach ($categories as $category)
    <flux:menu.group :heading="$source->named($category->label())" wire:key="{{ $model }}-{{ $category->id }}">
        <flux:menu.checkbox.group wire:model.live="{{ $model }}.{{ $category->id }}">
            @foreach ($category->values as $value)
                <flux:menu.checkbox :value="(string) $value->id">{{ $value->name }}</flux:menu.checkbox>
            @endforeach
        </flux:menu.checkbox.group>
    </flux:menu.group>
@endforeach
