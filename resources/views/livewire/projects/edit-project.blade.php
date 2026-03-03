<flux:modal name="edit-project" variant="flyout" class="md:w-96">
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

            <flux:select wire:model.defer="strategy" :label="__('Prompting Strategy')">
                <option value="single">{{ __('Single-Threaded') }}</option>
                <option value="multiple">{{ __('Multi-Threaded') }}</option>
            </flux:select>


            <div class="flex items-center justify-between">
                <flux:button wire:click.debounce="delete" type="button" variant="danger" class="cursor-pointer">
                    {{ __('Delete Project') }}
                </flux:button>
                <flux:button type="submit" variant="primary" class="cursor-pointer">
                    {{ __('Update Project') }}
                </flux:button>
            </div>
        </form>
    </div>
</flux:modal>
