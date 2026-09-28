export const NOTIFICATION_POLL_INTERVAL_MS = 30_000

export const createNotificationPoller = ({
    load,
    documentRef = typeof document === 'undefined' ? null : document,
    setIntervalFn = setInterval,
    clearIntervalFn = clearInterval,
    intervalMs = NOTIFICATION_POLL_INTERVAL_MS,
}) => {
    let intervalId = null
    let subscribed = false

    const isVisible = () => documentRef?.visibilityState !== 'hidden'
    const refreshQuietly = () => Promise.resolve(load()).catch(() => undefined)

    const stopTimer = () => {
        if (intervalId === null) return
        clearIntervalFn(intervalId)
        intervalId = null
    }

    const startTimer = () => {
        if (!isVisible() || intervalId !== null) return
        intervalId = setIntervalFn(() => {
            if (isVisible()) refreshQuietly()
        }, intervalMs)
    }

    const handleVisibilityChange = () => {
        if (!isVisible()) {
            stopTimer()
            return
        }

        refreshQuietly()
        startTimer()
    }

    return {
        start: () => {
            if (!subscribed) {
                documentRef?.addEventListener('visibilitychange', handleVisibilityChange)
                subscribed = true
            }
            startTimer()
        },
        stop: () => {
            stopTimer()
            if (subscribed) documentRef?.removeEventListener('visibilitychange', handleVisibilityChange)
            subscribed = false
        },
    }
}
