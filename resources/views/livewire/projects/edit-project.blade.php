<flux:modal name="edit-project" variant="flyout" class="md:w-[32rem]">
    <div class="space-y-6">
        <flux:heading size="lg">
            {{ __('Edit Project') }}
        </flux:heading>

        <flux:spacer />

        <form wire:submit.prevent="save" class="space-y-6">
            <flux:input wire:model.defer="title" :label="__('Title')"/>
            <flux:textarea wire:model.defer="description" :label="__('Description')" rows="10"/>

            <flux:select wire:model.defer="frequency" :label="__('Memory Reduction')">
                <option value="5">{{ __('High') }}</option>
                <option value="10">{{ __('Standard') }}</option>
                <option value="20">{{ __('Low') }}</option>
            </flux:select>

            <div class="flex items-center justify-between">
                <flux:button type="button" variant="danger" class="cursor-pointer"
                    wire:click="needsConfirmation('delete')">
                    {{ __('Delete Project') }}
                </flux:button>
                <flux:button type="submit" variant="primary" class="cursor-pointer">
                    {{ __('Update Project') }}
                </flux:button>
            </div>
        </form>

        <flux:separator />

        <flux:button as="a" href="{{ route('project.export.json', $forProjectId) }}"
            icon="arrow-down-tray" variant="ghost" size="sm" class="cursor-pointer w-full"
            :aria-label="__('Export JSON')">
            {{ __('Export as JSON') }}
        </flux:button>
    </div>
</flux:modal>
