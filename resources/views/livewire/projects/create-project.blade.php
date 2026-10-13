<div class="mx-auto w-full max-w-2xl">
    <div>
        <flux:heading size="xl" level="1">{{ __('projects.create.heading') }}</flux:heading>
        <flux:text class="mt-1">{{ __('projects.create.subheading') }}</flux:text>
    </div>

    <flux:card class="mt-6 max-sm:border-0! max-sm:bg-transparent! max-sm:p-0! max-sm:shadow-none! sm:p-6!">
        <form wire:submit.prevent="save" class="space-y-6">
            <flux:input wire:model.defer="title" :label="__('common.fields.title')" :description="__('projects.fields.title_help')"/>

            <flux:textarea wire:model.defer="description" :label="__('common.fields.description')" rows="8" :description="__('projects.fields.description_help')"/>


            <flux:accordion>
                <flux:accordion.item transition="true">
                    <flux:accordion.heading>
                        <div class="flex gap-2 {{ $errors->hasAny(['pipeline', 'provider', 'model']) ? 'text-red-500 dark:text-red-400' : '' }}">
                            <flux:icon.cpu-chip class="size-5"/> {{ __('projects.groups.ai_pipeline') }}
                        </div>
                    </flux:accordion.heading>
                    <flux:accordion.content class="my-4 space-y-4">
                        <flux:select wire:model.defer="pipeline" :label="__('projects.fields.pipeline')" :description="__('projects.fields.pipeline_help')">
                            @foreach ($pipelines as $name => $label)
                                <option value="{{ $name }}">{{ $label }}</option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:model.live="provider" :label="__('projects.fields.provider')" :description="__('projects.fields.provider_help')">
                            @foreach ($providers as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:model.defer="model" :label="__('projects.fields.model')" :description="__('projects.fields.model_help')">
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
                        <flux:autocomplete wire:model.defer="summarizeThreshold" :label="__('projects.fields.summarize_threshold')" :description="__('projects.fields.summarize_threshold_help')">
                            <flux:autocomplete.item>20</flux:autocomplete.item>
                            <flux:autocomplete.item>30</flux:autocomplete.item>
                            <flux:autocomplete.item>40</flux:autocomplete.item>
                            <flux:autocomplete.item>60</flux:autocomplete.item>
                        </flux:autocomplete>

                        <flux:autocomplete wire:model.defer="summarizeOldest" :label="__('projects.fields.summarize_oldest')" :description="__('projects.fields.summarize_oldest_help')">
                            <flux:autocomplete.item>10</flux:autocomplete.item>
                            <flux:autocomplete.item>20</flux:autocomplete.item>
                            <flux:autocomplete.item>30</flux:autocomplete.item>
                        </flux:autocomplete>
                    </flux:accordion.content>
                </flux:accordion.item>
                @if ($tagCategories->isNotEmpty())
                    <x-tags.project-accordion-item :values="$chosenTags" :categories="$tagCategories" />
                @endif
            </flux:accordion>

            <div class="flex justify-end">
                <flux:button type="submit" variant="primary" icon="chat-bubble-left-right" class="w-full sm:w-auto">
                    {{ __('projects.create.submit') }}
                </flux:button>
            </div>
        </form>
    </flux:card>
</div>
