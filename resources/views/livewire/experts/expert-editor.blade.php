@props([
    '$isUpdate' => false,
    'voices' => [],
])

<flux:modal name="edit-expert" variant="flyout" class="md:w-96">
    <form wire:submit.prevent="save" class="space-y-6">
        <flux:heading size="lg">
            {{ $isUpdate ? __('Update Expert') : __('Create Expert') }}
        </flux:heading>

        <div class="flex justify-center items-center text-center">
            <input type="file" class="hidden" wire:model="avatarUpload" accept="image/*" x-ref="fileInput"/>
            <div @click="$refs.fileInput.click()" class="cursor-pointer">
                @if (!is_null($avatarUrl))
                    <flux:avatar circle src="{!! $avatarUrl !!}" alt="{{ $name }} Avatar" class="cut-avatar w-32 h-32" wire:key="avatar-{{  $avatarUrl }}">
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
        </div>

        <flux:input :label="__('Name')" wire:model.defer="name" />
        <flux:input :label="__('Job')" wire:model.defer="job" />
        <flux:input
            :label="__('Tags')"
            :description="__('Comma-separated, e.g. Engineering, AI, Design')"
            wire:model.defer="tagsInput"
        />
        <flux:textarea :label="__('Description')" wire:model.defer="description" rows="10" />
        <flux:textarea :label="__('Prompt')" wire:model.defer="prompt" rows="10" />

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <flux:select
                :label="__('Geschlecht')"
                wire:model.live="voiceGender"
                class="sm:col-span-1"
            >
                <flux:select.option value="female">{{ __('Weiblich') }}</flux:select.option>
                <flux:select.option value="male">{{ __('Männlich') }}</flux:select.option>
            </flux:select>

            <flux:select
                :label="__('Stimme')"
                wire:model.defer="voiceId"
                class="sm:col-span-2"
            >
                <flux:select.option value="">{{ __('— keine Stimme —') }}</flux:select.option>
                @foreach ($voices as $voice)
                    <flux:select.option value="{{ $voice['id'] }}">{{ $voice['label'] }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <flux:spacer/>
        <div class="flex items-center justify-between">
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
