<flux:modal name="edit-project" variant="flyout" class="w-full p-5! sm:p-8! md:w-[32rem]">
    <div class="space-y-6">
        <div class="space-y-1 pe-8">
            <flux:heading size="lg">{{ __('projects.edit.heading') }}</flux:heading>
            <flux:text size="sm">{{ __('projects.edit.subheading') }}</flux:text>
        </div>

        <form wire:submit.prevent="save" class="space-y-6">
            <flux:input wire:model.defer="title" :label="__('common.fields.title')"/>
            <flux:textarea wire:model.defer="description" :label="__('common.fields.description')" rows="10"/>

            <flux:select wire:model.defer="pipeline" :label="__('projects.fields.pipeline')" :disabled="$runStarted">
                @foreach ($pipelines as $name => $label)
                    <option value="{{ $name }}">{{ $label }}</option>
                @endforeach
            </flux:select>

            <flux:select wire:model.defer="model" :label="__('projects.fields.model')" :disabled="$runStarted"
                :description="$runStarted ? __('projects.fields.run_started_help') : null">
                @foreach ($models as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </flux:select>

            <div class="flex justify-end">
                <flux:button type="submit" variant="primary" class="w-full cursor-pointer sm:w-auto">
                    {{ __('projects.edit.submit') }}
                </flux:button>
            </div>
        </form>

        <flux:separator />

        <div class="flex flex-col gap-2 sm:flex-row sm:justify-between">
            <flux:button as="a" href="{{ route('project.export.json', $forProjectId) }}"
                icon="arrow-down-tray" variant="ghost" class="cursor-pointer">
                {{ __('projects.edit.export_json') }}
            </flux:button>
            <flux:button type="button" variant="danger" icon="trash" class="cursor-pointer"
                wire:click="needsConfirmation('delete')">
                {{ __('projects.edit.delete') }}
            </flux:button>
        </div>
    </div>
</flux:modal>
