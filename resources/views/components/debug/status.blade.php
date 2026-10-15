@use(App\Discussion\Values\JobStatus)
@props(['status'])

{{-- A job's status as a Flux badge: colour and word together, never the colour alone. --}}
@php($color = match ($status) { JobStatus::Success => 'green', JobStatus::Failed => 'red', JobStatus::Running => 'amber' })
@php($label = __('debug.status.'.$status->value))
<flux:badge size="sm" :color="$color" {{ $attributes }}>{{ $label }}</flux:badge>
