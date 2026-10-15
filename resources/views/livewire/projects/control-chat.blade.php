@php
    $aiRunTooltip = $disableGenerate && $disabledControlsHint ? $disabledControlsHint : __('chat.controls.run');
    $aiPauseTooltip = $disableGenerate && $disabledControlsHint ? $disabledControlsHint : __('chat.controls.pause');
@endphp

<div
    class="relative z-10 shrink-0 bg-white dark:bg-zinc-800"
    @unless($showGenerate) wire:poll.30s="heartbeat" @endunless
    @if($awaitTurnEnd) wire:poll.2s @endif
    x-data="chatSound({{ $projectId }})"
>
    {{-- Fade: messages disappear behind the control bar --}}
    <div class="pointer-events-none absolute inset-x-0 -top-6 h-6 bg-linear-to-t from-white to-transparent dark:from-zinc-800"></div>
    <div>
        <div class="mx-auto w-full max-w-3xl px-3 pb-3 sm:px-6 sm:pb-5">
            @if ($notice)
                <flux:callout variant="warning" icon="exclamation-triangle" class="mb-2" role="status">
                    <flux:callout.text>{{ $notice }}</flux:callout.text>
                </flux:callout>
            @endif
            {{-- Three zones with equal outer columns. Only a grid puts the primary
                 action in the optical centre: with justify-between it would sit left
                 of centre, because the right zone holds two buttons and the left one.
                 The shell (rounded-2xl, shadow-xl) is the composer's, so the bar reads
                 as its successor rather than as a leftover. --}}
            <div
                class="grid grid-cols-[1fr_auto_1fr] items-center gap-2 rounded-2xl bg-white px-4 py-2 shadow-xl dark:bg-zinc-900 [&_[data-flux-button]]:rounded-lg"
                role="toolbar"
                aria-label="{{ __('chat.controls.aria_label') }}"
            >
                {{-- Left: view settings. --}}
                <div class="flex items-center justify-start">
                    <flux:button
                        :tooltip="__('chat.controls.sound_off')"
                        type="button"
                        size="sm"
                        variant="subtle"
                        icon="bell"
                        x-show="popEnabled"
                        x-on:click="togglePop()"
                        :aria-label="__('chat.controls.sound_off')"
                    />
                    <flux:button
                        :tooltip="__('chat.controls.sound_on')"
                        type="button"
                        size="sm"
                        variant="subtle"
                        icon="bell-slash"
                        x-show="!popEnabled"
                        x-cloak
                        x-on:click="togglePop()"
                        :aria-label="__('chat.controls.sound_on')"
                    />
                </div>

                {{-- Centre: the one action on this screen. Both states share a minimum
                     width so the bar does not jump when the label switches. --}}
                <div class="flex items-center justify-center">
                    @if($showGenerate)
                        <flux:button
                            :tooltip="$aiRunTooltip"
                            type="button"
                            size="sm"
                            variant="primary"
                            icon="sparkles"
                            wire:click.debounce="startGenerate"
                            :disabled="$disableGenerate"
                            :aria-label="$aiRunTooltip"
                            class="sm:min-w-36"
                        >
                            <span class="hidden sm:inline">{{ __('chat.controls.start_label') }}</span>
                        </flux:button>
                    @else
                        <flux:button
                            :tooltip="$aiPauseTooltip"
                            type="button"
                            size="sm"
                            variant="primary"
                            icon="pause"
                            wire:click="stopGenerate"
                            :disabled="$disableStop"
                            :aria-label="$aiPauseTooltip"
                            class="sm:min-w-36"
                        >
                            <span class="hidden sm:inline">{{ __('chat.controls.pause_label') }}</span>
                        </flux:button>
                    @endif
                </div>

                {{-- Right: the two panels beside the chat. The debug panel appears only
                     with app.debug on; the statistics panel is always there. --}}
                <div class="flex items-center justify-end gap-2">
                    @if (config('app.debug'))
                        <flux:button
                            :tooltip="__('chat.controls.debug')"
                            type="button"
                            size="sm"
                            variant="subtle"
                            icon="bug-ant"
                            x-on:click="$dispatch('open-job-debug')"
                            :aria-label="__('chat.controls.debug')"
                        />
                    @endif
                    <flux:button
                        :tooltip="__('chat.controls.stats')"
                        type="button"
                        size="sm"
                        variant="subtle"
                        icon="chart-bar"
                        x-on:click="$dispatch('open-statistics')"
                        :aria-label="__('chat.controls.stats')"
                    />
                </div>
            </div>
        </div>
    </div>
</div>
