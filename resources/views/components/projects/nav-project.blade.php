@props([
    'project',
    'groups',
    'current' => false,
    'outsideFilter' => false, {{-- the open project, kept in the list although the tag filter hides it --}}
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
        :title="$outsideFilter ? __('tags.filter.outside', ['title' => $project->title]) : $project->title"
        @class(['pe-9', 'opacity-60' => $outsideFilter])
        wire:navigate
    >{{ $project->title }}</flux:sidebar.item>

    <div class="absolute end-1 top-0 flex h-8 items-center opacity-0 transition-opacity has-[:focus-visible]:opacity-100 group-hover/navitem:opacity-100 has-[[data-open]]:opacity-100">
        <flux:dropdown position="bottom" align="start">
            <x-more-button :label="__('groups.menus.project')" />

            <flux:menu>
                {{-- Zone 1: everyone who reads the project files it for themselves.
                     Ticking the group it already sits in takes it back out. --}}
                {{-- The heading labels the list below it. Without a single group
                     there is no list, and it would stand alone over the entry that
                     creates the first one. --}}
                @if ($groups->isNotEmpty())
                    <flux:menu.heading>{{ __('groups.menus.file_in') }}</flux:menu.heading>
                @endif

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

                <flux:menu.item icon="folder-plus" wire:click="$dispatchTo('projects.project-group-dialogs', 'new-group', { project: {{ $project->id }} })">
                    {{ __('groups.actions.create_group') }}
                </flux:menu.item>

                {{-- Zone 2: the owner's alone. Someone who only reads the project
                     never sees it — and the gate is checked again server-side. --}}
                @can('update', $project)
                    <flux:menu.separator />

                    <flux:menu.item icon="pencil-square" wire:click="$dispatchTo('projects.project-dialogs', 'rename-project', { project: {{ $project->id }} })">
                        {{ __('groups.actions.rename') }}
                    </flux:menu.item>

                    <flux:menu.item variant="danger" icon="trash" wire:click="$dispatchTo('projects.project-dialogs', 'delete-project', { project: {{ $project->id }} })">
                        {{ __('groups.actions.delete_project') }}
                    </flux:menu.item>
                @endcan
            </flux:menu>
        </flux:dropdown>
    </div>
</div>
