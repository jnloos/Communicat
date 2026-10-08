{{-- Livewire needs a root element even when there is nothing to choose. --}}
<div>
    @if ($categories->isNotEmpty())
        {{-- Two per row, so a single select does not run across the whole form.
             Category names are stored as typed; only the label is capitalised. --}}
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach ($categories as $category)
                <flux:select wire:key="tag-field-{{ $category->id }}" wire:model="selection.{{ $category->id }}" :label="$category->label()">
                    <option value="">{{ __('tags.fields.unset') }}</option>
                    @foreach ($category->values as $value)
                        <option value="{{ $value->id }}">{{ $value->name }}</option>
                    @endforeach
                </flux:select>
            @endforeach
        </div>
    @endif
</div>
