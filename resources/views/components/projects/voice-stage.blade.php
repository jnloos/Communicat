@props([
    'project',
    'messages',
])

@php
    $experts = $project->experts()->get();
    $users = $project->users()->get();
    $participants = $experts->map(fn($expert) => [
        'tile_id' => 'expert-'.$expert->id,
        'expert_id' => $expert->id,
        'name' => $expert->name,
        'role' => $expert->job,
        'avatar_url' => $expert->avatar_url,
        'is_expert' => true,
    ])->concat($users->map(fn($user) => [
        'tile_id' => 'user-'.$user->id,
        'expert_id' => null,
        'name' => $user->name,
        'role' => __('User'),
        'avatar_url' => $user->avatar_url,
        'is_expert' => false,
    ]));

    $lastExpert = $messages
        ->filter(fn($msg) => $msg->isExpert())
        ->last();

    // Addressed expert id from the last expert message's adjacency_partner, so
    // the voice stage can highlight them without relying on the Reverb event
    // payload (which Livewire's echo binding parses unevenly). Only an expert
    // partner highlights a tile; a user hand-back does not.
    $lastAddressedId = $lastExpert?->adjacency_partner_type === \App\Models\Expert::class
        ? $lastExpert?->adjacency_partner_id
        : null;
@endphp

<div
    class="max-w-240 mx-auto px-6 sm:px-10 lg:px-14"
    data-project-id="{{ $project->id }}"
    data-last-expert-message-id="{{ $lastExpert?->id ?? '' }}"
    data-last-expert-speaker-id="{{ $lastExpert?->expert_id ?? '' }}"
    data-last-expert-addressed-id="{{ $lastAddressedId ?? '' }}"
    x-data="{
        projectId: null,
        speakerId: null,
        addressedId: null,
        audioPlaying: false,
        userTalking: false,
        userInputRequested: false,
        lastPlayedId: null,
        participantsCount: {{ $participants->count() }},
        stageWidth: 920,
        currentAudio: null,
        channel: null,
        msgListener: null,
        speakListener: null,
        inputReqListener: null,
        inputClrListener: null,
        resizeListener: null,
        visibilityListener: null,
        init() {
            this.projectId = String(this.$root.dataset.projectId || '');

            // Hydrate the per-project lastPlayedId from sessionStorage so a tab
            // reload does not re-play the last expert message. sessionStorage
            // is tab-local, which means new tabs start clean (so A2 still
            // holds for a fresh tab on a different project).
            const stored = sessionStorage.getItem(`voiceLastPlayed:${this.projectId}`);
            this.lastPlayedId = stored ? parseInt(stored, 10) : null;

            // Cross-tab coordination: when another tab plays a message for
            // the same project, silence ourselves. Tabs listen on a shared
            // channel; the sender posts {projectId, messageId}.
            if (typeof BroadcastChannel !== 'undefined') {
                this.channel = new BroadcastChannel('communicat-audio');
                this.channel.onmessage = (ev) => {
                    if (ev.data?.projectId === this.projectId
                        && ev.data?.messageId !== this.lastPlayedId) {
                        this.stopAudio();
                    }
                };
            }

            this.msgListener = (event) => {
                // Only react to message_generated events for THIS project.
                // Avoids cross-project bleed when multiple tabs are open.
                if (event.detail?.projectId
                    && String(event.detail.projectId) !== this.projectId) {
                    return;
                }
                if (document.visibilityState !== 'visible') return;
                // Only speak when the Voice tab is actually on screen. The stage
                // is hidden via x-show (display:none) on the Text tab, so its
                // offsetParent is null there — skip audio then.
                if (this.$root.offsetParent === null) return;
                this.playLatest();
            };
            this.speakListener = (event) => {
                this.userTalking = Boolean(event.detail?.recording);
                if (this.userTalking) this.userInputRequested = false;
            };
            this.inputReqListener = () => { this.userInputRequested = true; };
            this.inputClrListener = () => { this.userInputRequested = false; };
            this.resizeListener = () => this.updateStageWidth();
            this.visibilityListener = () => {
                if (document.visibilityState !== 'visible') this.stopAudio();
            };

            window.addEventListener('message_generated', this.msgListener);
            window.addEventListener('user-speaking', this.speakListener);
            window.addEventListener('user-input-requested', this.inputReqListener);
            window.addEventListener('user-input-cleared', this.inputClrListener);
            window.addEventListener('resize', this.resizeListener);
            document.addEventListener('visibilitychange', this.visibilityListener);

            // Leaving the Voice tab cuts off any ongoing playback immediately.
            this.$watch('$store.discussionMode.value', (mode) => {
                if (mode !== 'voice') this.stopAudio();
            });

            this.updateStageWidth();
            // A2: intentionally NO playLatest() here. Audio only fires on a
            // fresh MessageGenerated event, not on mount/navigation.
        },
        destroy() {
            this.channel?.close();
            this.stopAudio();
            window.removeEventListener('message_generated', this.msgListener);
            window.removeEventListener('user-speaking', this.speakListener);
            window.removeEventListener('user-input-requested', this.inputReqListener);
            window.removeEventListener('user-input-cleared', this.inputClrListener);
            window.removeEventListener('resize', this.resizeListener);
            document.removeEventListener('visibilitychange', this.visibilityListener);
        },
        stopAudio() {
            if (this.currentAudio) {
                this.currentAudio.pause();
                this.currentAudio.src = '';
                this.currentAudio = null;
            }
            this.audioPlaying = false;
        },
        updateStageWidth() {
            this.stageWidth = this.$el.getBoundingClientRect().width || 920;
        },
        circleRadius() {
            if (this.participantsCount <= 1) return 0;

            const byCount = Math.max(120, 240 - (this.participantsCount * 10));
            const byViewport = Math.max(95, ((this.stageWidth - 220) / 2) - 90);
            return Math.min(220, byCount, byViewport);
        },
        circleDiameter() {
            const radius = this.circleRadius();
            return Math.max(320, (radius * 2) + 220);
        },
        tileStyle(index) {
            if (this.participantsCount <= 1) {
                return 'left: 50%; top: 50%; transform: translate(-50%, -50%);';
            }

            const angle = (Math.PI * 2 * index) / this.participantsCount - (Math.PI / 2);
            const radius = this.circleRadius();
            const x = Math.cos(angle) * radius;
            const y = Math.sin(angle) * radius;
            return `left: 50%; top: 50%; transform: translate(calc(-50% + ${x}px), calc(-50% + ${y}px));`;
        },
        async playLatest() {
            const messageId = parseInt(this.$root.dataset.lastExpertMessageId || '', 10);
            const speakerId = parseInt(this.$root.dataset.lastExpertSpeakerId || '', 10);
            const addressedId = parseInt(this.$root.dataset.lastExpertAddressedId || '', 10);
            if (!messageId || !speakerId || this.lastPlayedId === messageId) {
                return;
            }

            // Stop any in-flight playback before starting the next one so
            // back-to-back messages never overlap.
            this.stopAudio();

            // Sync highlight state from the DOM — the server always renders
            // the latest speaker and addressee into data-* attributes.
            this.speakerId = `expert-${speakerId}`;
            this.addressedId = addressedId ? `expert-${addressedId}` : null;

            this.lastPlayedId = messageId;
            sessionStorage.setItem(`voiceLastPlayed:${this.projectId}`, String(messageId));
            this.channel?.postMessage({
                projectId: this.projectId,
                messageId,
                ts: Date.now(),
            });

            const audio = new Audio(
                `{{ route('messages.audio', ['message' => '__MESSAGE__']) }}`.replace('__MESSAGE__', messageId)
            );
            audio.onplay = () => { this.audioPlaying = true; };
            audio.onended = () => { this.audioPlaying = false; };
            audio.onerror = () => { this.audioPlaying = false; };
            this.currentAudio = audio;

            try {
                await audio.play();
            } catch (_) {
                this.audioPlaying = false;
            }
        },
        isTileHighlighted(tileId, isUser) {
            // Highlight = ring + glow + pulse. Only active while audio plays
            // (or while the user is recording their own input).
            if (isUser) return this.userTalking;
            return this.speakerId === tileId && this.audioPlaying;
        },
        tileIsColorful(tileId, isUser) {
            // User tiles stay in color so the user always recognizes themself.
            // All other tiles are only colorful WHILE audio is playing —
            // once the sentence ends, everyone returns to grayscale until
            // the next message arrives.
            if (isUser) return true;
            if (!this.audioPlaying) return false;
            return this.speakerId === tileId || this.addressedId === tileId;
        }
    }"
>
    <div
        x-show="userInputRequested"
        x-transition.opacity
        x-cloak
        class="mx-auto mb-6 max-w-md rounded-2xl border-2 border-amber-400
               bg-amber-100 dark:bg-amber-900/40 px-5 py-3 text-center
               shadow-[0_0_30px_rgba(251,191,36,0.5)] animate-pulse"
    >
        <div class="text-sm font-semibold text-amber-900 dark:text-amber-100">
            {{ __('Deine Eingabe ist gefragt') }}
        </div>
        <div class="mt-1 text-xs text-amber-800 dark:text-amber-200">
            {{ __('Sprich oder schreibe, um die Diskussion fortzusetzen.') }}
        </div>
    </div>

    <div
        class="relative mx-auto w-full max-w-[920px] min-h-[620px]"
        :style="`height: ${circleDiameter()}px;`"
    >
        @foreach ($participants as $index => $participant)
            <div
                class="absolute w-44"
                x-bind:style="tileStyle({{ $index }})"
            >
                <div class="flex flex-col items-center text-center">
                    @if ($participant['is_expert'])
                        <div class="relative group">
                            <button
                                type="button"
                                title="{{ __('Gedanken von') }} {{ $participant['name'] }}"
                                @click="$dispatch('open-expert-thoughts', { expertId: {{ $participant['expert_id'] }} })"
                                class="cursor-pointer rounded-full transition-transform hover:scale-105 group-hover:scale-105 group-active:scale-105 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
                            >
                                <div
                                    class="voice-tile w-28 h-28 rounded-full flex items-center justify-center ring-2 ring-zinc-200 dark:ring-zinc-700"
                                    :class="isTileHighlighted('{{ $participant['tile_id'] }}', false)
                                        ? 'ring-[5px] ring-amber-400 dark:ring-amber-500 shadow-[0_0_44px_rgba(251,191,36,0.85)] scale-110 animate-pulse'
                                        : ''"
                                    :style="tileIsColorful('{{ $participant['tile_id'] }}', false)
                                        ? 'filter: grayscale(0%);'
                                        : 'filter: grayscale(100%);'"
                                >
                                    <x-contributors.contributors-avatar
                                        :name="$participant['name']"
                                        :avatar-url="$participant['avatar_url']"
                                        class="w-24 h-24"
                                    />
                                </div>
                            </button>
                            <button
                                type="button"
                                title="{{ __('Gedächtnis anzeigen') }}"
                                @click.stop="$dispatch('open-expert-thoughts', { expertId: {{ $participant['expert_id'] }} })"
                                class="absolute bottom-0 right-1 inline-flex items-center justify-center
                                       w-8 h-8 rounded-full
                                       bg-white dark:bg-zinc-800
                                       ring-2 ring-white dark:ring-zinc-800
                                       text-zinc-500 dark:text-zinc-300
                                       hover:text-amber-600 dark:hover:text-amber-400
                                       group-hover:text-amber-600 dark:group-hover:text-amber-400
                                       group-active:text-amber-600 dark:group-active:text-amber-400
                                       cursor-pointer transition-colors
                                       focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 z-10"
                                aria-label="{{ __('Gedächtnis anzeigen') }}"
                            >
                                <x-icons.brain class="w-4 h-4"/>
                            </button>
                        </div>
                    @else
                        <div
                            class="voice-tile w-28 h-28 rounded-full flex items-center justify-center ring-2 ring-zinc-200 dark:ring-zinc-700"
                            :class="isTileHighlighted('{{ $participant['tile_id'] }}', true)
                                ? 'ring-[5px] ring-amber-400 dark:ring-amber-500 shadow-[0_0_44px_rgba(251,191,36,0.85)] scale-110 animate-pulse'
                                : ''"
                        >
                            <x-contributors.contributors-avatar
                                :name="$participant['name']"
                                :avatar-url="$participant['avatar_url']"
                                class="w-24 h-24"
                            />
                        </div>
                    @endif
                    <div class="mt-3 text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ $participant['name'] }}</div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400 line-clamp-2">{{ $participant['role'] }}</div>
                </div>
            </div>
        @endforeach
    </div>
</div>
