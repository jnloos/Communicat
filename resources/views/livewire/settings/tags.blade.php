<x-settings.layout :heading="__('tags.heading')" :subheading="__('tags.subheading')" wide>
    {{-- Tabs, not a segmented switch: this navigates between two lists,
         while the segmented controls elsewhere switch a mode. Without
         panels, the group is only there because Flux's tabs expect one. --}}
    <flux:tab.group class="mb-6">
        <flux:tabs wire:model.live="scope">
            @foreach ($scopes as $option)
                <flux:tab :name="$option->value">{{ $option->label() }}</flux:tab>
            @endforeach
        </flux:tabs>
    </flux:tab.group>

    @if ($categories->isEmpty())
        <x-empty-state icon="tag">{{ __('tags.empty') }}</x-empty-state>
    @endif

    {{-- Categories and, inside each, its values are both sortable. Each list
         has its own handles, shown on hover; the inner list is a sortable of
         its own, so a value never drags its category along.

         Dragging has a keyboard and touch equivalent: Move up/down in the
         row's menu. Rename and delete sit in that "…" menu too, as for sidebar groups
         and saved questions: red appears only on the delete item and in the
         confirm dialog. --}}
    <div class="space-y-4" wire:sort="sortCategory">
        @foreach ($categories as $category)
            <flux:card size="sm" wire:key="tag-category-{{ $category->id }}" wire:sort:item="{{ $category->id }}">
                <div class="group/category flex items-center gap-2">
                    <span wire:sort:handle class="cursor-grab text-zinc-400 opacity-0 transition-opacity group-hover/category:opacity-100" title="{{ __('tags.actions.drag') }}">
                        <flux:icon.bars-2 variant="micro" />
                    </span>

                    @if ($renamingCategoryId === $category->id)
                        <form wire:submit="saveCategoryName" class="flex flex-1 items-start gap-2">
                            <flux:input wire:model="renameTo" size="sm" autofocus wire:keydown.escape="cancelRenaming" class="flex-1" />
                            <flux:button type="submit" size="sm" variant="primary">{{ __('common.actions.save') }}</flux:button>
                            <flux:button size="sm" variant="ghost" wire:click="cancelRenaming">{{ __('common.actions.cancel') }}</flux:button>
                        </form>
                    @else
                        <span class="flex-1 font-medium text-zinc-800 dark:text-white">{{ $category->label() }}</span>

                        <x-tags.row-menu
                            :label="__('tags.actions.category_menu')"
                            :rename="'startRenamingCategory('.$category->id.')'"
                            :delete="'confirmDeleteCategory('.$category->id.')'"
                        :move="'moveCategory('.$category->id.', %d)'"
                        :first="$loop->first"
                        :last="$loop->last"
                        />
                    @endif
                </div>

                <ul class="mt-2 divide-y divide-zinc-100 ps-6 dark:divide-white/10" wire:sort="sortValue">
                    @foreach ($category->values as $value)
                        <li class="group/value flex min-h-9 items-center gap-2 py-1" wire:key="tag-value-{{ $value->id }}" wire:sort:item="{{ $value->id }}">
                            <span wire:sort:handle class="cursor-grab text-zinc-400 opacity-0 transition-opacity group-hover/value:opacity-100" title="{{ __('tags.actions.drag') }}">
                                <flux:icon.bars-2 variant="micro" />
                            </span>

                            @if ($renamingValueId === $value->id)
                                <form wire:submit="saveValueName" class="flex flex-1 items-start gap-2">
                                    <flux:input wire:model="renameTo" size="sm" autofocus wire:keydown.escape="cancelRenaming" class="flex-1" />
                                    <flux:button type="submit" size="sm" variant="primary">{{ __('common.actions.save') }}</flux:button>
                                    <flux:button size="sm" variant="ghost" wire:click="cancelRenaming">{{ __('common.actions.cancel') }}</flux:button>
                                </form>
                            @else
                                <span class="flex-1 text-sm text-zinc-800 dark:text-white">{{ $value->name }}</span>

                                <flux:text size="sm" class="tabular-nums">
                                    {{ trans_choice("tags.usage.{$category->scope->value}", $value->assignments_count, ['count' => $value->assignments_count]) }}
                                </flux:text>

                                <x-tags.row-menu
                                    :label="__('tags.actions.value_menu')"
                                    :rename="'startRenamingValue('.$value->id.')'"
                                    :delete="'confirmDeleteValue('.$value->id.')'"
                                    :move="'moveValue('.$value->id.', %d)'"
                                    :first="$loop->first"
                                    :last="$loop->last"
                                />
                            @endif
                        </li>
                    @endforeach
                </ul>

                <x-tags.add-form
                    class="mt-2 ps-6"
                    :submit="'createValue('.$category->id.')'"
                    :model="'newValueNames.'.$category->id"
                    :open="$errors->has('newValueNames.'.$category->id)"
                    :button="__('tags.actions.add_value')"
                    :placeholder="__('tags.placeholders.new_value')"
                />
            </flux:card>
        @endforeach
    </div>

    <x-tags.add-form
        class="mt-4"
        :submit="'createCategory'"
        :model="'newCategoryName'"
        :open="$errors->has('newCategoryName')"
        :button="__('tags.actions.add_category')"
        :placeholder="__('tags.placeholders.new_category')"
    />
</x-settings.layout>
