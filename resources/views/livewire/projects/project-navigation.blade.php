{{--
    The sidebar's project list. flex-1 makes it both the scrolling area when
    there are projects and the spacer that pushes the footer down when there
    are none — which is why no flux:sidebar.spacer is needed here.
--}}
<div class="-mx-2 flex min-h-0 flex-1 flex-col overflow-y-auto px-2">
    {{-- Filter by project tags. Shown only once project categories exist.

         The label row reads like the group headers below it (text-sm, medium,
         zinc-400, px-3), with the funnel at its end; an active filter tints the
         funnel in the accent colour and lists its values as chips underneath,
         so the state is visible without opening anything.

         A popover, not a flux:menu: the panel holds a mode switch and toggle
         pills, which a menu's roving focus and item roles do not fit. --}}
    @if ($tagCategories->isNotEmpty())
        <div class="mb-1 flex flex-col">
            <div class="flex h-8 items-center justify-between ps-3 pe-1">
                <span class="truncate text-sm font-medium leading-none text-zinc-400">{{ __('tags.filter.label') }}</span>

                <flux:dropdown position="bottom" align="end">
                    <flux:button
                        size="xs"
                        variant="subtle"
                        icon="funnel"
                        :icon:variant="$activeFilterCount > 0 ? 'solid' : 'outline'"
                        :aria-label="__('tags.filter.button')"
                        :tooltip="__('tags.filter.button')"
                        @class([
                            'cursor-pointer',
                            'text-accent-content! hover:text-accent-content!' => $activeFilterCount > 0,
                        ])
                    />

                    <flux:popover class="flex w-64 flex-col gap-3">
                        <flux:heading size="sm">{{ __('tags.filter.title') }}</flux:heading>

                        <div class="flex flex-col gap-1.5">
                            <flux:radio.group wire:model.live="tagFilterMode" variant="segmented" size="sm">
                                <flux:radio :value="\App\Livewire\Projects\ProjectNavigation::FILTER_MERGE" :label="__('tags.filter.modes.merge.label')" class="cursor-pointer" />
                                <flux:radio :value="\App\Livewire\Projects\ProjectNavigation::FILTER_CUT" :label="__('tags.filter.modes.cut.label')" class="cursor-pointer" />
                            </flux:radio.group>

                            <flux:text size="sm">{{ __("tags.filter.modes.{$tagFilterMode}.description") }}</flux:text>
                        </div>

                        @foreach ($tagCategories as $category)
                            <div class="flex flex-col gap-1.5" wire:key="tag-filter-category-{{ $category->id }}">
                                <div class="text-xs font-medium uppercase tracking-wide text-zinc-400">{{ $category->name }}</div>

                                <div class="flex flex-wrap gap-1">
                                    @foreach ($category->values as $value)
                                        @php $chosen = $chosenValues->contains('id', $value->id); @endphp
                                        <flux:button
                                            size="xs"
                                            :variant="$chosen ? 'primary' : 'outline'"
                                            wire:click="toggleTagValue({{ $value->id }})"
                                            wire:key="tag-filter-{{ $value->id }}"
                                            aria-pressed="{{ $chosen ? 'true' : 'false' }}"
                                            class="cursor-pointer"
                                        >{{ $value->name }}</flux:button>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach

                        <flux:separator variant="subtle" />

                        <div class="flex items-center justify-between gap-2">
                            <flux:text size="sm">{{ __('tags.filter.count', ['matches' => $matchCount, 'total' => $projectCount]) }}</flux:text>

                            <flux:button size="xs" variant="ghost" wire:click="clearTagFilter" :disabled="$activeFilterCount === 0" class="cursor-pointer">
                                {{ __('tags.filter.clear') }}
                            </flux:button>
                        </div>
                    </flux:popover>
                </flux:dropdown>
            </div>

            @if ($chosenValues->isNotEmpty())
                <div class="flex flex-wrap items-center gap-1 px-3 pb-1">
                    @foreach ($chosenValues as $value)
                        <flux:badge size="sm" wire:key="tag-filter-chip-{{ $value->id }}">
                            {{ $value->category->name }}: {{ $value->name }}
                            <flux:badge.close
                                wire:click="removeTagValue({{ $value->id }})"
                                :aria-label="__('tags.filter.remove', ['value' => $value->name])"
                                class="cursor-pointer"
                            />
                        </flux:badge>
                    @endforeach

                    <flux:button size="xs" variant="ghost" wire:click="clearTagFilter" :aria-label="__('tags.filter.clear_label')" class="cursor-pointer">
                        {{ __('tags.filter.clear') }}
                    </flux:button>
                </div>
            @endif
        </div>
    @endif

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
                 unfolds, so the row marking where the user is stays in sight. --}}
            <div class="group/navgroup relative" wire:key="nav-group-{{ $group->id }}">
                <flux:sidebar.group
                    expandable
                    :expanded="$grouped[$group->id]->contains('id', $currentProjectId)"
                    :heading="$group->name"
                    class="[&>button]:pe-8 [&>button]:text-zinc-400!"
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

        @if ($activeFilterCount > 0 && $groups->isEmpty() && $ungrouped->isEmpty())
            <div class="px-3 py-1 text-sm text-zinc-400">{{ __('tags.filter.no_matches') }}</div>
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
