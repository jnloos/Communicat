{{--
    The sidebar's project list. flex-1 makes it both the scrolling area when
    there are projects and the spacer that pushes the footer down when there
    are none — which is why no flux:sidebar.spacer is needed here.
--}}
<div data-project-list class="-mx-2 flex min-h-0 flex-1 flex-col overflow-y-auto overflow-x-hidden px-2">
    {{-- The label row is the title of the list, not one of its groups: an xs
         uppercase section label, set apart from the text-sm group headers
         below. It sticks to the top with the sidebar's own background, so the
         funnel stays in reach while the list scrolls; the
         stable, thin scrollbar (app.css, [data-project-list]) keeps the row
         from shifting when a filter makes the list long enough to scroll.

         The funnel is the only sign of a filter: always the same solid icon,
         in the button's normal icon colour while none is chosen and in the
         accent colour (no filled block, no badge) once at least one value is.

         The menu is a plain flux:menu with keep-open, so ticking a value or
         switching the mode re-renders the component without closing it. Flux
         keeps the open state on the overlay element itself (the browser's top
         layer, plus data-open as a "durable" attribute it restores whenever a
         morph strips it). That only holds while Livewire morphs the same
         element instead of replacing it, hence the fixed wire:keys on the
         dropdown and its menu, and a label row that is never conditional. --}}
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
                            icon:variant="solid"
                            :aria-label="$funnelLabel"
                            :tooltip="$funnelLabel"
                            :data-filter-active="$activeFilterCount > 0 ? 'true' : null"
                            :class="$activeFilterCount > 0 ? 'text-(--color-accent-content)!' : 'text-zinc-800! dark:text-white!'"
                        />

                        <flux:menu keep-open wire:key="tag-filter-menu">
                            {{-- Each mode explains itself in its tooltip. --}}
                            <flux:menu.radio.group wire:model.live="tagFilterMode">
                                <flux:menu.radio :value="\App\Livewire\Projects\ProjectNavigation::FILTER_MERGE" :title="__('tags.filter.modes.merge.description')">{{ __('tags.filter.modes.merge.label') }}</flux:menu.radio>
                                <flux:menu.radio :value="\App\Livewire\Projects\ProjectNavigation::FILTER_CUT" :title="__('tags.filter.modes.cut.description')">{{ __('tags.filter.modes.cut.label') }}</flux:menu.radio>
                            </flux:menu.radio.group>

                            {{-- One group over every category: Flux's checkbox group
                                 collects every menu checkbox below it, headings and
                                 separators in between or not, so all values write
                                 the one flat $tagFilter list. --}}
                            <flux:menu.checkbox.group wire:model.live="tagFilter">
                                @foreach ($tagCategories as $category)
                                    <flux:menu.separator />
                                    <flux:menu.heading>{{ $category->label() }}</flux:menu.heading>

                                    @foreach ($category->values as $value)
                                        <flux:menu.checkbox :value="(string) $value->id" wire:key="tag-filter-{{ $value->id }}">{{ $value->name }}</flux:menu.checkbox>
                                    @endforeach
                                @endforeach
                            </flux:menu.checkbox.group>

                            <flux:menu.separator />

                            <flux:menu.item icon="x-mark" wire:click="clearTagFilter" :disabled="$activeFilterCount === 0">{{ __('tags.filter.clear_label') }}</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </div>
            @endif
        </div>
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
