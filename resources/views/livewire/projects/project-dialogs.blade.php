{{-- Deleting asks through the shared x-confirm-modal; only the rename needs a
     dialog of its own, in the house modal pattern (see project-group-dialogs). --}}
<div>
    <flux:modal name="project-rename" class="md:w-96">
        <form wire:submit.prevent="saveProjectTitle" class="space-y-6">
            <x-modal-header :heading="__('groups.rename_project.heading')" />

            <flux:input wire:model.defer="form.title" :label="__('common.fields.title')" autofocus />

            <x-modal-footer padding="dialog">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('common.actions.cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('common.actions.save') }}</flux:button>
            </x-modal-footer>
        </form>
    </flux:modal>
</div>
