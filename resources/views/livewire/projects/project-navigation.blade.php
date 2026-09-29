{{--
    The sidebar's project list. flex-1 makes it both the scrolling area when
    there are projects and the spacer that pushes the footer down when there
    are none — which is why no flux:sidebar.spacer is needed here.
--}}
<div class="-mx-2 flex min-h-0 flex-1 flex-col overflow-y-auto px-2">
    {{-- The list is its own flex column so the gap falls between the groups and
         before the ungrouped block, and not between the modals below, which are
         children of the component's root as well. --}}
    <div class="flex flex-col gap-2">
        @foreach ($groups as $group)
            {{-- Flux's expandable sidebar.group brings the chevron and the
                 disclosure, but has no slot beside its heading, so the group's menu
                 is laid over the header row (h-8, as in the stub).

                 The padding that keeps the heading clear of that menu goes on the
                 header button alone: Flux puts the component's attributes on the
                 whole ui-disclosure, so a plain pe-8 would shorten every project
                 row inside the group as well. --}}
            <div class="group/navgroup relative" wire:key="nav-group-{{ $group->id }}">
                <flux:sidebar.group expandable :heading="$group->name" class="[&>button]:pe-8 [&>button]:text-zinc-400!">
                    @forelse ($grouped[$group->id] as $project)
                        <x-projects.nav-project
                            :project="$project"
                            :groups="$groups"
                            :current="$currentProjectId === $project->id"
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
            <flux:sidebar.group :heading="__('groups.ungrouped')">
                @foreach ($ungrouped as $project)
                    <x-projects.nav-project
                        :project="$project"
                        :groups="$groups"
                        :current="$currentProjectId === $project->id"
                    />
                @endforeach
            </flux:sidebar.group>
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
