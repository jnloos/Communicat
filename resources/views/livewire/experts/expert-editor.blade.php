@props([
    '$isUpdate' => false,
])

<flux:modal name="edit-expert" variant="flyout" class="md:w-[52rem]">
    <form wire:submit.prevent="save" class="space-y-6">
        <div class="space-y-1">
            <flux:heading size="lg">
                {{ $isUpdate ? __('Update Expert') : __('Create Expert') }}
            </flux:heading>
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                {{ __('Define this expert\'s identity.') }}
            </flux:text>
        </div>

        {{-- Avatar --}}
        <div class="flex flex-col items-center gap-3">
            <input type="file" class="hidden" wire:model="avatarUpload" accept="image/*" x-ref="fileInput"/>
            <button type="button" @click="$refs.fileInput.click()"
                class="group rounded-full cursor-pointer transition-transform hover:scale-105
                       focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 focus-visible:ring-offset-2"
                wire:loading.class="opacity-60" wire:target="avatarUpload">
                <div class="rounded-full ring-2 ring-transparent group-hover:ring-amber-400 transition-shadow">
                    @if (!is_null($avatarUrl))
                        <flux:avatar circle src="{!! $avatarUrl !!}" alt="{{ $name }} Avatar" class="cut-avatar w-32 h-32" wire:key="avatar-{{ $avatarUrl }}">
                            <x-slot:badge class="h-8 w-8 translate-y-4">
                                <flux:icon.pencil/>
                            </x-slot:badge>
                        </flux:avatar>
                    @else
                        <flux:avatar circle name="{{ $name }}" color="auto" color:seed="{{ $name }}" class="cut-avatar w-32 h-32" wire:key="initials-{{ $name }}">
                            <x-slot:badge class="h-8 w-8 translate-y-4">
                                <flux:icon.pencil/>
                            </x-slot:badge>
                        </flux:avatar>
                    @endif
                </div>
            </button>
            <flux:text size="xs" class="text-zinc-400 dark:text-zinc-500">
                <span wire:loading.remove wire:target="avatarUpload">{{ __('Click to change avatar') }}</span>
                <span wire:loading wire:target="avatarUpload">{{ __('Uploading…') }}</span>
            </flux:text>
        </div>

        <flux:accordion transition>
            {{-- Identity --}}
            <x-accordion-section :heading="__('Identity')" icon="identification" :error-fields="['name', 'job', 'description']">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <flux:input :label="__('Name')" wire:model.defer="name" />
                    <flux:input :label="__('Job')" wire:model.defer="job" />
                </div>
                <flux:textarea
                    :label="__('Description')"
                    :description="__('Wird in der UI angezeigt und ist der Persona-Kern im Prompt.')"
                    wire:model.defer="description"
                    rows="4"
                />
            </x-accordion-section>
        </flux:accordion>

        <div class="flex items-center justify-between pt-2">
            @if ($isUpdate)
                <flux:button type="button" variant="danger" class="cursor-pointer"
                    wire:click="needsConfirmation('delete')">
                    {{ __('Delete Expert') }}
                </flux:button>
            @else
                <div></div>
            @endif

            <flux:button type="submit" variant="primary" class="cursor-pointer">
                {{ $isUpdate ? __('Update Expert') : __('Create Expert') }}
            </flux:button>
        </div>
    </form>
</flux:modal>
