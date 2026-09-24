@props([
    'project'
])

{{ __('chat.welcome.intro', ['title' => $project->title]) }}
{{ __('chat.welcome.purpose') }}
{{ __('chat.welcome.start_hint', ['button' => __('chat.controls.start_label')]) }}
{{ __('chat.welcome.observer_hint') }}
