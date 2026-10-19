<x-settings.layout :heading="__('attributes.heading')" :subheading="__('attributes.subheading')" wide>
    <flux:callout icon="information-circle" class="mb-6">
        <flux:callout.text>{{ __('attributes.help') }}</flux:callout.text>
    </flux:callout>

    @if ($definitions->isEmpty())
        <x-empty-state icon="identification">{{ __('attributes.empty') }}</x-empty-state>
    @endif

    {{-- Drag to sort; Move up/down in the row menu is the keyboard and touch way. --}}
    <div class="space-y-3" wire:sort="sort">
        @foreach ($definitions as $attribute)
            <flux:card size="sm" wire:key="persona-attribute-{{ $attribute->id }}" wire:sort:item="{{ $attribute->id }}">
                <div class="group/attribute flex items-start gap-2">
                    <span wire:sort:handle class="mt-1 cursor-grab text-zinc-400 opacity-0 transition-opacity group-hover/attribute:opacity-100 group-focus-within/attribute:opacity-100 [@media(hover:none)]:opacity-100" title="{{ __('attributes.actions.drag') }}">
                        <flux:icon.bars-2 variant="micro" />
                    </span>

                    <div class="min-w-0 flex-1 space-y-1">
                        <div class="font-medium text-zinc-800 dark:text-white">{{ $attribute->name }}</div>
                        <flux:text size="sm"><span class="text-zinc-500 dark:text-zinc-400">{{ __('attributes.rows.self') }}</span> {{ $attribute->self_template }}</flux:text>
                        <flux:text size="sm"><span class="text-zinc-500 dark:text-zinc-400">{{ __('attributes.rows.roster') }}</span> {{ $attribute->roster_template }}</flux:text>
                    </div>

                    <flux:text size="sm" class="mt-1 tabular-nums">
                        {{ trans_choice('attributes.usage', $attribute->experts_count, ['count' => $attribute->experts_count]) }}
                    </flux:text>

                    <flux:dropdown position="bottom" align="end">
                        <x-more-button :label="__('attributes.actions.menu', ['name' => $attribute->name])" />

                        <flux:menu>
                            <flux:menu.item icon="pencil-square" wire:click="edit({{ $attribute->id }})">{{ __('attributes.actions.edit') }}</flux:menu.item>
                            <flux:menu.separator />
                            <x-statistics.move-items :action="'move('.$attribute->id.', %d)'" :first="$loop->first" :last="$loop->last" />
                            <flux:menu.separator />
                            <flux:menu.item variant="danger" icon="trash" wire:click="confirmDelete({{ $attribute->id }})">{{ __('attributes.actions.delete') }}</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </div>
            </flux:card>
        @endforeach
    </div>

    <flux:button class="mt-4" size="sm" variant="ghost" icon="plus" wire:click="edit">{{ __('attributes.actions.add') }}</flux:button>

    <flux:modal name="persona-attribute" class="md:w-[32rem]">
        <form wire:submit.prevent="save" class="space-y-6">
            <x-modal-header
                :heading="$editingId ? __('attributes.dialog.edit') : __('attributes.dialog.create')"
                :subheading="__('attributes.dialog.subheading', ['placeholder' => \App\Models\PersonaAttribute::PLACEHOLDER])"
            />

            <flux:input wire:model.live.blur="name" :label="__('attributes.fields.name')" :placeholder="__('attributes.placeholders.name')" autofocus />

            <flux:input
                wire:model.live.debounce.300ms="selfTemplate"
                :label="__('attributes.fields.self_template')"
                :description="__('attributes.descriptions.self_template')"
                :placeholder="__('attributes.placeholders.self_template')"
            />

            <flux:input
                wire:model.live.debounce.300ms="rosterTemplate"
                :label="__('attributes.fields.roster_template')"
                :description="__('attributes.descriptions.roster_template')"
                :placeholder="__('attributes.placeholders.roster_template')"
            />

            <div class="space-y-3 rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800/50">
                <flux:input
                    wire:model.live.debounce.300ms="previewValue"
                    size="sm"
                    :label="__('attributes.preview.label')"
                    :placeholder="__('attributes.preview.example')"
                />

                <dl class="space-y-1 text-sm" data-preview>
                    <div>
                        <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('attributes.preview.self') }}</dt>
                        <dd class="text-zinc-800 dark:text-white" data-preview-self>{{ $previewSelf ?? '–' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('attributes.preview.roster') }}</dt>
                        <dd class="text-zinc-800 dark:text-white" data-preview-roster>{{ $previewRoster ?? '–' }}</dd>
                    </div>
                </dl>
            </div>

            <x-modal-footer padding="dialog">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('common.actions.cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('common.actions.save') }}</flux:button>
            </x-modal-footer>
        </form>
    </flux:modal>
</x-settings.layout>
