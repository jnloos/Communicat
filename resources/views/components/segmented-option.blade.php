@props([
    'value',
    'label',
    'tooltip', {{-- what the option means, shown on hover --}}
    'disabled' => false,
])

{{-- A flux:radio of a segmented group with a tooltip: Flux's segmented radio takes none, so a tooltip wraps it and fills its slot of the group. --}}
<flux:tooltip :content="$tooltip" class="flex flex-1">
    <flux:radio :value="$value" :label="$label" :disabled="$disabled" />
</flux:tooltip>
