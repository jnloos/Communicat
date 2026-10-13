@props(['status'])

{{-- A job's or a call's status as a Flux badge: colour and word together, never the colour alone. --}}
@php($color = match ($status) { 'success' => 'green', 'failed' => 'red', default => 'amber' })
@php($label = match ($status) { 'success' => __('debug.status.success'), 'failed' => __('debug.status.failed'), default => __('debug.status.running') })
<flux:badge size="sm" :color="$color" {{ $attributes }}>{{ $label }}</flux:badge>
