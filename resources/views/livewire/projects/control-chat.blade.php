@props([
    'disableInput'         => false,
    'disableGenerate'      => false,
    'disableStop'          => false,
    'showGenerate'         => true,
    'disabledControlsHint' => null,
])

@php
    $sendTooltip = $disableInput && $disabledControlsHint ? $disabledControlsHint : __('chat.composer.send');
    $aiRunTooltip = $disableGenerate && $disabledControlsHint ? $disabledControlsHint : __('chat.composer.run');
    $aiPauseTooltip = $disableGenerate && $disabledControlsHint ? $disabledControlsHint : __('chat.composer.pause');
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
                // Skip the user's OWN message: user-sent messages carry a
                // senderId; an expert/AI message carries none (→ always pops).
                if (String(event.detail?.senderId ?? '') === '{{ auth()->id() }}') return;
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
        onComposerKeydown(event) {
            // Enter (without Shift) sends the message; Shift+Enter keeps the
            // newline. Only inside the composer fields, and not mid-IME.
            if (event.key !== 'Enter' || event.shiftKey || event.isComposing) return;
            const el = event.target;
            if (!(el instanceof HTMLTextAreaElement) && !(el instanceof HTMLInputElement)) return;
            event.preventDefault();
            event.stopPropagation();
            // Flush the (debounced) value before validating server-side,
            // otherwise a fast Enter can submit a stale/empty msgContent.
            Promise.resolve($wire.set('msgContent', el.value))
                .then(() => $wire.sendMessage());
        },
    }"
    @keydown.capture="onComposerKeydown($event)"
>
    <!-- Fade: messages disappear behind the composer -->
    <div class="pointer-events-none absolute inset-x-0 -top-6 h-6 bg-linear-to-t from-white to-transparent dark:from-zinc-800"></div>
    <div>
        <div class="mx-auto w-full max-w-3xl px-3 pb-3 sm:px-6 sm:pb-5">
            <form wire:submit="sendMessage">
                <flux:composer
                    wire:model.live.debounce.300ms="msgContent"
                    rows="2"
                    max-rows="8"
                    :placeholder="__('chat.composer.placeholder')"
                >
                    <x-slot name="actionsTrailing">
                        <div class="flex items-center gap-2">
                            <span x-show="popEnabled">
                                <flux:tooltip :content="__('chat.composer.sound_off')" position="top">
                                    <flux:button
                                        type="button"
                                        size="sm"
                                        variant="subtle"
                                        icon="bell"
                                        x-on:click="togglePop()"
                                        :aria-label="__('chat.composer.sound_off')"
                                        class="cursor-pointer"
                                    />
                                </flux:tooltip>
                            </span>
                            <span x-show="!popEnabled" x-cloak>
                                <flux:tooltip :content="__('chat.composer.sound_on')" position="top">
                                    <flux:button
                                        type="button"
                                        size="sm"
                                        variant="subtle"
                                        icon="bell-slash"
                                        x-on:click="togglePop()"
                                        :aria-label="__('chat.composer.sound_on')"
                                        class="cursor-pointer"
                                    />
                                </flux:tooltip>
                            </span>

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
                                    >
                                        <span class="hidden sm:inline">{{ __('chat.composer.start_label') }}</span>
                                    </flux:button>
                                </flux:tooltip>
                            @else
                                <flux:tooltip :content="$aiPauseTooltip" position="top">
                                    <flux:button
                                        type="button"
                                        size="sm"
                                        variant="filled"
                                        icon="pause"
                                        wire:click="stopGenerate"
                                        :disabled="$disableStop"
                                        :aria-label="$aiPauseTooltip"
                                        class="cursor-pointer"
                                    >
                                        <span class="hidden sm:inline">{{ __('chat.composer.pause_label') }}</span>
                                    </flux:button>
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
