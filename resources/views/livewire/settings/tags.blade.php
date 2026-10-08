<section class="mx-auto w-full max-w-6xl">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('tags.heading')" :subheading="__('tags.subheading')" wide>
        <flux:radio.group wire:model.live="scope" variant="segmented" size="sm" class="mb-6">
            @foreach ($scopes as $option)
                <flux:radio :value="$option->value" :label="$option->label()" />
            @endforeach
        </flux:radio.group>

        @if ($categories->isEmpty())
            <x-empty-state icon="tag">{{ __('tags.empty') }}</x-empty-state>
        @endif

        {{-- Categories and, inside each, its values are both sortable. Each list
             has its own handles; the inner list is a sortable of its own, so a
             value never drags its category along. --}}
        <div class="space-y-4" wire:sort="sortCategory">
            @foreach ($categories as $category)
                <flux:card size="sm" wire:key="tag-category-{{ $category->id }}" wire:sort:item="{{ $category->id }}">
                    <div class="flex items-center gap-2">
                        <span wire:sort:handle class="cursor-grab text-zinc-400" title="{{ __('tags.actions.drag') }}">
                            <flux:icon.bars-2 variant="micro" />
                        </span>

                        @if ($renamingCategoryId === $category->id)
                            <form wire:submit="saveCategoryName" class="flex flex-1 items-start gap-2">
                                <flux:input wire:model="renameTo" size="sm" autofocus wire:keydown.escape="cancelRenaming" class="flex-1" />
                                <flux:button type="submit" size="sm" variant="primary" class="cursor-pointer">{{ __('common.actions.save') }}</flux:button>
                            </form>
                        @else
                            <button type="button" class="flex-1 cursor-text text-start font-medium text-zinc-800 dark:text-white"
                                wire:click="startRenamingCategory({{ $category->id }})" title="{{ __('tags.actions.rename') }}">
                                {{ $category->name }}
                            </button>
                            <flux:button variant="ghost" size="sm" icon="trash" class="cursor-pointer text-red-600! dark:text-red-400!"
                                wire:click="confirmDeleteCategory({{ $category->id }})" :aria-label="__('tags.actions.delete_category')" />
                        @endif
                    </div>

                    <ul class="mt-3 space-y-1 ps-6" wire:sort="sortValue">
                        @foreach ($category->values as $value)
                            <li class="flex items-center gap-2" wire:key="tag-value-{{ $value->id }}" wire:sort:item="{{ $value->id }}">
                                <span wire:sort:handle class="cursor-grab text-zinc-400" title="{{ __('tags.actions.drag') }}">
                                    <flux:icon.bars-2 variant="micro" />
                                </span>

                                @if ($renamingValueId === $value->id)
                                    <form wire:submit="saveValueName" class="flex flex-1 items-start gap-2">
                                        <flux:input wire:model="renameTo" size="sm" autofocus wire:keydown.escape="cancelRenaming" class="flex-1" />
                                        <flux:button type="submit" size="sm" variant="primary" class="cursor-pointer">{{ __('common.actions.save') }}</flux:button>
                                    </form>
                                @else
                                    <button type="button" class="flex-1 cursor-text text-start text-sm"
                                        wire:click="startRenamingValue({{ $value->id }})" title="{{ __('tags.actions.rename') }}">
                                        {{ $value->name }}
                                    </button>
                                    <flux:badge size="sm" color="zinc">{{ trans_choice('tags.assignments', $value->assignments_count, ['count' => $value->assignments_count]) }}</flux:badge>
                                    <flux:button variant="ghost" size="xs" icon="x-mark" class="cursor-pointer"
                                        wire:click="confirmDeleteValue({{ $value->id }})" :aria-label="__('tags.actions.delete_value')" />
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    <form wire:submit="createValue({{ $category->id }})" class="mt-3 flex items-start gap-2 ps-6">
                        <flux:input wire:model="newValueNames.{{ $category->id }}" size="sm" :placeholder="__('tags.placeholders.new_value')" class="flex-1" />
                        <flux:button type="submit" size="sm" icon="plus" class="cursor-pointer">{{ __('tags.actions.add_value') }}</flux:button>
                    </form>
                </flux:card>
            @endforeach
        </div>

        <form wire:submit="createCategory" class="mt-6 flex items-start gap-2">
            <flux:input wire:model="newCategoryName" :placeholder="__('tags.placeholders.new_category')" class="flex-1" />
            <flux:button type="submit" variant="primary" icon="plus" class="cursor-pointer">{{ __('tags.actions.add_category') }}</flux:button>
        </form>
    </x-settings.layout>
</section>
