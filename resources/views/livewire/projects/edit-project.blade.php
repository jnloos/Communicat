<flux:modal name="edit-project" variant="flyout" class="md:w-[32rem]">
    <div class="space-y-6">
        <flux:heading size="lg">
            {{ __('Edit Project') }}
        </flux:heading>

        <flux:spacer />

        @can('admin')
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
        @else
            {{-- Non-admins see title/description read-only; only delete remains. --}}
            <div class="space-y-6">
                <div>
                    <flux:heading size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('Title') }}</flux:heading>
                    <p class="mt-1 text-zinc-800 dark:text-zinc-100">{{ $title }}</p>
                </div>
                <div>
                    <flux:heading size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('Description') }}</flux:heading>
                    <p class="mt-1 whitespace-pre-wrap text-zinc-800 dark:text-zinc-100">{{ $description }}</p>
                </div>

                <div class="flex">
                    <flux:button type="button" variant="danger" class="cursor-pointer"
                        wire:click="needsConfirmation('delete')">
                        {{ __('Delete Project') }}
                    </flux:button>
                </div>
            </div>
        @endcan

        <flux:separator />

        <flux:button as="a" href="{{ route('project.export.json', $forProjectId) }}"
            icon="arrow-down-tray" variant="ghost" size="sm" class="cursor-pointer w-full"
            :aria-label="__('Export JSON')">
            {{ __('Export as JSON') }}
        </flux:button>
    </div>
</flux:modal>
