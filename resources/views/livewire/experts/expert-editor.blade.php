@props([
    '$isUpdate' => false,
])

<flux:modal name="edit-expert" variant="flyout" class="flex w-full flex-col p-5! sm:p-8! md:w-[40rem]">
    <form wire:submit.prevent="save" class="flex flex-1 flex-col gap-6">
        <div class="flex items-start justify-between gap-4 pe-8">
            <div class="space-y-1">
                <flux:heading size="lg">
                    {{ $isUpdate ? __('experts.editor.update') : __('experts.editor.create') }}
                </flux:heading>
                <flux:text size="sm">
                    {{ __('experts.editor.subheading') }}
                </flux:text>
            </div>

            @if ($isUpdate)
                {{-- What this modal does to the expert, as opposed to what the form does. --}}
                <flux:dropdown position="bottom" align="end">
                    <flux:button variant="subtle" size="sm" icon="ellipsis-horizontal"
                        :aria-label="__('common.actions.more')" class="cursor-pointer"/>
                    <flux:menu>
                        <flux:menu.item variant="danger" icon="trash" wire:click="needsConfirmation('delete')">
                            {{ __('experts.editor.delete') }}
                        </flux:menu.item>
                    </flux:menu>
                </flux:dropdown>
            @endif
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

        <x-modal-footer>
            <flux:modal.close>
                <flux:button variant="filled" class="cursor-pointer">{{ __('common.actions.cancel') }}</flux:button>
            </flux:modal.close>
            <flux:button type="submit" variant="primary" class="cursor-pointer">
                {{ $isUpdate ? __('experts.editor.update') : __('experts.editor.create') }}
            </flux:button>
        </x-modal-footer>
    </form>
</flux:modal>
