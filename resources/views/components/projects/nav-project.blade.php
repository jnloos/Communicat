@props([
    'project',
    'groups',
    'current' => false,
])

@php
    $pivotGroupId = $project->pivot?->project_group_id;
    $groupId = $pivotGroupId === null ? null : (int) $pivotGroupId;
@endphp

{{--
    One project in the sidebar, with the menu that files it.

    Flux's sidebar.item is a link and has no slot for a second control, so the
    menu button is laid over its right edge instead of nested inside it — a
    button inside an anchor would be invalid markup and would navigate on click.
    The item keeps pe-9 free so a long title cannot run under the button.
--}}
<div class="group/navitem relative" wire:key="nav-project-{{ $project->id }}">
    <flux:sidebar.item
        :href="route('project.show', $project)"
        :current="$current"
        :title="$project->title"
        class="pe-9"
        wire:navigate
    >{{ $project->title }}</flux:sidebar.item>

    <div class="absolute end-1 top-0 flex h-8 items-center opacity-0 transition-opacity focus-within:opacity-100 group-hover/navitem:opacity-100">
        <flux:dropdown position="bottom" align="start">
            <flux:button
                variant="ghost"
                size="xs"
                icon="ellipsis-horizontal"
                :aria-label="__('groups.menus.project')"
                class="cursor-pointer text-zinc-400! hover:text-zinc-800! dark:text-zinc-500! dark:hover:text-white!"
            />

            <flux:menu>
                {{-- Zone 1: everyone who reads the project files it for themselves.
                     Ticking the group it already sits in takes it back out. --}}
                <flux:menu.heading>{{ __('groups.menus.file_in') }}</flux:menu.heading>

                @foreach ($groups as $group)
                    {{-- The tick lives in a data attribute the custom element writes
                         itself, and Livewire's morph would strip it. Folding the state
                         into the key replaces the node instead, so it re-reads `checked`. --}}
                    <flux:menu.checkbox
                        wire:key="file-{{ $project->id }}-{{ $group->id }}-{{ (int) ($groupId === $group->id) }}"
                        :checked="$groupId === $group->id"
                        wire:click="toggleGroup({{ $project->id }}, {{ $group->id }})"
                    >{{ $group->name }}</flux:menu.checkbox>
                @endforeach

                <flux:menu.item icon="folder-plus" wire:click="startNewGroup({{ $project->id }})">
                    {{ __('groups.actions.new_group') }}
                </flux:menu.item>

                {{-- Zone 2: the owner's alone. Someone who only reads the project
                     never sees it — and the gate is checked again server-side. --}}
                @can('manage-project', $project)
                    <flux:menu.separator />

                    <flux:menu.item icon="pencil-square" wire:click="startRenamingProject({{ $project->id }})">
                        {{ __('groups.actions.rename') }}
                    </flux:menu.item>

                    <flux:menu.item variant="danger" icon="trash" wire:click="confirmDeleteProject({{ $project->id }})">
                        {{ __('groups.actions.delete_project') }}
                    </flux:menu.item>
                @endcan
            </flux:menu>
        </flux:dropdown>
    </div>
</div>
