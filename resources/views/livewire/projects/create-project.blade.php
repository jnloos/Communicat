<div>
    <flux:heading size="xl">{{ __('Create Project') }}</flux:heading>
    <div class="max-w-xl mx-auto mt-10">
        <form wire:submit.prevent="save" class="space-y-6">

            <flux:input wire:model.defer="title" :label="__('Title')" description="Enter a concise and recognizable project title."/>

            <flux:textarea wire:model.defer="description" :label="__('Description')" rows="10" description="Describe the project in a clear and concise way. This description will be used by AI systems, so make sure it's easy to understand and captures the core idea precisely."/>

            <flux:select wire:model.defer="frequency" :label="__('Memory Reduction')" description="This setting controls how many messages will be sent to the LLM. High reduction reduces token usage, but may also reduce the quality of the discussion.">
                <option value="5">{{ __('High') }}</option>
                <option selected value="10">{{ __('Standard') }}</option>
                <option value="20">{{ __('Low') }}</option>
            </flux:select>

            <div class="flex">
                <flux:spacer />
                <flux:button type="submit" variant="primary" class="cursor-pointer">
                    {{ __('Start Discussion') }}
                </flux:button>
            </div>
        </form>

        <div class="my-8 flex items-center gap-4 text-zinc-400">
            <flux:separator class="flex-1" />
            <span class="text-sm">{{ __('or') }}</span>
            <flux:separator class="flex-1" />
        </div>

        <div class="space-y-3">
            <flux:heading size="lg">{{ __('Create from File') }}</flux:heading>
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

            <div class="flex">
                <flux:spacer />
                <flux:button type="button" variant="filled" class="cursor-pointer"
                    wire:click="createFromFile" wire:loading.attr="disabled" wire:target="importFile,createFromFile">
                    <span wire:loading.remove wire:target="createFromFile">{{ __('Create from File') }}</span>
                    <span wire:loading wire:target="createFromFile">{{ __('Creating…') }}</span>
                </flux:button>
            </div>
        </div>
    </div>
</div>
