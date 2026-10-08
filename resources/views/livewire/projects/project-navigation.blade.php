{{--
    The sidebar's project list. flex-1 makes it both the scrolling area when
    there are projects and the spacer that pushes the footer down when there
    are none — which is why no flux:sidebar.spacer is needed here.
--}}
<div class="-mx-2 flex min-h-0 flex-1 flex-col overflow-y-auto overflow-x-hidden px-2 [scrollbar-gutter:stable] [scrollbar-width:thin]">
    {{-- The label row is the title of the list, not one of its groups: an xs
         uppercase section label, set apart from the text-sm group headers
         below. It sticks to the top with the sidebar's own background, so the
         funnel and the summary stay in reach while the list scrolls; the
         stable, thin scrollbar keeps the row from shifting when a filter makes
         the list long enough to scroll.

         The funnel stays an outline icon; an active filter shows as a small
         count badge on it and as one summary line underneath ("26 of 47 ·
         status, control"), whose text opens the popover and whose × clears.

         A popover, not a flux:menu: the panel holds a mode switch and pills,
         which a menu's roving focus and item roles do not fit.

         The popover stays open while its pills and switch re-render the
         component: Flux keeps the open state on the overlay element itself (the
         browser's top layer, plus data-open as a "durable" attribute it restores
         whenever a morph strips it). That only holds while Livewire morphs the
         same element instead of replacing it, hence the fixed wire:keys on the
         dropdown and its popover, and a label row that is never conditional. --}}
    <div class="sticky top-0 z-10 mb-1 flex flex-col bg-zinc-50 dark:bg-zinc-900" x-data>
        <div class="flex h-8 items-center justify-between ps-3 pe-1">
            <span class="truncate text-xs font-medium uppercase leading-none tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('tags.filter.label') }}</span>

            @if ($tagCategories->isNotEmpty())
                @php
                    $funnelLabel = $activeFilterCount > 0
                        ? __('tags.filter.button_active', ['count' => $activeFilterCount])
                        : __('tags.filter.button');
                @endphp

                <div class="relative">
                    <flux:dropdown position="bottom" align="start" wire:key="tag-filter-dropdown">
                        <flux:button
                            size="xs"
                            variant="subtle"
                            icon="funnel"
                            :aria-label="$funnelLabel"
                            :tooltip="$funnelLabel"
                            x-ref="tagFilterTrigger"
                            class="cursor-pointer"
                        />

                        <flux:popover class="flex w-64 flex-col gap-3" wire:key="tag-filter-popover">
                            <flux:heading size="sm">{{ __('tags.filter.title') }}</flux:heading>

                            <div class="flex flex-col gap-1.5">
                                <flux:radio.group wire:model.live="tagFilterMode" variant="segmented" size="sm" class="w-fit">
                                    <flux:radio :value="\App\Livewire\Projects\ProjectNavigation::FILTER_MERGE" :label="__('tags.filter.modes.merge.label')" class="cursor-pointer" />
                                    <flux:radio :value="\App\Livewire\Projects\ProjectNavigation::FILTER_CUT" :label="__('tags.filter.modes.cut.label')" class="cursor-pointer" />
                                </flux:radio.group>

                                <flux:text size="sm">{{ __("tags.filter.modes.{$tagFilterMode}.description") }}</flux:text>
                            </div>

                            {{-- One group over every category: Flux's checkbox group
                                 collects every ui-checkbox below it, headings in
                                 between or not, so all pills write the one flat
                                 $tagFilter list. --}}
                            <flux:checkbox.group wire:model.live="tagFilter" variant="pills" class="flex-col! gap-3!">
                                @foreach ($tagCategories as $category)
                                    <div class="flex flex-col gap-1.5" wire:key="tag-filter-category-{{ $category->id }}">
                                        <div class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-300">{{ $category->label() }}</div>

                                        <div class="flex flex-wrap gap-1.5">
                                            @foreach ($category->values as $value)
                                                <flux:checkbox
                                                    variant="pills"
                                                    :value="(string) $value->id"
                                                    :label="$value->name"
                                                    wire:key="tag-filter-{{ $value->id }}"
                                                    class="cursor-pointer"
                                                />
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </flux:checkbox.group>

                            <flux:separator variant="subtle" />

                            <div class="flex items-center justify-between gap-2">
                                <flux:text size="sm">{{ trans_choice('tags.filter.count', $projectCount, ['matches' => $matchCount, 'total' => $projectCount]) }}</flux:text>

                                <flux:button size="xs" variant="ghost" wire:click="clearTagFilter" :disabled="$activeFilterCount === 0" class="cursor-pointer">
                                    {{ __('tags.filter.clear') }}
                                </flux:button>
                            </div>
                        </flux:popover>
                    </flux:dropdown>

                    @if ($activeFilterCount > 0)
                        <span aria-hidden="true" class="pointer-events-none absolute -end-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-accent px-1 text-[10px] font-semibold leading-none text-accent-foreground">{{ $activeFilterCount }}</span>
                    @endif
                </div>
            @endif
        </div>

        @if ($chosenValues->isNotEmpty())
            <div class="flex items-center gap-1 ps-3 pe-1 pb-1">
                {{-- Tags as "category: value", like every badge; the tooltip
                     holds the full list once the line truncates. Clicking the
                     text opens the popover through the funnel itself, so
                     there is still only one dropdown to keep open. --}}
                <button
                    type="button"
                    class="min-w-0 flex-1 cursor-pointer truncate text-start text-xs text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white"
                    title="{{ $chosenValues->map->fullLabel()->implode(', ') }}"
                    x-on:click.stop="$refs.tagFilterTrigger.click()"
                >{{ __('tags.filter.summary', ['matches' => $matchCount, 'total' => $projectCount, 'values' => $chosenValues->map->fullLabel()->implode(', ')]) }}</button>

                <flux:button
                    size="xs"
                    variant="subtle"
                    icon="x-mark"
                    wire:click="clearTagFilter"
                    :aria-label="__('tags.filter.clear_label')"
                    :tooltip="__('tags.filter.clear_label')"
                    class="cursor-pointer"
                />
            </div>
        @endif
    </div>

    {{-- The list is its own flex column so its spacing stays clear of the modals
         below, which are children of the component's root as well.

         The groups stack without a gap: folded, they are a run of headers, and
         Flux's my-px on each header already gives them the rhythm of the nav
         items above. Only the ungrouped block is set apart, as its own section. --}}
    <div class="flex flex-col">
        @foreach ($groups as $group)
            {{-- Flux's expandable sidebar.group brings the chevron and the
                 disclosure, but has no slot beside its heading, so the group's menu
                 is laid over the header row (h-8, as in the stub).

                 The padding that keeps the heading clear of that menu goes on the
                 header button alone: Flux puts the component's attributes on the
                 whole ui-disclosure, so a plain pe-8 would shorten every project
                 row inside the group as well.

                 Groups start folded; only the one holding the open project
                 unfolds, so the row marking where the user is stays in sight.
                 While a filter is on, every group left is unfolded: the filter
                 already did the narrowing, and folded drawers would hide what it
                 found. Flux keeps a disclosure's open state across a morph, so
                 the filter state is part of the key: switching it on or off
                 rebuilds the group with the new default. --}}
            <div class="group/navgroup relative" wire:key="nav-group-{{ $group->id }}-{{ $activeFilterCount > 0 ? 'filtered' : 'all' }}">
                <flux:sidebar.group
                    expandable
                    :expanded="$activeFilterCount > 0 || $grouped[$group->id]->contains('id', $currentProjectId)"
                    :heading="$group->name"
                    class="[&>button]:pe-8 [&>button]:text-zinc-500! dark:[&>button]:text-zinc-400!"
                >
                    @forelse ($grouped[$group->id] as $project)
                        <x-projects.nav-project
                            :project="$project"
                            :groups="$groups"
                            :current="$currentProjectId === $project->id"
                            :outside-filter="$outsideFilterProjectId === $project->id"
                        />
                    @empty
                        <div class="px-3 py-1 text-sm text-zinc-400">{{ __('groups.empty') }}</div>
                    @endforelse
                </flux:sidebar.group>

                {{-- Hovering the group's own header, not its projects: the wrapper
                     spans the whole group, so a plain group-hover would light this
                     up while the pointer is three rows down on a project — which
                     has a menu of its own. The button sits over the header and
                     takes its own hover, hence the second condition. --}}
                <div class="absolute end-1 top-0 flex h-8 items-center opacity-0 transition-opacity hover:opacity-100 has-[:focus-visible]:opacity-100 group-has-[>ui-disclosure>button:hover]/navgroup:opacity-100 has-[[data-open]]:opacity-100">
                    <flux:dropdown position="bottom" align="start">
                        <flux:button
                            variant="ghost"
                            size="xs"
                            icon="ellipsis-horizontal"
                            :aria-label="__('groups.menus.group')"
                            class="cursor-pointer text-zinc-400! hover:text-zinc-800! dark:text-zinc-500! dark:hover:text-white!"
                        />

                        <flux:menu>
                            <flux:menu.item icon="pencil-square" wire:click="startRenamingGroup({{ $group->id }})">
                                {{ __('groups.actions.rename') }}
                            </flux:menu.item>

                            <flux:menu.item variant="danger" icon="trash" wire:click="confirmDeleteGroup({{ $group->id }})">
                                {{ __('groups.actions.delete_group') }}
                            </flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </div>
            </div>
        @endforeach

        @if ($ungrouped->isNotEmpty())
            <flux:sidebar.group :heading="__('groups.ungrouped')" @class(['mt-4' => $groups->isNotEmpty()])>
                @foreach ($ungrouped as $project)
                    <x-projects.nav-project
                        :project="$project"
                        :groups="$groups"
                        :current="$currentProjectId === $project->id"
                        :outside-filter="$outsideFilterProjectId === $project->id"
                    />
                @endforeach
            </flux:sidebar.group>
        @endif

        {{-- Counted on matches, not on rows: the open project stays in the list
             dimmed even when nothing matches. --}}
        @if ($activeFilterCount > 0 && $matchCount === 0)
            <div class="px-3 py-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('tags.filter.no_matches') }}</div>
        @endif
    </div>

    {{-- Renaming inline would fight Flux's disclosure button and its link item,
         so both renames use the house modal pattern instead. --}}
    <flux:modal name="project-group-create" class="md:w-96">
        <form wire:submit.prevent="createGroup" class="space-y-6">
            <x-modal-header :heading="__('groups.create.heading')" :subheading="__('groups.create.subheading')" />

            <flux:input wire:model.defer="groupName" :label="__('groups.fields.name')" autofocus />

            <x-modal-footer padding="dialog">
                <flux:modal.close>
                    <flux:button variant="filled" class="cursor-pointer">{{ __('common.actions.cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" class="cursor-pointer">{{ __('groups.create.submit') }}</flux:button>
            </x-modal-footer>
        </form>
    </flux:modal>

    <flux:modal name="project-group-rename" class="md:w-96">
        <form wire:submit.prevent="saveGroupName" class="space-y-6">
            <x-modal-header :heading="__('groups.rename_group.heading')" />

            <flux:input wire:model.defer="groupName" :label="__('groups.fields.name')" autofocus />

            <x-modal-footer padding="dialog">
                <flux:modal.close>
                    <flux:button variant="filled" class="cursor-pointer">{{ __('common.actions.cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" class="cursor-pointer">{{ __('common.actions.save') }}</flux:button>
            </x-modal-footer>
        </form>
    </flux:modal>

    <flux:modal name="project-rename" class="md:w-96">
        <form wire:submit.prevent="saveProjectTitle" class="space-y-6">
            <x-modal-header :heading="__('groups.rename_project.heading')" />

            <flux:input wire:model.defer="projectTitle" :label="__('common.fields.title')" autofocus />

            <x-modal-footer padding="dialog">
                <flux:modal.close>
                    <flux:button variant="filled" class="cursor-pointer">{{ __('common.actions.cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" class="cursor-pointer">{{ __('common.actions.save') }}</flux:button>
            </x-modal-footer>
        </form>
    </flux:modal>
</div>
