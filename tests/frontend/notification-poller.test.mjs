import assert from 'node:assert/strict'
import test from 'node:test'
import {
    NOTIFICATION_POLL_INTERVAL_MS,
    createNotificationPoller,
} from '../../resources/js/lib/notificationPoller.js'

const createDocument = (visibilityState = 'visible') => {
    const listeners = new Map()
    return {
        visibilityState,
        addEventListener: (event, callback) => listeners.set(event, callback),
        removeEventListener: event => listeners.delete(event),
        emit: event => listeners.get(event)?.(),
        listenerCount: () => listeners.size,
    }
}

test('notification polling starts every 30 seconds only for a visible tab', () => {
    const documentRef = createDocument()
    const timers = []
    const poller = createNotificationPoller({
        load: () => undefined,
        documentRef,
        setIntervalFn: (callback, interval) => {
            timers.push({ callback, interval })
            return 5
        },
        clearIntervalFn: () => undefined,
    })

    poller.start()
    assert.equal(timers.length, 1)
    assert.equal(timers[0].interval, NOTIFICATION_POLL_INTERVAL_MS)
})

test('visibility changes pause polling and refresh once when the tab returns', async () => {
    const documentRef = createDocument()
    let loads = 0
    let cleared = 0
    const timers = []
    const poller = createNotificationPoller({
        load: () => { loads += 1 },
        documentRef,
        setIntervalFn: callback => {
            timers.push(callback)
            return timers.length
        },
        clearIntervalFn: () => { cleared += 1 },
    })

    poller.start()
    documentRef.visibilityState = 'hidden'
    documentRef.emit('visibilitychange')
    assert.equal(cleared, 1)

    documentRef.visibilityState = 'visible'
    documentRef.emit('visibilitychange')
    await Promise.resolve()
    assert.equal(loads, 1)
    assert.equal(timers.length, 2)
})

test('polling refreshes unread data without duplicate timers and cleans up', async () => {
    const documentRef = createDocument()
    let loads = 0
    let cleared = 0
    let timerCallback = null
    const poller = createNotificationPoller({
        load: () => { loads += 1 },
        documentRef,
        setIntervalFn: callback => {
            timerCallback = callback
            return 9
        },
        clearIntervalFn: () => { cleared += 1 },
    })

    poller.start()
    poller.start()
    timerCallback()
    await Promise.resolve()
    assert.equal(loads, 1)

    poller.stop()
    assert.equal(cleared, 1)
    assert.equal(documentRef.listenerCount(), 0)
})
