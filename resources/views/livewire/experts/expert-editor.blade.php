<flux:modal name="edit-expert" variant="flyout" class="flex w-full flex-col p-5! sm:p-8! md:w-[40rem]">
    <form wire:submit.prevent="save" class="flex flex-1 flex-col gap-6">
        <x-modal-header
            :heading="! $mayEdit ? __('experts.editor.view') : ($isUpdate ? __('experts.editor.update') : __('experts.editor.create'))"
            :subheading="$mayEdit ? __('experts.editor.subheading') : __('experts.editor.read_only')"
        >
            @if ($isUpdate)
                {{-- What this modal does to the expert, as opposed to what the form does. --}}
                <x-slot:actions>
                    <flux:dropdown position="bottom" align="end">
                        <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal"
                            :aria-label="__('common.actions.more')"
                            class="text-zinc-400! hover:text-zinc-800! dark:text-zinc-500! dark:hover:text-white!"/>
                        <flux:menu>
                            <flux:menu.item icon="arrow-down-tray" href="{{ route('experts.export', $expertId) }}">
                                {{ __('transfer.actions.export') }}
                            </flux:menu.item>
                            @if ($mayDelete)
                                <flux:menu.separator/>
                                <flux:menu.item variant="danger" icon="trash" wire:click="needsConfirmation('delete')">
                                    {{ __('experts.editor.delete') }}
                                </flux:menu.item>
                            @endif
                        </flux:menu>
                    </flux:dropdown>
                </x-slot:actions>
            @endif
        </x-modal-header>

        <div class="flex flex-col gap-6 sm:flex-row sm:items-start">
            {{-- Avatar --}}
            @if (! $mayEdit)
                <x-contributors.contributors-avatar :name="$name" :avatar-url="$avatarUrl" class="size-28 shrink-0 self-center sm:self-start"/>
            @else
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
            @endif

            {{-- Identity --}}
            <div class="min-w-0 flex-1 space-y-4">
                <flux:input :label="__('common.fields.name')" wire:model.defer="name" :disabled="! $mayEdit" />
                <flux:input :label="__('experts.editor.role')" wire:model.defer="role" :disabled="! $mayEdit" />
            </div>
        </div>

        <flux:textarea
            :label="__('common.fields.description')"
            :description="__('experts.editor.description_help')"
            wire:model.defer="description"
            rows="8"
            :disabled="! $mayEdit"
        />

        @if ($tagCategories->isNotEmpty())
            <flux:fieldset :legend="__('tags.form.heading')" :description="__('tags.form.help')">
                <x-tags.fields :categories="$tagCategories" :disabled="! $mayEdit" />
            </flux:fieldset>
        @endif

        <x-modal-footer>
            @if ($mayEdit)
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('common.actions.cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">
                    {{ $isUpdate ? __('experts.editor.update') : __('experts.editor.create') }}
                </flux:button>
            @else
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('common.actions.close') }}</flux:button>
                </flux:modal.close>
            @endif
        </x-modal-footer>
    </form>
</flux:modal>
