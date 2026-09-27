const notificationHeaders = (token) => ({
    Accept: 'application/json',
    Authorization: `Bearer ${token}`,
})

const responseError = async (response, fallback) => {
    const data = await response.json().catch(() => ({}))
    return new Error(data.message || fallback)
}

export const safeNotificationLink = (link) => {
    if (typeof link !== 'string' || !link.startsWith('/') || link.startsWith('//')) {
        return null
    }

    return link
}

export const listNotifications = async ({ token, limit = 20, fetchImpl = fetch }) => {
    const response = await fetchImpl(`/api/notifications?limit=${encodeURIComponent(limit)}`, {
        headers: notificationHeaders(token),
    })

    if (!response.ok) {
        throw await responseError(response, 'Unable to load notifications.')
    }

    const data = await response.json()

    return {
        notifications: Array.isArray(data.data)
            ? data.data.map(notification => ({
                ...notification,
                link: safeNotificationLink(notification.link),
            }))
            : [],
        unreadCount: Number(data.meta?.unread_count || 0),
    }
}

export const markNotificationRead = async ({ token, notificationId, fetchImpl = fetch }) => {
    const response = await fetchImpl(`/api/notifications/${encodeURIComponent(notificationId)}/read`, {
        method: 'PATCH',
        headers: notificationHeaders(token),
    })

    if (!response.ok) {
        throw await responseError(response, 'Unable to update notification.')
    }

    const data = await response.json()
    return data.notification
        ? { ...data.notification, link: safeNotificationLink(data.notification.link) }
        : null
}

export const markAllNotificationsRead = async ({ token, fetchImpl = fetch }) => {
    const response = await fetchImpl('/api/notifications/read-all', {
        method: 'POST',
        headers: notificationHeaders(token),
    })

    if (!response.ok) {
        throw await responseError(response, 'Unable to update notifications.')
    }

    return response.json()
}
