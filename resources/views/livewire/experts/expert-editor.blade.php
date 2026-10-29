<x-flyout name="edit-expert" width="40rem" guard>
    <x-unsaved-guard modal="edit-expert" :fields="['name', 'description', 'avatarUpload', 'tagSelection', 'attributeValues']" />
    <form wire:submit.prevent="save" class="flex flex-1 flex-col gap-6">
        <x-modal-header
            :heading="! $mayEdit ? __('experts.editor.view') : ($isUpdate ? __('experts.editor.update') : __('experts.editor.create'))"
            :subheading="$mayEdit ? __('experts.editor.subheading') : __('experts.editor.read_only')"
        >
            @if ($isUpdate)
                {{-- What this modal does to the expert, as opposed to what the form does. --}}
                <x-slot:actions>
                    <flux:dropdown position="bottom" align="end">
                        <x-more-button size="sm" />
                        <flux:menu>
                            <flux:menu.item icon="arrow-down-tray" href="{{ route('experts.export', $expertId) }}">
                                {{ __('transfer.actions.export') }}
                            </flux:menu.item>
                            @if ($mayDelete)
                                <flux:menu.separator/>
                                <flux:menu.item variant="danger" icon="trash" wire:click="confirmDelete">
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
                <flux:input :label="__('common.fields.name')" wire:model.defer="name" required label:class="after:ms-1 after:text-red-600 after:content-['*'] dark:after:text-red-400" :disabled="! $mayEdit" />
            </div>
        </div>

        <flux:textarea
            :label="__('common.fields.description')"
            :description="__('experts.editor.description_help')"
            wire:model.defer="description"
            rows="8"
            :disabled="! $mayEdit"
        />

        {{-- Sections like the project forms: name, avatar and description stay in view,
             the rest folds. Both sections start closed. --}}
        @php
            $chosenTags = $tagCategories->flatMap->values->filter(fn ($value) => in_array($value->id, array_map('intval', array_filter($tagSelection ?? [])), true));
        @endphp
        @if ($definitions->isNotEmpty() || $tagCategories->isNotEmpty())
            {{-- Keyed per opening: a new key replaces the accordion, so both sections start closed for every expert. --}}
            <flux:accordion wire:key="sections-{{ $openings }}">
                @if ($definitions->isNotEmpty())
                    <flux:accordion.item transition="true">
                        <flux:accordion.heading>
                            {{-- Like the tags header: what is set shows while the section is folded, live as it is typed. --}}
                            <div
                                x-data="{
                                    ids: @js($definitions->pluck('id')->all()),
                                    get summary() { return this.ids.map((id) => String($wire.attributeValues?.[id] ?? '').trim()).filter(Boolean).join(' · ') },
                                }"
                                class="flex min-w-0 flex-1 items-center gap-2"
                            >
                                <div class="flex shrink-0 gap-2 {{ $errors->has('attributeValues.*') ? 'text-red-500 dark:text-red-400' : '' }}">
                                    <flux:icon.identification class="size-5"/> {{ __('experts.attributes.heading') }}
                                </div>
                                <span
                                    class="ms-auto me-2 min-w-0 truncate text-sm font-normal"
                                    x-bind:class="summary === '' ? 'text-zinc-500 dark:text-zinc-400' : 'text-zinc-800 dark:text-white'"
                                    x-bind:title="summary"
                                    x-text="summary === '' ? @js(__('experts.attributes.summary_none')) : summary"
                                    data-attribute-summary
                                >{{ __('experts.attributes.summary_none') }}</span>
                            </div>
                        </flux:accordion.heading>
                        <flux:accordion.content class="my-4">
                            <flux:fieldset>
                                <flux:description class="mb-4">{{ __('experts.attributes.help') }}</flux:description>
                                <x-attributes.fields :definitions="$definitions" :disabled="! $mayEdit" />
                            </flux:fieldset>
                        </flux:accordion.content>
                    </flux:accordion.item>
                @endif

                @if ($tagCategories->isNotEmpty())
                    <x-tags.project-accordion-item :values="$chosenTags" :categories="$tagCategories" :disabled="! $mayEdit" />
                @endif
            </flux:accordion>
        @endif

        <x-modal-footer>
            @if ($mayEdit)
                <flux:button variant="filled" x-on:click="$store.unsaved.request('edit-expert')">{{ __('common.actions.cancel') }}</flux:button>
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
</x-flyout>
