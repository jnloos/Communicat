<div class="mx-auto w-full max-w-2xl">
    <div>
        <flux:heading size="xl" level="1">{{ __('projects.create.heading') }}</flux:heading>
        <flux:text class="mt-1">{{ __('projects.create.subheading') }}</flux:text>
    </div>

    <flux:card class="mt-6 max-sm:border-0! max-sm:bg-transparent! max-sm:p-0! max-sm:shadow-none! sm:p-6!">
        <form wire:submit.prevent="save" class="space-y-6">
            <flux:input wire:model.defer="title" :label="__('common.fields.title')" :description="__('projects.fields.title_help')"/>

            <flux:textarea wire:model.defer="description" :label="__('common.fields.description')" rows="8" :description="__('projects.fields.description_help')"/>

            <flux:select wire:model.defer="frequency" :label="__('projects.fields.memory_reduction')" :description="__('projects.fields.memory_reduction_help')">
                <option value="5">{{ __('projects.fields.reduction.high') }}</option>
                <option selected value="10">{{ __('projects.fields.reduction.standard') }}</option>
                <option value="20">{{ __('projects.fields.reduction.low') }}</option>
            </flux:select>

            <div class="flex justify-end">
                <flux:button type="submit" variant="primary" icon="chat-bubble-left-right" class="w-full cursor-pointer sm:w-auto">
                    {{ __('projects.create.submit') }}
                </flux:button>
            </div>
        </form>
    </flux:card>

    <div class="my-8 flex items-center gap-4 text-zinc-400">
        <flux:separator class="flex-1" />
        <span class="text-sm">{{ __('common.or') }}</span>
        <flux:separator class="flex-1" />
    </div>

    <flux:card class="max-sm:border-0! max-sm:bg-transparent! max-sm:p-0! max-sm:shadow-none! sm:p-6!">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">{{ __('projects.import.heading') }}</flux:heading>
                <flux:text size="sm" class="mt-1">{{ __('projects.import.description') }}</flux:text>
            </div>

            <flux:file-upload wire:model="importFile" accept="application/json,.json">
                <flux:file-upload.dropzone
                    inline
                    :heading="__('projects.import.dropzone_heading')"
                    :text="__('projects.import.dropzone_text')"
                />
            </flux:file-upload>

            @if ($importFile)
                <flux:file-item :heading="$importFile->getClientOriginalName()" :size="$importFile->getSize()">
                    <x-slot name="actions">
                        <flux:file-item.remove wire:click="$set('importFile', null)" :aria-label="__('projects.import.remove_file')" />
                    </x-slot>
                </flux:file-item>
            @endif

            <flux:error name="importFile" />

            <div class="flex justify-end">
                <flux:button type="button" variant="filled" icon="arrow-up-tray" class="w-full cursor-pointer sm:w-auto"
                    wire:click="createFromFile" wire:loading.attr="disabled" wire:target="importFile,createFromFile"
                    :disabled="! $importFile">
                    <span wire:loading.remove wire:target="createFromFile">{{ __('projects.import.submit') }}</span>
                    <span wire:loading wire:target="createFromFile">{{ __('projects.import.creating') }}</span>
                </flux:button>
            </div>
        </div>
    </flux:card>
</div>
