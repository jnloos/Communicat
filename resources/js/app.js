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

document.addEventListener('livewire:navigated', () => window.applyStoredSidebarWidth?.());

document.addEventListener('alpine:init', () => {
    // Unsaved-changes guard for flyouts (x-flyout guard + x-unsaved-guard): each
    // guarded flyout registers a reader of its form's state when it opens; any
    // way out (Escape, a click outside, X, Cancel) asks here first, and only a
    // dirty form opens the shared "Discard changes?" dialog.
    window.Alpine.store('unsaved', {
        readers: {},
        baselines: {},
        pending: null,
        escapeClosedPopover: false,

        // Escape with a "..." menu open must close only the menu. The browser closes
        // the popover and still sends the dialog its cancel event, so note at keydown
        // (capture, before anything closes) whether a popover was open in a dialog.
        init() {
            document.addEventListener('keydown', (event) => {
                if (event.key !== 'Escape') return;
                this.escapeClosedPopover = document.querySelector('dialog[open] :popover-open') !== null;
                setTimeout(() => (this.escapeClosedPopover = false));
            }, true);
        },

        track(name, read) {
            this.readers[name] = read;
            this.baselines[name] = JSON.stringify(read());
        },

        isDirty(name) {
            const read = this.readers[name];
            return !!read && JSON.stringify(read()) !== this.baselines[name];
        },

        // With an event (the dialog's cancel) a clean form is left alone, so the
        // browser closes the dialog itself; without one a clean form is closed here.
        request(name, event = null) {
            if (event && this.escapeClosedPopover) {
                event.preventDefault();
                return;
            }

            if (!this.isDirty(name)) {
                if (!event) window.Flux.modal(name).close();
                return;
            }

            event?.preventDefault();
            this.pending = name;
            window.Flux.modal('discard-changes').show();
        },

        discard() {
            const name = this.pending;
            this.pending = null;
            window.Flux.modal('discard-changes').close();
            if (name) window.Flux.modal(name).close();
        },
    });

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

    // Sidebar width: dragged on the handle, kept as --sidebar-width on <html>.
    // Limits come from app.css (--sidebar-min/-max), measured through a probe so
    // rem and vw resolve like the layout does. Only a drag's end touches storage;
    // no Livewire request is made.
    window.Alpine.data('sidebarResize', (storageKey) => ({
        now: 0,
        min: 0,
        max: 0,
        step: 16,
        dragging: false,

        init() {
            this.sync();
        },

        px(cssValue) {
            const probe = document.createElement('div');
            probe.style.cssText = `position:absolute;visibility:hidden;width:${cssValue}`;
            document.body.appendChild(probe);
            const width = probe.getBoundingClientRect().width;
            probe.remove();
            return Math.round(width);
        },

        sync() {
            this.min = this.px('var(--sidebar-min)');
            this.max = Math.max(this.min, this.px('var(--sidebar-max)'));
            const sidebar = document.querySelector('[data-flux-sidebar]');
            this.now = sidebar ? Math.round(sidebar.getBoundingClientRect().width) : this.min;
        },

        set(width, persist) {
            this.now = Math.round(Math.min(this.max, Math.max(this.min, width)));
            document.documentElement.style.setProperty('--sidebar-width', `${this.now}px`);
            if (persist) this.save();
        },

        save() {
            try {
                if (this.now <= this.min) localStorage.removeItem(storageKey);
                else localStorage.setItem(storageKey, String(this.now));
            } catch (e) {}
        },

        reset() {
            this.sync();
            this.set(this.min, true);
        },

        start(event) {
            if (event.button !== 0) return;
            this.sync();
            this.dragging = true;
            this.$el.setPointerCapture(event.pointerId);
            this.$el.dataset.dragging = '';
            document.documentElement.dataset.sidebarResizing = '';
            event.preventDefault();
        },

        drag(event) {
            if (this.dragging) this.set(event.clientX, false);
        },

        stop(event) {
            if (!this.dragging) return;
            this.dragging = false;
            delete this.$el.dataset.dragging;
            delete document.documentElement.dataset.sidebarResizing;
            if (this.$el.hasPointerCapture(event.pointerId)) this.$el.releasePointerCapture(event.pointerId);
            this.save();
        },

        key(event) {
            const target = {
                ArrowLeft: this.now - this.step,
                ArrowRight: this.now + this.step,
                Home: this.min,
                End: this.max,
            }[event.key];
            if (target === undefined) return;
            event.preventDefault();
            this.set(target, true);
        },
    }));
});
