@props([
    'disableInput'         => false,
    'disableGenerate'      => false,
    'showGenerate'         => true,
    'disabledControlsHint' => null,
])

@php
    $sendTooltip = $disableInput && $disabledControlsHint ? $disabledControlsHint : __('Send your message');
    $aiRunTooltip = $disableGenerate && $disabledControlsHint ? $disabledControlsHint : __('Run expert discussion');
    $aiPauseTooltip = $disableGenerate && $disabledControlsHint ? $disabledControlsHint : __('Pause expert discussion');
@endphp

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
                        <div class="flex items-center gap-2">
                            @if($showGenerate)
                                <flux:tooltip :content="$aiRunTooltip" position="top">
                                    <flux:button
                                        type="button"
                                        size="sm"
                                        variant="filled"
                                        icon="sparkles"
                                        wire:click.debounce="startGenerate"
                                        :disabled="$disableGenerate"
                                        :aria-label="$aiRunTooltip"
                                        class="cursor-pointer"
                                    />
                                </flux:tooltip>
                            @else
                                <flux:tooltip :content="$aiPauseTooltip" position="top">
                                    <flux:button
                                        type="button"
                                        size="sm"
                                        variant="filled"
                                        icon="pause"
                                        wire:click.debounce="stopGenerate"
                                        :disabled="$disableGenerate"
                                        :aria-label="$aiPauseTooltip"
                                        class="cursor-pointer"
                                    />
                                </flux:tooltip>
                            @endif

                            <div class="h-6 w-px shrink-0 bg-zinc-200 dark:bg-zinc-600" aria-hidden="true"></div>

                            <flux:tooltip :content="$sendTooltip" position="top">
                                <flux:button
                                    type="submit"
                                    size="sm"
                                    variant="primary"
                                    icon="paper-airplane"
                                    :disabled="$disableInput"
                                    :aria-label="$sendTooltip"
                                    class="cursor-pointer"
                                />
                            </flux:tooltip>
                        </div>
                    </x-slot>
                </flux:composer>
            </form>
        </div>
    </div>
</div>
