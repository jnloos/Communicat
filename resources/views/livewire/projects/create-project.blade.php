<div class="mx-auto w-full max-w-2xl">
    <div>
        <flux:heading size="xl" level="1">{{ __('projects.create.heading') }}</flux:heading>
        <flux:text class="mt-1">{{ __('projects.create.subheading') }}</flux:text>
    </div>

    <flux:card class="mt-6 max-sm:border-0! max-sm:bg-transparent! max-sm:p-0! max-sm:shadow-none! sm:p-6!">
        <form wire:submit.prevent="save" class="space-y-6">
            <flux:input wire:model.defer="form.title" :label="__('common.fields.title')" :description="__('projects.fields.title_help')"/>

            <flux:textarea wire:model.defer="form.description" :label="__('common.fields.description')" rows="8" :description="__('projects.fields.description_help')"/>


            <flux:accordion>
                <x-projects.run-settings :pipelines="$pipelines" :providers="$providers" :models="$models" with-help/>
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
