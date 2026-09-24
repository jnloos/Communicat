<flux:modal name="edit-project" variant="flyout" class="flex w-full flex-col p-5! sm:p-8! md:w-[32rem]">
    <div class="flex flex-1 flex-col gap-6">
        <div class="flex items-start justify-between gap-4 pe-8">
            <div class="space-y-1">
                <flux:heading size="lg">{{ __('projects.edit.heading') }}</flux:heading>
                <flux:text size="sm">{{ __('projects.edit.subheading') }}</flux:text>
            </div>

            {{-- What this modal does to the project, as opposed to what the form
                 does: keeps the footer to cancel and save, like every other modal. --}}
            <flux:dropdown position="bottom" align="end">
                <flux:button variant="subtle" size="sm" icon="ellipsis-horizontal"
                    :aria-label="__('common.actions.more')" class="cursor-pointer"/>
                <flux:menu>
                    <flux:menu.item icon="arrow-down-tray"
                        href="{{ route('project.export.json', $forProjectId) }}">
                        {{ __('projects.edit.export_json') }}
                    </flux:menu.item>
                    <flux:menu.separator/>
                    <flux:menu.item variant="danger" icon="trash" wire:click="needsConfirmation('delete')">
                        {{ __('projects.edit.delete') }}
                    </flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        </div>

        <form wire:submit.prevent="save" class="flex flex-1 flex-col gap-6">
            <flux:input wire:model.defer="title" :label="__('common.fields.title')"/>
            <flux:textarea wire:model.defer="description" :label="__('common.fields.description')" rows="10"/>

            @if ($runStarted)
                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('projects.fields.run_started_help') }}</flux:text>
            @endif

            <flux:accordion>
                <flux:accordion.item transition="true">
                    <flux:accordion.heading>
                        <div class="flex gap-2 {{ $errors->hasAny(['pipeline', 'provider', 'model']) ? 'text-red-500 dark:text-red-400' : '' }}">
                            <flux:icon.cpu-chip class="size-5"/> {{ __('projects.groups.ai_pipeline') }}
                        </div>
                    </flux:accordion.heading>
                    <flux:accordion.content class="my-4 space-y-4">
                        <flux:select wire:model.defer="pipeline" :label="__('projects.fields.pipeline')">
                            @foreach ($pipelines as $name => $label)
                                <option value="{{ $name }}">{{ $label }}</option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:model.live="provider" :label="__('projects.fields.provider')">
                            @foreach ($providers as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
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

            <x-modal-footer>
                <flux:modal.close>
                    <flux:button variant="filled" class="cursor-pointer">{{ __('common.actions.cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" class="cursor-pointer">
                    {{ __('projects.edit.submit') }}
                </flux:button>
            </x-modal-footer>
        </form>
    </div>
</flux:modal>
