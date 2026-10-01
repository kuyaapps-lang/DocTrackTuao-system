<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { Bell } from 'lucide-vue-next'

import { Button } from '@/components/ui/button'
import { useAuth } from '@/lib/auth'
import {
    listNotifications,
    markNotificationRead,
} from '@/lib/notifications'
import { createNotificationPoller } from '@/lib/notificationPoller'
import { listenForRealtimeInvalidation } from '@/lib/realtime'

const router = useRouter()
const { getToken, ensureCurrentUser } = useAuth()
const open = ref(false)
const loading = ref(false)
const updating = ref(false)
const error = ref('')
const notifications = ref([])
const unreadCount = ref(0)
const root = ref(null)
let poller = null
let leaveRealtime = null

const badgeLabel = computed(() => unreadCount.value > 99 ? '99+' : unreadCount.value)

const load = async ({ quiet = false } = {}) => {
    if (loading.value) return

    const token = getToken()
    if (!token) return

    loading.value = true
    if (!quiet) error.value = ''
    try {
        const data = await listNotifications({ token, limit: 12 })
        notifications.value = data.notifications
        unreadCount.value = data.unreadCount
    } catch (err) {
        if (!quiet) error.value = err.message || 'Unable to load notifications.'
    } finally {
        loading.value = false
    }
}

const toggle = async () => {
    open.value = !open.value
    if (open.value) await load()
}

const markRead = async (notification) => {
    if (notification.is_read || updating.value) return

    updating.value = true
    try {
        const updated = await markNotificationRead({
            token: getToken(),
            notificationId: notification.id,
        })
        if (updated) {
            notifications.value = notifications.value.map(item => item.id === updated.id ? updated : item)
            unreadCount.value = Math.max(0, unreadCount.value - 1)
        }
    } catch (err) {
        error.value = err.message || 'Unable to update notification.'
    } finally {
        updating.value = false
    }
}

const openNotification = async (notification) => {
    await markRead(notification)
    if (notification.link) {
        open.value = false
        await router.push(notification.link)
    }
}

const formatTime = (value) => {
    if (!value) return ''
    const date = new Date(value)
    return Number.isNaN(date.getTime()) ? '' : date.toLocaleString()
}

const outsideClick = (event) => {
    if (open.value && !root.value?.contains(event.target)) open.value = false
}

onMounted(() => {
    load()
    poller = createNotificationPoller({
        load: () => load({ quiet: true }),
        documentRef: document,
    })
    poller.start()
    ensureCurrentUser().then(user => {
        if (user) leaveRealtime = listenForRealtimeInvalidation([`doc-track.user.${user.id}`], () => load({ quiet: true }))
    }).catch(() => undefined)
    document.addEventListener('pointerdown', outsideClick)
})

onBeforeUnmount(() => {
    poller?.stop()
    leaveRealtime?.()
    document.removeEventListener('pointerdown', outsideClick)
})
</script>

<template>
    <div ref="root" class="relative">
        <Button
            type="button"
            size="icon"
            class="relative rounded-full"
            aria-label="Open notifications"
            aria-haspopup="menu"
            :aria-expanded="open"
            @click="toggle"
        >
            <Bell aria-hidden="true" class="size-5" />
            <span v-if="unreadCount" class="absolute -right-1 -top-1 flex min-w-5 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-bold text-white">{{ badgeLabel }}</span>
        </Button>

        <div v-if="open" class="absolute right-0 z-40 mt-3 w-[min(24rem,calc(100vw-2rem))] rounded-2xl border border-white/80 bg-white/95 p-3 shadow-[0_18px_42px_rgb(67_86_119/0.16)] backdrop-blur-xl" role="menu" aria-label="Notifications">
            <div class="border-b border-slate-200 px-1 pb-2">
                <p class="font-semibold text-blue-950">Notifications</p>
            </div>
            <p v-if="loading" class="px-1 py-5 text-center text-sm text-slate-500">Loading notifications…</p>
            <p v-else-if="error" class="px-1 py-4 text-sm text-red-700" role="alert">{{ error }}</p>
            <p v-else-if="!notifications.length" class="px-1 py-5 text-center text-sm text-slate-500">You have no notifications.</p>
            <ul v-else class="max-h-96 divide-y divide-slate-100 overflow-auto">
                <li v-for="notification in notifications" :key="notification.id">
                    <button type="button" class="w-full px-2 py-3 text-left transition-colors hover:bg-blue-50" :class="notification.is_read ? 'text-slate-600' : 'bg-blue-50/60 text-slate-900'" @click="openNotification(notification)">
                        <span class="block text-sm font-semibold">{{ notification.title }}</span>
                        <span class="mt-1 block text-xs leading-5">{{ notification.message }}</span>
                        <time class="mt-1 block text-[11px] text-slate-500">{{ formatTime(notification.created_at) }}</time>
                    </button>
                </li>
            </ul>
            <RouterLink to="/notifications" class="mt-2 block rounded-lg px-2 py-2 text-center text-sm font-semibold text-blue-900 hover:bg-blue-50" @click="open = false">View all notifications</RouterLink>
        </div>
    </div>
</template>
