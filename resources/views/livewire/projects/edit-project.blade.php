<flux:modal name="edit-project" variant="flyout" class="w-full p-5! sm:p-8! md:w-[32rem]">
    <div class="space-y-6">
        <div class="space-y-1 pe-8">
            <flux:heading size="lg">{{ __('projects.edit.heading') }}</flux:heading>
            <flux:text size="sm">{{ __('projects.edit.subheading') }}</flux:text>
        </div>

        <form wire:submit.prevent="save" class="space-y-6">
            <flux:input wire:model.defer="title" :label="__('common.fields.title')"/>
            <flux:textarea wire:model.defer="description" :label="__('common.fields.description')" rows="10"/>

            @if ($runStarted)
                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('projects.fields.run_started_help') }}</flux:text>
            @endif

            <flux:accordion>
                <flux:accordion.item transition="true">
                    <flux:accordion.heading>
                        <div class="flex gap-2 {{ $errors->hasAny(['pipeline', 'model']) ? 'text-red-500 dark:text-red-400' : '' }}">
                            <flux:icon.cpu-chip class="size-5"/> {{ __('projects.groups.llm') }}
                        </div>
                    </flux:accordion.heading>
                    <flux:accordion.content class="my-4 space-y-4">
                        <flux:select wire:model.defer="pipeline" :label="__('projects.fields.pipeline')">
                            @foreach ($pipelines as $name => $label)
                                <option value="{{ $name }}">{{ $label }}</option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:model.defer="model" :label="__('projects.fields.model')">
                            @foreach ($models as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </flux:select>
                    </flux:accordion.content>
                </flux:accordion.item>

                <flux:accordion.item transition="true">
                    <flux:accordion.heading>
                        <div class="flex gap-2 {{ $errors->hasAny(['summarizeThreshold', 'summarizeOldest']) ? 'text-red-500 dark:text-red-400' : '' }}">
                            <flux:icon.arrows-pointing-in class="size-5"/> {{ __('projects.groups.reduction') }}
                        </div>
                    </flux:accordion.heading>
                    <flux:accordion.content class="my-4 space-y-4">
                        <flux:autocomplete wire:model.defer="summarizeThreshold" :label="__('projects.fields.summarize_threshold')">
                            <flux:autocomplete.item>20</flux:autocomplete.item>
                            <flux:autocomplete.item>30</flux:autocomplete.item>
                            <flux:autocomplete.item>40</flux:autocomplete.item>
                            <flux:autocomplete.item>60</flux:autocomplete.item>
                        </flux:autocomplete>

                        <flux:autocomplete wire:model.defer="summarizeOldest" :label="__('projects.fields.summarize_oldest')">
                            <flux:autocomplete.item>10</flux:autocomplete.item>
                            <flux:autocomplete.item>20</flux:autocomplete.item>
                            <flux:autocomplete.item>30</flux:autocomplete.item>
                        </flux:autocomplete>
                    </flux:accordion.content>
                </flux:accordion.item>
            </flux:accordion>

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
