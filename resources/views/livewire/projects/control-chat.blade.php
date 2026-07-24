@props([
    'disableInput'         => false,
    'disableGenerate'      => false,
    'disableStop'          => false,
    'showGenerate'         => true,
    'disabledControlsHint' => null,
    'userInputRequested'   => false,
    'mentionables'         => [],
])

@php
    $sendTooltip = $disableInput && $disabledControlsHint ? $disabledControlsHint : __('Send your message');
    $aiRunTooltip = $disableGenerate && $disabledControlsHint ? $disabledControlsHint : __('Run expert discussion');
    $aiPauseTooltip = $disableGenerate && $disabledControlsHint ? $disabledControlsHint : __('Pause expert discussion');
@endphp

<div
    class="fixed bottom-0 left-0 w-full lg:left-[15vw] lg:w-[85vw] z-50"
    @unless($showGenerate) wire:poll.30s="heartbeat" @endunless
    :data-mentionables="@js(json_encode(array_values($mentionables), JSON_UNESCAPED_UNICODE))"
    x-data="{
        mode: 'text',
        recording: false,
        transcript: '',
        recognition: null,
        supportsSpeech: Boolean(window.SpeechRecognition || window.webkitSpeechRecognition),
        mentionPattern: /(?:^|[\s\n])@([\p{L}\p{M}\p{Nd}_\-]*(?:[ ][\p{L}\p{M}\p{Nd}_\-]+){0,2})$/u,
        mentionOpen: false,
        mentionQuery: '',
        mentionMatches: [],
        mentionIndex: 0,
        activeInput: null,
        popEnabled: true,
        audioCtx: null,
        popListener: null,
        init() {
            this.mode = this.$store.discussionMode?.value ?? 'text';
            this.$watch('$store.discussionMode.value', (value) => {
                this.mode = value;
                if (value !== 'voice') {
                    this.stopRecording();
                }
                this.closeMention();
            });

            // Chat message 'pop' sound — opt-in, persisted per browser. Plays a
            // short blip when a new message arrives while the Text tab is open.
            this.popEnabled = localStorage.getItem('chatPop') !== '0';
            this.popListener = (event) => {
                if (!this.popEnabled) return;
                if ((this.$store.discussionMode?.value ?? 'text') !== 'text') return;
                // Pop only for messages of THIS project.
                if (String(event.detail?.projectId ?? '') !== '{{ $projectId }}') return;
                // Skip the user's OWN message: user-sent messages carry a
                // senderId; an expert/AI message carries none (→ always pops).
                if (String(event.detail?.senderId ?? '') === '{{ auth()->id() }}') return;
                if (document.visibilityState !== 'visible') return;
                this.playPop();
            };
            window.addEventListener('message_generated', this.popListener);

            if (this.supportsSpeech) {
                const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
                this.recognition = new Recognition();
                this.recognition.lang = 'de-DE';
                this.recognition.continuous = false;
                this.recognition.interimResults = true;

                this.recognition.onresult = (event) => {
                    const text = Array.from(event.results)
                        .map((result) => result[0].transcript)
                        .join(' ')
                        .trim();

                    const rewritten = this.rewriteVoiceMentions(text);

                    this.transcript = rewritten;
                    $wire.set('msgContent', rewritten);
                    window.dispatchEvent(new CustomEvent('user-speaking', {
                        detail: { recording: this.recording, transcript: this.transcript },
                    }));
                };

                this.recognition.onend = () => {
                    this.recording = false;

                    window.dispatchEvent(new CustomEvent('user-speaking', {
                        detail: { recording: false, transcript: this.transcript },
                    }));

                    // Kein Auto-Send mehr — der Nutzer bestätigt die erkannte
                    // Eingabe manuell mit dem Senden-Button im Voice-Mode.
                };
            }
        },
        startRecording() {
            if (!this.supportsSpeech || !this.recognition || this.recording) {
                return;
            }

            this.recording = true;
            this.transcript = '';
            $wire.set('msgContent', '');
            this.recognition.start();
            window.dispatchEvent(new CustomEvent('user-speaking', {
                detail: { recording: true, transcript: '' },
            }));
        },
        stopRecording() {
            if (!this.supportsSpeech || !this.recognition || !this.recording) {
                return;
            }

            this.recognition.stop();
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
        closeMention() {
            this.mentionOpen = false;
            this.mentionMatches = [];
            this.mentionQuery = '';
            this.mentionIndex = 0;
        },
        readMentionables() {
            // Read fresh from the data attribute on every call so that
            // newly-added contributors appear in the dropdown without a page
            // reload — Alpine does not re-evaluate x-data after Livewire morphs,
            // but the data-mentionables attribute IS updated by morph.
            try {
                const raw = this.$root.dataset.mentionables ?? '[]';
                const parsed = JSON.parse(raw);
                return Array.isArray(parsed) ? parsed : [];
            } catch (_) {
                return [];
            }
        },
        rewriteVoiceMentions(text) {
            // Convert spoken contributor names into @-mentions so the
            // backend PATH-A shortcut behaves identically to the text mode.
            // Longest names first (full name wins over first-name partials).
            // Lookbehind avoids double-prefixing already mentioned names;
            // Unicode-aware boundaries protect against substring hits.
            if (!text) return text;
            const mentionables = this.readMentionables();
            if (mentionables.length === 0) return text;

            const sorted = [...mentionables].sort(
                (a, b) => (b.name?.length ?? 0) - (a.name?.length ?? 0)
            );

            let out = text;
            for (const item of sorted) {
                if (!item?.name) continue;
                const escaped = item.name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                const re = new RegExp(
                    `(?<!@)(?<![\\p{L}\\p{M}\\p{Nd}])${escaped}(?![\\p{L}\\p{M}\\p{Nd}])`,
                    'giu',
                );
                out = out.replace(re, `@${item.name}`);
            }
            return out;
        },
        onComposerInput(event) {
            const el = event.target;
            if (!(el instanceof HTMLTextAreaElement) && !(el instanceof HTMLInputElement)) {
                return;
            }

            const mentionables = this.readMentionables();
            if (mentionables.length === 0) {
                this.closeMention();
                return;
            }

            this.activeInput = el;
            const cursor = el.selectionStart ?? el.value.length;
            const before = el.value.slice(0, cursor);
            const match = before.match(this.mentionPattern);

            if (!match) {
                this.closeMention();
                return;
            }

            const query = (match[1] ?? '').trim();
            const lower = query.toLowerCase();
            const matches = mentionables
                .filter((item) => {
                    return item.name.toLowerCase().includes(lower)
                        || (item.job ?? '').toLowerCase().includes(lower);
                })
                .slice(0, 6);

            if (matches.length === 0) {
                this.closeMention();
                return;
            }

            this.mentionQuery = query;
            this.mentionMatches = matches;
            this.mentionIndex = 0;
            this.mentionOpen = true;
        },
        onComposerKeydown(event) {
            if (!this.mentionOpen) {
                // Enter (without Shift) sends the message; Shift+Enter keeps the
                // newline. Only inside the composer fields, and not mid-IME.
                if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
                    const el = event.target;
                    if (!(el instanceof HTMLTextAreaElement) && !(el instanceof HTMLInputElement)) {
                        return;
                    }
                    event.preventDefault();
                    event.stopPropagation();
                    // Flush the (debounced) value before validating server-side,
                    // otherwise a fast Enter can submit a stale/empty msgContent.
                    Promise.resolve($wire.set('msgContent', el.value))
                        .then(() => $wire.sendMessage());
                }
                return;
            }
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                this.mentionIndex = (this.mentionIndex + 1) % this.mentionMatches.length;
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                this.mentionIndex = (this.mentionIndex - 1 + this.mentionMatches.length) % this.mentionMatches.length;
            } else if (event.key === 'Enter' || event.key === 'Tab') {
                event.preventDefault();
                event.stopPropagation();
                this.applyMention(this.mentionMatches[this.mentionIndex]);
            } else if (event.key === 'Escape') {
                event.preventDefault();
                this.closeMention();
            }
        },
        applyMention(item) {
            const el = this.activeInput;
            if (!item || !el) {
                this.closeMention();
                return;
            }

            const cursor = el.selectionStart ?? el.value.length;
            const before = el.value.slice(0, cursor);
            const after  = el.value.slice(cursor);
            const replaced = before.replace(this.mentionPattern, (full) => {
                const leading = (full.length > 0 && full[0] !== '@') ? full[0] : '';
                return `${leading}@${item.name} `;
            });

            const newValue  = replaced + after;
            const newCursor = replaced.length;

            el.value = newValue;
            try { el.setSelectionRange(newCursor, newCursor); } catch (_) {}

            $wire.set('msgContent', newValue);
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.focus();

            this.closeMention();
        },
    }"
    @input.capture="onComposerInput($event)"
    @keydown.capture="onComposerKeydown($event)"
    @click.outside="closeMention()"
>
    <!-- Fade: messages disappear behind control -->
    <div class="h-2 bg-linear-to-t from-white dark:from-zinc-800 to-transparent pointer-events-none"></div>
    <!-- Solid control area -->
    <div class="bg-white dark:bg-zinc-800">
        <div class="max-w-240 mx-auto pb-4 px-4 relative">
            <!-- Mention autocomplete dropdown -->
            <div
                x-show="mentionOpen"
                x-cloak
                x-transition.opacity
                class="absolute left-4 right-4 bottom-full mb-2 z-50 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-lg overflow-hidden"
                @mousedown.prevent
            >
                <ul class="max-h-60 overflow-y-auto py-1 text-sm">
                    <template x-for="(item, index) in mentionMatches" :key="item.name">
                        <li>
                            <button
                                type="button"
                                class="w-full flex items-center gap-2 px-3 py-2 text-start hover:bg-zinc-100 dark:hover:bg-zinc-800 focus:outline-none"
                                :class="index === mentionIndex ? 'bg-zinc-100 dark:bg-zinc-800' : ''"
                                @mouseenter="mentionIndex = index"
                                @click.prevent="applyMention(item)"
                            >
                                <span class="w-6 h-6 rounded-full overflow-hidden bg-zinc-200 dark:bg-zinc-700 shrink-0 flex items-center justify-center">
                                    <template x-if="item.avatar_url">
                                        <img :src="item.avatar_url" :alt="item.name" class="w-full h-full object-cover" />
                                    </template>
                                    <template x-if="!item.avatar_url">
                                        <span class="text-xs font-semibold text-zinc-600 dark:text-zinc-300" x-text="item.name.slice(0,2).toUpperCase()"></span>
                                    </template>
                                </span>
                                <span class="flex flex-col items-start min-w-0">
                                    <span class="font-medium text-zinc-900 dark:text-zinc-100 truncate" x-text="item.name"></span>
                                    <template x-if="item.job">
                                        <span class="text-xs leading-tight text-zinc-500 dark:text-zinc-400 truncate" x-text="item.job"></span>
                                    </template>
                                </span>
                            </button>
                        </li>
                    </template>
                </ul>
                <div class="px-3 py-1.5 text-xs uppercase tracking-wide text-zinc-500 border-t border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/40">
                    <span>{{ __('↑↓ wählen, Enter bestätigen, Esc abbrechen') }}</span>
                </div>
            </div>

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

            <form wire:submit="sendMessage" x-show="mode === 'text'">
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
                            <span x-show="popEnabled">
                                <flux:tooltip :content="__('Nachrichtenton ausschalten')" position="top">
                                    <flux:button
                                        type="button"
                                        size="sm"
                                        variant="subtle"
                                        icon="bell"
                                        x-on:click="togglePop()"
                                        :aria-label="__('Nachrichtenton ausschalten')"
                                        class="cursor-pointer"
                                    />
                                </flux:tooltip>
                            </span>
                            <span x-show="!popEnabled" x-cloak>
                                <flux:tooltip :content="__('Nachrichtenton einschalten')" position="top">
                                    <flux:button
                                        type="button"
                                        size="sm"
                                        variant="subtle"
                                        icon="bell-slash"
                                        x-on:click="togglePop()"
                                        :aria-label="__('Nachrichtenton einschalten')"
                                        class="cursor-pointer"
                                    />
                                </flux:tooltip>
                            </span>

                            <flux:tooltip :content="$autoplay ? __('Autoplay an: Diskussion läuft nach deiner Nachricht automatisch weiter') : __('Autoplay aus: nach deiner Nachricht musst du selbst starten')" position="top">
                                <flux:button
                                    type="button"
                                    size="sm"
                                    :variant="$autoplay ? 'filled' : 'subtle'"
                                    icon="forward"
                                    wire:click="toggleAutoplay"
                                    :aria-label="__('Autoplay umschalten')"
                                    @class(['cursor-pointer', 'text-amber-600 dark:text-amber-400' => $autoplay])
                                />
                            </flux:tooltip>

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
                                        wire:click="stopGenerate"
                                        :disabled="$disableStop"
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

            <div x-show="mode === 'voice'" class="py-4">
                <div class="space-y-3">
                    <textarea
                        wire:model.live.debounce.150ms="msgContent"
                        x-bind:readonly="recording"
                        rows="3"
                        class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 px-3 py-2 text-sm text-zinc-900 dark:text-zinc-100 focus:outline-none focus:ring-2 focus:ring-amber-400"
                        placeholder="{{ __('Dein Spracheingang erscheint hier...') }}"
                    ></textarea>

                    <div class="flex flex-wrap items-center justify-center gap-4">
                        <flux:tooltip :content="$autoplay ? __('Autoplay an: Diskussion läuft nach deiner Nachricht automatisch weiter') : __('Autoplay aus: nach deiner Nachricht musst du selbst starten')" position="top">
                            <flux:button
                                type="button"
                                size="base"
                                :variant="$autoplay ? 'filled' : 'subtle'"
                                icon="forward"
                                wire:click="toggleAutoplay"
                                :aria-label="__('Autoplay umschalten')"
                                @class(['w-14 h-14 rounded-full cursor-pointer', 'text-amber-600 dark:text-amber-400' => $autoplay])
                            />
                        </flux:tooltip>

                        @if($showGenerate)
                            <flux:tooltip :content="$aiRunTooltip" position="top">
                                <flux:button
                                    type="button"
                                    size="base"
                                    variant="filled"
                                    icon="sparkles"
                                    wire:click.debounce="startGenerate"
                                    :disabled="$disableGenerate"
                                    :aria-label="$aiRunTooltip"
                                    class="w-20 h-20 rounded-full cursor-pointer"
                                />
                            </flux:tooltip>
                        @else
                            <flux:tooltip :content="$aiPauseTooltip" position="top">
                                <flux:button
                                    type="button"
                                    size="base"
                                    variant="filled"
                                    icon="pause"
                                    wire:click="stopGenerate"
                                    :disabled="$disableStop"
                                    :aria-label="$aiPauseTooltip"
                                    class="w-20 h-20 rounded-full cursor-pointer"
                                />
                            </flux:tooltip>
                        @endif

                        <flux:tooltip :content="$sendTooltip" position="top">
                            <flux:button
                                type="button"
                                size="base"
                                variant="primary"
                                icon="paper-airplane"
                                x-on:click="$wire.sendMessage()"
                                x-bind:disabled="@js($disableInput) || !($wire.msgContent ?? '').trim()"
                                :aria-label="$sendTooltip"
                                class="w-20 h-20 rounded-full cursor-pointer"
                            />
                        </flux:tooltip>

                        <flux:button
                            x-show="supportsSpeech"
                            type="button"
                            size="base"
                            variant="primary"
                            icon="microphone"
                            x-on:click="recording ? stopRecording() : startRecording()"
                            :disabled="$disableInput"
                            class="w-20 h-20 rounded-full transition-all"
                            x-bind:class="recording ? 'animate-pulse ring-4 ring-amber-400 dark:ring-amber-500' : ''"
                        />
                    </div>

                    <div x-show="supportsSpeech" class="flex flex-col items-center gap-1">
                        <p class="text-sm text-zinc-500 dark:text-zinc-400" x-text="recording ? '{{ __('Listening... click again to stop') }}' : '{{ __('Tap the microphone and speak') }}'"></p>
                    </div>
                </div>

                <div x-show="!supportsSpeech" class="text-center text-sm text-zinc-600 dark:text-zinc-300">
                    {{ __('Spracheingabe wird vom Browser nicht unterstützt - bitte Chrome oder Edge verwenden.') }}
                    <button
                        type="button"
                        class="ms-2 underline text-amber-600 dark:text-amber-400 cursor-pointer"
                        x-on:click="$store.discussionMode.setMode('text')"
                    >
                        {{ __('Switch to text mode') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
