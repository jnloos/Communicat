<x-flyout name="edit-project" width="32rem">
    <div class="flex flex-1 flex-col gap-6">
        <x-modal-header :heading="__('projects.edit.heading')" :subheading="__('projects.edit.subheading')">
            {{-- What this modal does to the project, as opposed to what the form
                 does: keeps the footer to cancel and save, like every other modal. --}}
            <x-slot:actions>
                <flux:dropdown position="bottom" align="end">
                    <x-more-button size="sm" />
                    <flux:menu>
                        <flux:menu.item icon="arrow-down-tray"
                            href="{{ route('project.export', $forProjectId) }}">
                            {{ __('transfer.actions.export') }}
                        </flux:menu.item>
                        <flux:menu.separator/>
                        <flux:menu.item variant="danger" icon="trash" wire:click="needsConfirmation('delete')">
                            {{ __('projects.edit.delete') }}
                        </flux:menu.item>
                    </flux:menu>
                </flux:dropdown>
            </x-slot:actions>
        </x-modal-header>

        <x-tags.badges :values="$savedTags" class="-mt-3" />

        <form wire:submit.prevent="save" class="flex flex-1 flex-col gap-6">
            <flux:input wire:model.defer="form.title" :label="__('common.fields.title')"/>
            <flux:textarea wire:model.defer="form.description" :label="__('common.fields.description')" rows="10"/>

            @if ($runStarted)
                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('projects.fields.run_started_help') }}</flux:text>
            @endif

            <flux:accordion>
                <x-projects.run-settings :pipelines="$pipelines" :providers="$providers" :models="$models" :attribute-count="$attributeCount" :locked="$runStarted"/>
                @if ($tagCategories->isNotEmpty())
                    <x-tags.project-accordion-item :values="$chosenTags" :categories="$tagCategories" />
                @endif
            </flux:accordion>

            <x-modal-footer>
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('common.actions.cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">
                    {{ __('projects.edit.submit') }}
                </flux:button>
            </x-modal-footer>
        </form>
    </div>
</x-flyout>
