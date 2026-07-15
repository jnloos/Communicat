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
    // Thinking bubble / typing indicator fed by the PipelineStageChanged
    // broadcasts. Lives entirely client-side: Livewire is not involved, the
    // bubble is display-only sugar while a turn generates.
    window.Alpine.data('pipelineIndicator', (projectId) => ({
        stage: null,
        experts: [],

        init() {
            if (!window.Echo) return;

            const channel = window.Echo.private(`projects.${projectId}`);

            channel.listen('.PipelineStageChanged', (e) => {
                this.stage = e.stage;
                this.experts = e.experts ?? [];
                // Lets the chat scroll container keep the bubble in view.
                window.dispatchEvent(new Event('pipeline_stage_changed'));
            });

            const clear = () => {
                this.stage = null;
                this.experts = [];
            };
            channel.listen('.MessageGenerated', clear);
            channel.listen('.GenerationStopped', clear);
            channel.listen('.UserInputRequested', clear);
        },

        label() {
            const names = this.experts.map((e) => e.name).join(', ');
            switch (this.stage) {
                case 'routing':
                    return 'Moderator is choosing the next speaker';
                case 'thinking':
                    return this.experts.length > 1 ? `${names} are thinking` : `${names} is thinking`;
                case 'speaking':
                    return `${names} is writing`;
                default:
                    return '';
            }
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
