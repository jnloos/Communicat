import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

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
        _countdownTimer: null,

        init() {
            if (!window.Echo) return;

            const channel = window.Echo.private(`projects.${projectId}`);

            channel.listen('.PipelineStageChanged', (e) => {
                if (e.stage !== 'speaking') {
                    return;
                }

                this.clearCountdown();
                this.stage = e.stage;
                this.experts = e.experts ?? [];
                window.dispatchEvent(new Event('pipeline_stage_changed'));
            });

            channel.listen('.MessageGenerated', (e) => {
                if (e.next_turn_delay_seconds > 0) {
                    this.clearCountdown();
                    this.stage = 'waiting';
                    this.experts = [];
                    this.countdown = e.next_turn_delay_seconds;
                    this.startCountdown();
                    window.dispatchEvent(new Event('pipeline_stage_changed'));
                    return;
                }

                this.clear();
            });

            const clear = () => this.clear();
            channel.listen('.GenerationStopped', clear);
            channel.listen('.UserInputRequested', clear);
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
