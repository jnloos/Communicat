@props([
    'project'
])

<x-layouts.app :title="$project->title" flush>
    <livewire:projects.project-chat :project="$project"/>
</x-layouts.app>
