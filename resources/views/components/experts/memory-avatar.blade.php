@props([
    'expertId',
    'name',
    'avatarUrl' => null,
    'size' => 'md',
])

{{-- Expert avatar that opens the memory flyout. Used wherever an expert avatar is clickable. --}}
<flux:tooltip :content="__('chat.message.open_thoughts')" position="top" class="shrink-0">
    <x-contributors.avatar-button
        :name="$name"
        :avatar-url="$avatarUrl"
        :size="$size"
        :aria-label="__('chat.message.show_memory', ['name' => $name])"
        x-on:click="$dispatch('open-expert-thoughts', { expertId: {{ (int) $expertId }} })"
        {{ $attributes }}
    >
        <x-slot:badge><x-icons.brain/></x-slot:badge>
    </x-contributors.avatar-button>
</flux:tooltip>
