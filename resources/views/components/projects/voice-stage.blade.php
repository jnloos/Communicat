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
@endphp

<div
    class="max-w-240 mx-auto px-6 sm:px-10 lg:px-14"
    data-last-expert-message-id="{{ $lastExpert?->id ?? '' }}"
    data-last-expert-speaker-id="{{ $lastExpert?->expert_id ?? '' }}"
    x-data="{
        speakingId: null,
        userTalking: false,
        lastPlayedId: null,
        participantsCount: {{ $participants->count() }},
        stageWidth: 920,
        audio: new Audio(),
        init() {
            window.addEventListener('message_generated', () => this.playLatest());
            window.addEventListener('user-speaking', (event) => {
                this.userTalking = Boolean(event.detail?.recording);
            });
            this.updateStageWidth();
            window.addEventListener('resize', () => this.updateStageWidth());
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
            if (!messageId || !speakerId || this.lastPlayedId === messageId) {
                return;
            }

            this.lastPlayedId = messageId;
            this.audio.src = `{{ route('messages.audio', ['message' => '__MESSAGE__']) }}`.replace('__MESSAGE__', messageId);

            this.audio.onplay = () => {
                this.speakingId = `expert-${speakerId}`;
            };
            this.audio.onended = () => {
                this.speakingId = null;
            };
            this.audio.onerror = () => {
                this.speakingId = null;
            };

            try {
                await this.audio.play();
            } catch (error) {
                this.speakingId = null;
            }
        },
        isTileSpeaking(tileId, isUser) {
            if (isUser) {
                return this.userTalking;
            }

            return this.speakingId === tileId;
        }
    }"
>
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
                        <button
                            type="button"
                            title="{{ __('Gedanken von') }} {{ $participant['name'] }}"
                            @click="$dispatch('open-expert-thoughts', { expertId: {{ $participant['expert_id'] }} })"
                            class="cursor-pointer rounded-full transition-transform hover:scale-105 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
                        >
                            <div
                                class="w-28 h-28 rounded-full flex items-center justify-center transition-all duration-200 ring-2 ring-zinc-200 dark:ring-zinc-700"
                                :class="isTileSpeaking('{{ $participant['tile_id'] }}', false)
                                    ? 'ring-4 ring-amber-400 dark:ring-amber-500 shadow-[0_0_24px_rgba(251,191,36,0.55)] scale-105 animate-pulse'
                                    : ''"
                            >
                                <x-contributors.contributors-avatar
                                    :name="$participant['name']"
                                    :avatar-url="$participant['avatar_url']"
                                    class="w-24 h-24"
                                />
                            </div>
                        </button>
                    @else
                        <div
                            class="w-28 h-28 rounded-full flex items-center justify-center transition-all duration-200 ring-2 ring-zinc-200 dark:ring-zinc-700"
                            :class="isTileSpeaking('{{ $participant['tile_id'] }}', true)
                                ? 'ring-4 ring-amber-400 dark:ring-amber-500 shadow-[0_0_24px_rgba(251,191,36,0.55)] scale-105 animate-pulse'
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
