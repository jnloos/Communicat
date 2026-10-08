{{-- The tag section of the project forms (create and edit), inside their accordion. --}}
<flux:accordion.item transition="true">
    <flux:accordion.heading>
        <div class="flex gap-2">
            <flux:icon.tag class="size-5"/> {{ __('tags.form.heading') }}
        </div>
    </flux:accordion.heading>
    <flux:accordion.content class="my-4 space-y-4">
        <flux:text size="sm">{{ __('tags.form.help') }}</flux:text>
        <livewire:tags.tag-fields scope="project" wire:model="tagSelection" />
    </flux:accordion.content>
</flux:accordion.item>
