import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

let echo = null

export const realtimeEnabled = () => Boolean(import.meta.env.VITE_REVERB_APP_KEY)

const getEcho = () => {
    if (echo || !realtimeEnabled()) return echo

    const token = localStorage.getItem('auth_token')
    if (!token) return null

    window.Pusher = Pusher
    echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT || 80),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT || 443),
        forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
        enabledTransports: ['ws', 'wss'],
        authEndpoint: '/broadcasting/auth',
        auth: { headers: { Authorization: `Bearer ${token}` } },
    })
    return echo
}

// Events contain no record content. Pages reload only their own authorized API data.
export const listenForRealtimeInvalidation = (channels, handler) => {
    const client = getEcho()
    if (!client) return () => undefined

    const unique = [...new Set(channels.filter(Boolean))]
    unique.forEach(channel => client.private(channel).listen('.doc-track.invalidated', handler))

    return () => unique.forEach(channel => client.leave(channel))
}
