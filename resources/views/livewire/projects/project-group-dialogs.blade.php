{{-- Renaming inline would fight Flux's disclosure button and its link item,
     so creating and renaming a group use the house modal pattern instead. --}}
<div>
    <flux:modal name="project-group-create" class="md:w-96">
        <form wire:submit.prevent="createGroup" class="space-y-6">
            <x-modal-header :heading="__('groups.create.heading')" :subheading="__('groups.create.subheading')" />

            <flux:input wire:model.defer="form.name" :label="__('groups.fields.name')" autofocus />

            <x-modal-footer padding="dialog">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('common.actions.cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('groups.create.submit') }}</flux:button>
            </x-modal-footer>
        </form>
    </flux:modal>

    <flux:modal name="project-group-rename" class="md:w-96">
        <form wire:submit.prevent="saveGroupName" class="space-y-6">
            <x-modal-header :heading="__('groups.rename_group.heading')" />

            <flux:input wire:model.defer="form.name" :label="__('groups.fields.name')" autofocus />

            <x-modal-footer padding="dialog">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('common.actions.cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('common.actions.save') }}</flux:button>
            </x-modal-footer>
        </form>
    </flux:modal>
</div>
