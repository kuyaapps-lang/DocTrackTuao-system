<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuth } from '@/lib/auth'
import { listNotifications, markNotificationRead } from '@/lib/notifications'
import { ensureCurrentUser } from '@/lib/auth'
import { listenForRealtimeInvalidation } from '@/lib/realtime'

const router = useRouter()
const { getToken } = useAuth()
const notifications = ref([])
const loading = ref(true)
const updating = ref(false)
const error = ref('')

const load = async () => {
    loading.value = true
    error.value = ''
    try {
        notifications.value = (await listNotifications({ token: getToken(), limit: 50 })).notifications
    } catch (err) {
        error.value = err.message || 'Unable to load notifications.'
    } finally {
        loading.value = false
    }
}

const openNotification = async (notification) => {
    if (!notification.is_read) {
        updating.value = true
        try {
            const updated = await markNotificationRead({ token: getToken(), notificationId: notification.id })
            if (updated) notifications.value = notifications.value.map(item => item.id === updated.id ? updated : item)
        } catch (err) {
            error.value = err.message || 'Unable to update notification.'
        } finally {
            updating.value = false
        }
    }
    if (notification.link) {
        await router.push({
            path: notification.link,
            query: { notification: String(notification.id) },
        })
    }
}

const formatTime = value => value ? new Date(value).toLocaleString() : ''
let leaveRealtime = null
onMounted(async () => {
    await load()
    const user = await ensureCurrentUser().catch(() => null)
    if (user) leaveRealtime = listenForRealtimeInvalidation([`doc-track.user.${user.id}`], load)
})
onBeforeUnmount(() => leaveRealtime?.())
</script>

<template>
    <section class="min-h-screen bg-slate-100 p-4 text-slate-900 dark:bg-slate-950 dark:text-slate-100 sm:p-6" aria-labelledby="notifications-heading">
        <div class="mx-auto max-w-3xl rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
            <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-700">
                <h2 id="notifications-heading" class="text-xl font-bold text-blue-950 dark:text-slate-100">Notifications</h2>
            </div>
            <p v-if="loading" class="p-8 text-center text-slate-500 dark:text-slate-400">Loading notifications…</p>
            <p v-else-if="error" class="p-8 text-center text-red-700 dark:text-red-200" role="alert">{{ error }}</p>
            <p v-else-if="!notifications.length" class="p-8 text-center text-slate-500 dark:text-slate-400">You have no notifications.</p>
            <ul v-else class="divide-y divide-slate-200 dark:divide-slate-700">
                <li v-for="notification in notifications" :key="notification.id">
                    <button type="button" class="w-full bg-transparent px-5 py-4 text-left transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-inset" :class="notification.is_read ? 'font-normal text-slate-600 dark:text-slate-300' : 'font-semibold text-slate-900 dark:text-slate-100'" @click="openNotification(notification)">
                        <span class="block">{{ notification.title }}</span>
                        <span class="mt-1 block text-sm">{{ notification.message }}</span>
                        <time class="mt-2 block text-xs font-normal text-slate-500 dark:text-slate-400">{{ formatTime(notification.created_at) }}</time>
                    </button>
                </li>
            </ul>
        </div>
    </section>
</template>
