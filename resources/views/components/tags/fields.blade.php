@props([
    'categories', {{-- Collection<TagCategory> of one scope, values loaded --}}
    'model' => 'tagSelection', {{-- the parent's property: category id => value id, '' for not set --}}
])

{{-- One select per category, bound straight to the parent form's property:
     the form loads it with tagSelection() and stores it in its own save()
     with syncTags(), so the tags travel in the same request, and under the
     same authorization, as every other field. Two per row, so a single select
     does not run across the whole form. Category names are stored as typed;
     only the label is capitalised. --}}
@if ($categories->isNotEmpty())
    <div class="grid gap-4 sm:grid-cols-2">
        @foreach ($categories as $category)
            <flux:select wire:key="tag-field-{{ $category->id }}" wire:model="{{ $model }}.{{ $category->id }}" :label="$category->label()">
                <option value="">{{ __('tags.fields.unset') }}</option>
                @foreach ($category->values as $value)
                    <option value="{{ $value->id }}">{{ $value->name }}</option>
                @endforeach
            </flux:select>
        @endforeach
    </div>
@endif
