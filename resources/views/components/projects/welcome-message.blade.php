@props([
    'project'
])

{{ __('chat.welcome.intro', ['title' => $project->title]) }}
{{ __('chat.welcome.purpose') }}
{{ __('chat.welcome.start_hint', ['button' => __('chat.composer.run')]) }}
{{ __('chat.welcome.steer_hint') }}
