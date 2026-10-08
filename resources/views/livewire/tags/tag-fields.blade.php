{{-- Livewire needs a root element even when there is nothing to choose. --}}
<div>
    @if ($categories->isNotEmpty())
        <div class="space-y-4">
            @foreach ($categories as $category)
                <flux:select wire:key="tag-field-{{ $category->id }}" wire:model="selection.{{ $category->id }}" :label="$category->name">
                    <option value="">{{ __('tags.fields.unset') }}</option>
                    @foreach ($category->values as $value)
                        <option value="{{ $value->id }}">{{ $value->name }}</option>
                    @endforeach
                </flux:select>
            @endforeach
        </div>
    @endif
</div>
