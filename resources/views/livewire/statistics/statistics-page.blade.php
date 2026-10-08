<section class="mx-auto w-full max-w-6xl">
    {{-- Page actions sit in the page header, as "Create Expert" does on the
         experts page. "New comparison" is the primary action only while there
         is nothing to open yet. --}}
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="xl" level="1">{{ __('comparisons.heading') }}</flux:heading>
            <flux:text class="mt-1">{{ __('comparisons.subheading') }}</flux:text>
        </div>

        <div class="flex items-center gap-2">
            <flux:dropdown position="bottom" align="end">
                <flux:button size="sm" icon="rectangle-stack" icon:trailing="chevron-down">{{ __('comparisons.templates.menu') }}</flux:button>
                <flux:menu>
                    @foreach ($templates as $template)
                        <flux:menu.item wire:click="createFromTemplate('{{ $template->key }}')" wire:key="template-{{ $template->key }}">{{ $template->name() }}</flux:menu.item>
                    @endforeach
                </flux:menu>
            </flux:dropdown>

            <flux:button size="sm" :variant="$comparisons->isEmpty() ? 'primary' : 'outline'" icon="plus" wire:click="startCreating">
                {{ __('comparisons.list.create') }}
            </flux:button>
        </div>
    </div>

    @if ($templateNotice)
        <flux:callout variant="warning" icon="exclamation-triangle" class="mt-6">
            <flux:callout.text>{{ $templateNotice }}</flux:callout.text>
            <x-slot name="controls">
                <flux:button variant="ghost" size="sm" icon="x-mark" wire:click="$set('templateNotice', null)" :aria-label="__('common.actions.close')" />
            </x-slot>
        </flux:callout>
    @endif

    <div class="mt-6 flex flex-col gap-6 lg:flex-row lg:items-start">
        <aside class="w-full shrink-0 lg:w-64">
            <x-statistics.section-label class="px-3">{{ __('comparisons.list.heading') }}</x-statistics.section-label>

            @if ($comparisons->isEmpty())
                <x-empty-state icon="chart-bar" class="mt-3">{{ __('comparisons.list.empty') }}</x-empty-state>
            @else
                <flux:navlist class="mt-3">
                    @foreach ($comparisons as $comparison)
                        <div class="group/comparison relative" wire:key="comparison-item-{{ $comparison->id }}">
                            {{-- A long name truncates inside its row, so it never runs under the "…" menu. --}}
                            <flux:navlist.item wire:click="open({{ $comparison->id }})" :current="$comparisonId === $comparison->id" :title="$comparison->name"
                                class="min-w-0 pe-9 [&_[data-content]]:min-w-0">
                                <span class="block truncate">{{ $comparison->name }}</span>
                            </flux:navlist.item>

                            <div class="absolute end-1 top-0 flex h-10 items-center lg:h-8">
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button variant="ghost" size="xs" icon="ellipsis-horizontal" :aria-label="__('common.actions.more')"
                                        class="text-zinc-400! hover:text-zinc-800! dark:text-zinc-500! dark:hover:text-white!" />
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
                <livewire:statistics.comparison-editor :comparison-id="$current->id" wire:key="comparison-editor-{{ $current->id }}" />
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
                    <flux:button variant="filled">{{ __('common.actions.cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('common.actions.save') }}</flux:button>
            </x-modal-footer>
        </form>
    </flux:modal>
</section>
