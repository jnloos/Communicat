@props([
    'disableGenerate'      => false,
    'disableStop'          => false,
    'showGenerate'         => true,
    'disabledControlsHint' => null,
])

@php
    $aiRunTooltip = $disableGenerate && $disabledControlsHint ? $disabledControlsHint : __('chat.controls.run');
    $aiPauseTooltip = $disableGenerate && $disabledControlsHint ? $disabledControlsHint : __('chat.controls.pause');
    $debugTooltip = __('chat.controls.debug').' — '.__('chat.controls.coming_soon');
    $statsTooltip = __('chat.controls.stats').' — '.__('chat.controls.coming_soon');
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
            {{-- Same shell the composer used (p-2, rounded-2xl, shadow-xl): the bar
                 reads as the composer minus its text field, not as a leftover. --}}
            <div
                class="flex items-center gap-2 rounded-2xl bg-white p-2 shadow-xl dark:bg-zinc-900 [&_[data-flux-button]]:rounded-lg"
                role="toolbar"
                aria-label="{{ __('chat.controls.aria_label') }}"
            >
                {{-- Secondary controls: view settings and the panels still to come. --}}
                <span x-show="popEnabled">
                    <flux:tooltip :content="__('chat.controls.sound_off')" position="top">
                        <flux:button
                            type="button"
                            size="sm"
                            variant="subtle"
                            icon="bell"
                            x-on:click="togglePop()"
                            :aria-label="__('chat.controls.sound_off')"
                            class="cursor-pointer"
                        />
                    </flux:tooltip>
                </span>
                <span x-show="!popEnabled" x-cloak>
                    <flux:tooltip :content="__('chat.controls.sound_on')" position="top">
                        <flux:button
                            type="button"
                            size="sm"
                            variant="subtle"
                            icon="bell-slash"
                            x-on:click="togglePop()"
                            :aria-label="__('chat.controls.sound_on')"
                            class="cursor-pointer"
                        />
                    </flux:tooltip>
                </span>

                <div class="h-6 w-px shrink-0 bg-zinc-200 dark:bg-zinc-600" aria-hidden="true"></div>

                {{-- Placeholders: wired up in a later step, disabled until then. --}}
                {-- The span keeps the tooltip reachable: a disabled button fires no hover. --}
                <span>
                    <flux:tooltip :content="$debugTooltip" position="top">
                        <flux:button
                            type="button"
                            size="sm"
                            variant="subtle"
                            icon="bug-ant"
                            disabled
                            :aria-label="$debugTooltip"
                        />
                    </flux:tooltip>
                </span>

                {-- The span keeps the tooltip reachable: a disabled button fires no hover. --}
                <span>
                    <flux:tooltip :content="$statsTooltip" position="top">
                        <flux:button
                            type="button"
                            size="sm"
                            variant="subtle"
                            icon="chart-bar"
                            disabled
                            :aria-label="$statsTooltip"
                        />
                    </flux:tooltip>
                </span>

                {{-- The one action on this screen: it gets the free space and a label. --}}
                <div class="flex-1"></div>

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
                            class="cursor-pointer"
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
                            class="cursor-pointer"
                        >
                            <span class="hidden sm:inline">{{ __('chat.controls.pause_label') }}</span>
                        </flux:button>
                    </flux:tooltip>
                @endif
            </div>
        </div>
    </div>
</div>
