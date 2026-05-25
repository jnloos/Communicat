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

        <div class="space-y-3">
            <flux:heading size="sm">{{ __('Import Project') }}</flux:heading>
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                {{ __('Upload a JSON export to create a full copy as a new project.') }}
            </flux:text>

            <input type="file" accept="application/json,.json" wire:model="importFile"
                class="block w-full text-sm text-zinc-600 dark:text-zinc-300
                       file:me-3 file:rounded-md file:border-0 file:bg-zinc-100 dark:file:bg-zinc-700
                       file:px-3 file:py-1.5 file:text-sm file:text-zinc-700 dark:file:text-zinc-200
                       file:cursor-pointer cursor-pointer"/>
            @error('importFile')
                <flux:text size="sm" class="text-red-500">{{ $message }}</flux:text>
            @enderror

            <flux:button type="button" variant="primary" class="cursor-pointer w-full"
                wire:click="import" wire:loading.attr="disabled" wire:target="importFile,import">
                <span wire:loading.remove wire:target="import">{{ __('Import as Copy') }}</span>
                <span wire:loading wire:target="import">{{ __('Importing…') }}</span>
            </flux:button>
        </div>
    </div>
</flux:modal>
