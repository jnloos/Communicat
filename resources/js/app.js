import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import './tour.js';

const reverbKey = import.meta.env.VITE_REVERB_APP_KEY;

if (reverbKey) {
    window.Pusher = Pusher;

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: reverbKey,
        wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 8080,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 8080,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}

document.addEventListener('alpine:init', () => {
    // Pipeline indicator: only the visible "writing" step and the post-message
    // reading countdown. Routing/thinking stages still broadcast server-side but
    // are intentionally hidden so the bubble never overlaps the info button area.
    window.Alpine.data('pipelineIndicator', (projectId) => ({
        stage: null,
        experts: [],
        countdown: 0,
        // Sticky: while the round waits for a human, nothing may animate a
        // "next contribution" — not even a MessageGenerated that arrives late or
        // out of order. Cleared only when generation actually restarts.
        awaitingUser: false,
        _countdownTimer: null,
        _channel: null,
        _handlers: {},

        init() {
            if (!window.Echo) return;

            this._channel = window.Echo.private(`projects.${projectId}`);

            this._handlers = {
                '.PipelineStageChanged': (e) => {
                    if (e.stage !== 'speaking') {
                        return;
                    }

                    this.awaitingUser = false;
                    this.clearCountdown();
                    this.stage = e.stage;
                    this.experts = e.experts ?? [];
                    window.dispatchEvent(new Event('pipeline_stage_changed'));
                },

                '.MessageGenerated': (e) => {
                    if (e.next_turn_delay_seconds > 0 && !this.awaitingUser) {
                        this.clearCountdown();
                        this.stage = 'waiting';
                        this.experts = [];
                        this.countdown = e.next_turn_delay_seconds;
                        this.startCountdown();
                        window.dispatchEvent(new Event('pipeline_stage_changed'));
                        return;
                    }

                    this.clear();
                },

                '.UserInputRequested': () => {
                    this.awaitingUser = true;
                    this.clear();
                },

                '.GenerationStopped': () => this.clear(),

                '.GenerationStarted': () => {
                    this.awaitingUser = false;
                    this.clear();
                },
            };

            for (const [event, handler] of Object.entries(this._handlers)) {
                this._channel.listen(event, handler);
            }
        },

        // Livewire morphs can re-create this component; without unbinding, every
        // re-init would stack another set of Echo listeners on the cached channel
        // and leave orphaned countdown timers behind.
        destroy() {
            this.clearCountdown();

            if (!this._channel) return;

            for (const [event, handler] of Object.entries(this._handlers)) {
                this._channel.stopListening(event, handler);
            }

            this._channel = null;
            this._handlers = {};
        },

        clearCountdown() {
            if (this._countdownTimer !== null) {
                clearInterval(this._countdownTimer);
                this._countdownTimer = null;
            }
        },

        startCountdown() {
            this.clearCountdown();
            this._countdownTimer = setInterval(() => {
                this.countdown = Math.max(0, this.countdown - 1);
                if (this.countdown <= 0) {
                    this.clear();
                }
            }, 1000);
        },

        clear() {
            this.clearCountdown();
            this.stage = null;
            this.experts = [];
            this.countdown = 0;
        },

        label() {
            if (this.stage === 'waiting') {
                return this.countdown > 0
                    ? `Next contribution in ${this.countdown}s`
                    : 'Next contribution soon';
            }

            const names = this.experts.map((e) => e.name).join(', ');
            if (this.stage === 'speaking') {
                return `${names} is writing`;
            }

            return '';
        },
    }));

    window.Alpine.store('discussionMode', {
        value: 'text',
        projectId: null,
        initForProject(projectId) {
            this.projectId = String(projectId);
            const saved = window.localStorage.getItem(this.storageKey());
            this.value = saved === 'voice' ? 'voice' : 'text';
        },
        storageKey() {
            return `discussionMode:${this.projectId ?? 'global'}`;
        },
        setMode(mode) {
            this.value = mode === 'voice' ? 'voice' : 'text';
            if (this.projectId !== null) {
                window.localStorage.setItem(this.storageKey(), this.value);
            }
        },
    });
});
