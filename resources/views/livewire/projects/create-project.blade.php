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
    </div>
</div>
