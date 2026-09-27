import test from 'node:test'
import assert from 'node:assert/strict'

import {
    listNotifications,
    markAllNotificationsRead,
    markNotificationRead,
    safeNotificationLink,
} from '../../resources/js/lib/notifications.js'

const jsonResponse = (body, ok = true) => ({
    ok,
    json: async () => body,
})

test('notification links accept internal routes only', () => {
    assert.equal(safeNotificationLink('/documents/8'), '/documents/8')
    assert.equal(safeNotificationLink('https://example.test'), null)
    assert.equal(safeNotificationLink('//example.test'), null)
    assert.equal(safeNotificationLink(null), null)
})

test('notification API helpers send authenticated requests and normalize links', async () => {
    const calls = []
    const fetchImpl = async (url, options = {}) => {
        calls.push([url, options])
        if (url.startsWith('/api/notifications?')) {
            return jsonResponse({
                data: [
                    { id: 1, link: '/qr-codes', is_read: false },
                    { id: 2, link: 'https://unsafe.test', is_read: true },
                ],
                meta: { unread_count: 1 },
            })
        }
        if (url === '/api/notifications/1/read') return jsonResponse({ notification: { id: 1, link: '/qr-codes', is_read: true } })
        return jsonResponse({ meta: { unread_count: 0 } })
    }

    const listed = await listNotifications({ token: 'token', limit: 12, fetchImpl })
    assert.equal(listed.unreadCount, 1)
    assert.equal(listed.notifications[1].link, null)
    assert.equal(calls[0][0], '/api/notifications?limit=12')
    assert.equal(calls[0][1].headers.Authorization, 'Bearer token')

    const updated = await markNotificationRead({ token: 'token', notificationId: 1, fetchImpl })
    assert.equal(updated.is_read, true)
    assert.equal(calls[1][1].method, 'PATCH')

    await markAllNotificationsRead({ token: 'token', fetchImpl })
    assert.equal(calls[2][0], '/api/notifications/read-all')
    assert.equal(calls[2][1].method, 'POST')
})
