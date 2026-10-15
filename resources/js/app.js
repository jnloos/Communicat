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
    // `labels` carries the translated strings rendered server-side by the
    // pipeline-indicator Blade component (writingOne, writingMany, nextIn, nextSoon).
    window.Alpine.data('pipelineIndicator', (projectId, labels = {}) => ({
        labels,
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

            channel.listen('.GenerationStopped', () => this.clear());
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
                    ? this.translate('nextIn', { seconds: this.countdown })
                    : this.translate('nextSoon');
            }

            if (this.stage === 'speaking') {
                const names = this.experts.map((e) => e.name).join(', ');
                const key = this.experts.length > 1 ? 'writingMany' : 'writingOne';
                return this.translate(key, { names });
            }

            return '';
        },

        // Resolves a server-provided label and fills Laravel-style `:placeholder`s.
        translate(key, replacements = {}) {
            return Object.entries(replacements).reduce(
                (text, [name, value]) => text.replaceAll(`:${name}`, String(value)),
                this.labels[key] ?? '',
            );
        },
    }));

    // Chat sound: a short blip whenever a new message of THIS project arrives
    // while the tab is visible. The on/off choice is remembered per browser.
    window.Alpine.data('chatSound', (projectId) => ({
        popEnabled: true,
        audioCtx: null,
        popListener: null,

        init() {
            this.popEnabled = localStorage.getItem('chatPop') !== '0';
            this.popListener = (event) => {
                if (!this.popEnabled) return;
                if (String(event.detail?.projectId ?? '') !== String(projectId)) return;
                if (document.visibilityState !== 'visible') return;
                this.playPop();
            };
            window.addEventListener('message_generated', this.popListener);
        },

        destroy() {
            window.removeEventListener('message_generated', this.popListener);
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
    }));

    // Chat scrolling: starts at the bottom, loads older messages when the
    // reader scrolls near the top, and follows new messages and the pipeline
    // indicator only while the reader is already near the bottom. Whether
    // older messages exist is read from the element's data-has-more attribute,
    // which Livewire's morph keeps current.
    window.Alpine.data('chatScroll', () => ({
        loading: false,
        locked: false,
        scrollThreshold: 300,
        followListener: null,

        init() {
            this.$nextTick(() => this.scrollToBottom());

            this.$el.addEventListener('scroll', () => this.loadOlderNearTop());

            this.followListener = () => {
                const wasNearBottom = this.isNearBottom();
                this.$nextTick(() => {
                    if (wasNearBottom) this.scrollToBottom();
                });
            };
            window.addEventListener('message_generated', this.followListener);
            window.addEventListener('pipeline_stage_changed', this.followListener);
        },

        destroy() {
            window.removeEventListener('message_generated', this.followListener);
            window.removeEventListener('pipeline_stage_changed', this.followListener);
        },

        hasMore() {
            return this.$el.dataset.hasMore === 'true';
        },

        isNearBottom() {
            const el = this.$el;
            return el.scrollHeight - el.scrollTop - el.clientHeight <= this.scrollThreshold;
        },

        scrollToBottom() {
            this.$el.scrollTop = this.$el.scrollHeight;
        },

        async loadOlderNearTop() {
            const el = this.$el;
            if (this.locked || !this.hasMore() || el.scrollTop > 50) return;

            this.locked = true;
            this.loading = true;
            const before = el.scrollHeight;

            await this.$wire.loadMore();

            this.$nextTick(() => {
                el.scrollTop = el.scrollHeight - before;
                this.loading = false;
                this.locked = false;
            });
        },
    }));
});
