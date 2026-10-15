@props([
    'name',               {{-- the Flux modal name the component opens with Flux::modal(...)->show() --}}
    'submit',             {{-- the Livewire action the form submits to --}}
    'model',              {{-- the property the single field is bound to --}}
    'heading',
    'label',              {{-- the field's label --}}
    'subheading' => null,
    'description' => null, {{-- a hint under the field --}}
    'submitLabel' => null, {{-- defaults to "Save" --}}
])

{{--
    A small centred dialog with one text field: creating or renaming a group,
    renaming a project or a question. Inline editing would fight Flux's
    disclosure buttons and link items, so every name is set here, in the house
    modal pattern (x-modal-header, x-modal-footer with cancel and confirm).
--}}
<flux:modal :name="$name" class="md:w-96">
    <form wire:submit.prevent="{{ $submit }}" class="space-y-6">
        <x-modal-header :heading="$heading" :subheading="$subheading" />

        <flux:input wire:model="{{ $model }}" :label="$label" :description="$description" autofocus />

        <x-modal-footer padding="dialog">
            <flux:modal.close>
                <flux:button variant="filled">{{ __('common.actions.cancel') }}</flux:button>
            </flux:modal.close>
            <flux:button type="submit" variant="primary">{{ $submitLabel ?? __('common.actions.save') }}</flux:button>
        </x-modal-footer>
    </form>
</flux:modal>
