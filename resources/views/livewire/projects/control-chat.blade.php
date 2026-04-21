@props([
    'disableInput'         => false,
    'disableGenerate'      => false,
    'showGenerate'         => true,
    'disabledControlsHint' => null,
    'userInputRequested'   => false,
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
            @if ($userInputRequested)
                <div class="mb-2 flex items-center gap-2 text-sm text-amber-700 dark:text-amber-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/>
                        <path d="M12 8v4"/>
                        <path d="M12 16h.01"/>
                    </svg>
                    {{ __('Deine Eingabe ist gefragt.') }}
                </div>
            @endif

            <form wire:submit="sendMessage">
                <div @class([
                    'rounded-lg transition-shadow',
                    'ring-2 ring-amber-400 dark:ring-amber-500 shadow-[0_0_0_4px_rgba(251,191,36,0.15)] animate-pulse' => $userInputRequested,
                ])>
                <flux:composer
                    wire:model.live.debounce.300ms="msgContent"
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
                </div>
            </form>
        </div>
    </div>
</div>
