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
