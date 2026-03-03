@props([
    'project'
])

<x-layouts.app :title="$project->title">
    <livewire:projects.project-chat :project="$project"/>
</x-layouts.app>
