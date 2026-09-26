@props([
    'disableGenerate'      => false,
    'disableStop'          => false,
    'showGenerate'         => true,
    'disabledControlsHint' => null,
])

@php
    $aiRunTooltip = $disableGenerate && $disabledControlsHint ? $disabledControlsHint : __('chat.controls.run');
    $aiPauseTooltip = $disableGenerate && $disabledControlsHint ? $disabledControlsHint : __('chat.controls.pause');
@endphp

<div
    class="relative z-10 shrink-0 bg-white dark:bg-zinc-800"
    @unless($showGenerate) wire:poll.30s="heartbeat" @endunless
    x-data="{
        popEnabled: true,
        audioCtx: null,
        popListener: null,
        init() {
            // Chat message 'pop' sound, persisted per browser. Plays a short
            // blip when a new message arrives.
            this.popEnabled = localStorage.getItem('chatPop') !== '0';
            this.popListener = (event) => {
                if (!this.popEnabled) return;
                // Pop only for messages of THIS project.
                if (String(event.detail?.projectId ?? '') !== '{{ $projectId }}') return;
                if (document.visibilityState !== 'visible') return;
                this.playPop();
            };
            window.addEventListener('message_generated', this.popListener);
        },
        destroy() {
            if (this.popListener) {
                window.removeEventListener('message_generated', this.popListener);
            }
        },
        playPop() {
            try {
                const Ctx = window.AudioContext || window.webkitAudioContext;
                if (!Ctx) return;
                this.audioCtx = this.audioCtx || new Ctx();
                const ctx = this.audioCtx;
                if (ctx.state === 'suspended') ctx.resume();
                const now = ctx.currentTime;
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(440, now);
                osc.frequency.exponentialRampToValueAtTime(760, now + 0.06);
                gain.gain.setValueAtTime(0.0001, now);
                gain.gain.exponentialRampToValueAtTime(0.22, now + 0.012);
                gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.18);
                osc.connect(gain).connect(ctx.destination);
                osc.start(now);
                osc.stop(now + 0.2);
            } catch (e) {}
        },
        togglePop() {
            this.popEnabled = !this.popEnabled;
            localStorage.setItem('chatPop', this.popEnabled ? '1' : '0');
            if (this.popEnabled) this.playPop(); // audible feedback on enable
        },
    }"
>
    <!-- Fade: messages disappear behind the control bar -->
    <div class="pointer-events-none absolute inset-x-0 -top-6 h-6 bg-linear-to-t from-white to-transparent dark:from-zinc-800"></div>
    <div>
        <div class="mx-auto w-full max-w-3xl px-3 pb-3 sm:px-6 sm:pb-5">
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
                    <flux:tooltip :content="__('chat.controls.sound_off')" position="top">
                        <flux:button
                            type="button"
                            size="sm"
                            variant="subtle"
                            icon="bell"
                            x-show="popEnabled"
                            x-on:click="togglePop()"
                            :aria-label="__('chat.controls.sound_off')"
                            class="cursor-pointer"
                        />
                    </flux:tooltip>
                    <flux:tooltip :content="__('chat.controls.sound_on')" position="top">
                        <flux:button
                            type="button"
                            size="sm"
                            variant="subtle"
                            icon="bell-slash"
                            x-show="!popEnabled"
                            x-cloak
                            x-on:click="togglePop()"
                            :aria-label="__('chat.controls.sound_on')"
                            class="cursor-pointer"
                        />
                    </flux:tooltip>
                </div>

                {{-- Centre: the one action on this screen. Both states share a minimum
                     width so the bar does not jump when the label switches. --}}
                <div class="flex items-center justify-center">
                    @if($showGenerate)
                        <flux:tooltip :content="$aiRunTooltip" position="top">
                            <flux:button
                                type="button"
                                size="sm"
                                variant="primary"
                                icon="sparkles"
                                wire:click.debounce="startGenerate"
                                :disabled="$disableGenerate"
                                :aria-label="$aiRunTooltip"
                                class="cursor-pointer sm:min-w-36"
                            >
                                <span class="hidden sm:inline">{{ __('chat.controls.start_label') }}</span>
                            </flux:button>
                        </flux:tooltip>
                    @else
                        <flux:tooltip :content="$aiPauseTooltip" position="top">
                            <flux:button
                                type="button"
                                size="sm"
                                variant="primary"
                                icon="pause"
                                wire:click="stopGenerate"
                                :disabled="$disableStop"
                                :aria-label="$aiPauseTooltip"
                                class="cursor-pointer sm:min-w-36"
                            >
                                <span class="hidden sm:inline">{{ __('chat.controls.pause_label') }}</span>
                            </flux:button>
                        </flux:tooltip>
                    @endif
                </div>

                {{-- Right: the panels still to come, disabled until they are wired up. --}}
                <div class="flex items-center justify-end gap-2">
                    @if (config('app.debug'))
                        <flux:tooltip :content="__('chat.controls.debug')" position="top">
                            <flux:button
                                type="button"
                                size="sm"
                                variant="subtle"
                                icon="bug-ant"
                                x-on:click="$dispatch('open-job-debug')"
                                :aria-label="__('chat.controls.debug')"
                                class="cursor-pointer"
                            />
                        </flux:tooltip>
                    @endif
                    <flux:tooltip :content="__('chat.controls.stats')" position="top">
                        <flux:button
                            type="button"
                            size="sm"
                            variant="subtle"
                            icon="chart-bar"
                            disabled
                            :aria-label="__('chat.controls.stats')"
                        />
                    </flux:tooltip>
                </div>
            </div>
        </div>
    </div>
</div>
