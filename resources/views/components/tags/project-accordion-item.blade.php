@props([
    'values', {{-- Collection<TagValue> the form currently has chosen, with category loaded --}}
])

{{-- The tag section of the project forms (create and edit), inside their
     accordion. The collapsed header already shows what is set, because the
     condition a project belongs to matters too much to hide behind a click. --}}
<flux:accordion.item transition="true">
    <flux:accordion.heading>
        <div class="flex flex-1 items-center gap-2">
            <flux:icon.tag class="size-5"/> {{ __('tags.form.heading') }}

            <div class="ms-auto me-2 flex min-w-0 justify-end">
                @if ($values->isNotEmpty())
                    <x-tags.badges :values="$values" class="justify-end" />
                @else
                    <span class="text-sm font-normal text-zinc-500 dark:text-zinc-400">{{ __('tags.form.none_set') }}</span>
                @endif
            </div>
        </div>
    </flux:accordion.heading>
    <flux:accordion.content class="my-4">
        <flux:fieldset>
            <flux:description class="mb-4">{{ __('tags.form.help') }}</flux:description>
            <livewire:tags.tag-fields scope="project" wire:model="tagSelection" />
        </flux:fieldset>
    </flux:accordion.content>
</flux:accordion.item>
