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
