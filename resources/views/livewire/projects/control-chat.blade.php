@props([
    'disableInput'    => false,
    'disableGenerate' => false,
    'showGenerate'    => true,
])

<div class="fixed bottom-0 left-0 w-full lg:left-[15vw] lg:w-[85vw] justify-center z-50">
    <div class="max-w-[1120px] justify-center mx-auto pb-4">
        <div class="flex items-end gap-2">
            @if($showGenerate)
                <flux:button
                    type="button"
                    size="sm"
                    variant="subtle"
                    icon="play"
                    wire:click.debounce="startGenerate"
                    :disabled="$disableGenerate"
                />
            @else
                <flux:button
                    type="button"
                    size="sm"
                    variant="subtle"
                    icon="pause"
                    wire:click.debounce="stopGenerate"
                />
            @endif

            <form wire:submit="sendMessage" class="flex-1">
                <flux:composer
                    wire:model="msgContent"
                    rows="3"
                    max-rows="8"
                    :placeholder="__('Contribute to the specification...')"
                >
                    <x-slot name="actionsTrailing">
                        <flux:button
                            type="submit"
                            size="sm"
                            variant="primary"
                            icon="paper-airplane"
                            :disabled="$disableInput"
                        />
                    </x-slot>
                </flux:composer>
            </form>
        </div>
    </div>
</div>
