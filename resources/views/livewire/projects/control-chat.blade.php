@props([
    'disableInput'    => false,
    'disableGenerate' => false,
    'showGenerate'    => true,
])

<div class="fixed bottom-0 left-0 w-full lg:left-[15vw] lg:w-[85vw] z-50">
    <!-- Fade: messages disappear behind control -->
    <div class="h-2 bg-linear-to-t from-white dark:from-zinc-800 to-transparent pointer-events-none"></div>
    <!-- Solid control area -->
    <div class="bg-white dark:bg-zinc-800">
        <div class="max-w-240 mx-auto pb-4 px-4">
            <form wire:submit="sendMessage">
                <flux:composer
                    wire:model="msgContent"
                    rows="3"
                    max-rows="8"
                    :placeholder="__('Contribute to the specification...')"
                >
                    <x-slot name="actionsTrailing">
                        @if($showGenerate)
                            <flux:button
                                type="button"
                                size="sm"
                                icon="play"
                                wire:click.debounce="startGenerate"
                                :disabled="$disableGenerate"
                                class="cursor-pointer"
                            />
                        @else
                            <flux:button
                                type="button"
                                size="sm"
                                icon="pause"
                                wire:click.debounce="stopGenerate"
                                class="cursor-pointer"
                            />
                        @endif

                        <flux:button
                            type="submit"
                            size="sm"
                            variant="primary"
                            icon="paper-airplane"
                            :disabled="$disableInput"
                            class="ms-1 cursor-pointer"
                        />
                    </x-slot>
                </flux:composer>
            </form>
        </div>
    </div>
</div>
