<section class="mx-auto w-full max-w-6xl">
    <div>
        <flux:heading size="xl" level="1">{{ __('comparisons.heading') }}</flux:heading>
        <flux:text class="mt-1">{{ __('comparisons.subheading') }}</flux:text>
    </div>

    <div class="mt-6 flex flex-col gap-6 lg:flex-row lg:items-start">
        <aside class="w-full shrink-0 lg:w-64">
            <div class="flex items-center justify-between gap-2">
                <flux:heading size="sm">{{ __('comparisons.list.heading') }}</flux:heading>
                <flux:button size="sm" variant="primary" icon="plus" wire:click="startCreating" class="cursor-pointer">
                    {{ __('comparisons.list.create') }}
                </flux:button>
            </div>

            @if ($comparisons->isEmpty())
                <x-empty-state icon="chart-bar" class="mt-3">{{ __('comparisons.list.empty') }}</x-empty-state>
            @else
                <flux:navlist class="mt-3">
                    @foreach ($comparisons as $comparison)
                        <div class="group/comparison relative" wire:key="comparison-item-{{ $comparison->id }}">
                            <flux:navlist.item wire:click="open({{ $comparison->id }})" :current="$comparisonId === $comparison->id" class="cursor-pointer pe-9">
                                {{ $comparison->name }}
                            </flux:navlist.item>

                            <div class="absolute end-1 top-0 flex h-10 items-center lg:h-8">
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button variant="ghost" size="xs" icon="ellipsis-horizontal" :aria-label="__('common.actions.more')"
                                        class="cursor-pointer text-zinc-400! hover:text-zinc-800! dark:text-zinc-500! dark:hover:text-white!" />
                                    <flux:menu>
                                        <flux:menu.item icon="pencil-square" wire:click="startRenaming({{ $comparison->id }})">{{ __('comparisons.actions.rename') }}</flux:menu.item>
                                        <flux:menu.item icon="document-duplicate" wire:click="duplicate({{ $comparison->id }})">{{ __('comparisons.actions.duplicate') }}</flux:menu.item>
                                        <flux:menu.separator />
                                        <flux:menu.item variant="danger" icon="trash" wire:click="confirmDelete({{ $comparison->id }})">{{ __('comparisons.actions.delete') }}</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </div>
                        </div>
                    @endforeach
                </flux:navlist>
            @endif
        </aside>

        <div class="min-w-0 flex-1">
            @if ($current)
                <flux:heading size="lg" class="mb-4">{{ $current->name }}</flux:heading>
                {{-- comparison-editor --}}
            @else
                <x-empty-state icon="chart-bar">{{ __('comparisons.none_open') }}</x-empty-state>
            @endif
        </div>
    </div>

    <flux:modal name="comparison-name" class="md:w-96">
        <form wire:submit.prevent="saveName" class="space-y-6">
            <x-modal-header :heading="$renamingId ? __('comparisons.name.rename') : __('comparisons.name.create')" />

            <flux:input wire:model="name" :label="__('comparisons.name.field')" autofocus />

            <x-modal-footer padding="dialog">
                <flux:modal.close>
                    <flux:button variant="filled" class="cursor-pointer">{{ __('common.actions.cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" class="cursor-pointer">{{ __('common.actions.save') }}</flux:button>
            </x-modal-footer>
        </form>
    </flux:modal>
</section>
