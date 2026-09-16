@props([
    '$isUpdate' => false,
])

<flux:modal name="edit-expert" variant="flyout" class="w-full p-5! sm:p-8! md:w-[40rem]">
    <form wire:submit.prevent="save" class="space-y-6">
        <div class="space-y-1 pe-8">
            <flux:heading size="lg">
                {{ $isUpdate ? __('experts.editor.update') : __('experts.editor.create') }}
            </flux:heading>
            <flux:text size="sm">
                {{ __('experts.editor.subheading') }}
            </flux:text>
        </div>

        <div class="flex flex-col gap-6 sm:flex-row sm:items-start">
            {{-- Avatar --}}
            <div class="flex shrink-0 flex-col items-center gap-2">
                <input type="file" class="hidden" wire:model="avatarUpload" accept="image/*" x-ref="fileInput"/>
                <x-contributors.avatar-button
                    size="xl"
                    :name="$name"
                    :avatar-url="$avatarUrl"
                    wire:key="avatar-{{ $avatarUrl ?? 'initials-' . $name }}"
                    x-on:click="$refs.fileInput.click()"
                    :aria-label="__('experts.editor.change_avatar')"
                    wire:loading.class="opacity-60"
                    wire:target="avatarUpload"
                >
                    <x-slot:badge><flux:icon.pencil variant="micro"/></x-slot:badge>
                </x-contributors.avatar-button>
                <flux:text size="xs" class="text-center">
                    <span wire:loading.remove wire:target="avatarUpload">{{ __('experts.editor.change_avatar') }}</span>
                    <span wire:loading wire:target="avatarUpload">{{ __('experts.editor.uploading') }}</span>
                </flux:text>
                <flux:error name="avatarUpload" />
            </div>

            {{-- Identity --}}
            <div class="min-w-0 flex-1 space-y-4">
                <flux:input :label="__('common.fields.name')" wire:model.defer="name" />
                <flux:input :label="__('experts.editor.job')" wire:model.defer="job" />
            </div>
        </div>

        <flux:textarea
            :label="__('common.fields.description')"
            :description="__('experts.editor.description_help')"
            wire:model.defer="description"
            rows="8"
        />

        <div class="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:items-center sm:justify-between">
            @if ($isUpdate)
                <flux:button type="button" variant="danger" icon="trash" class="cursor-pointer"
                    wire:click="needsConfirmation('delete')">
                    {{ __('experts.editor.delete') }}
                </flux:button>
            @else
                <div class="hidden sm:block"></div>
            @endif

            <flux:button type="submit" variant="primary" class="cursor-pointer">
                {{ $isUpdate ? __('experts.editor.update') : __('experts.editor.create') }}
            </flux:button>
        </div>
    </form>
</flux:modal>
