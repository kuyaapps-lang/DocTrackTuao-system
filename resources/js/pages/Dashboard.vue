<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import {
    CheckCircle2,
    Filter,
    FileText,
    Inbox,
    RotateCcw,
    Send,
    Truck,
    X,
} from 'lucide-vue-next'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import DashboardSkeleton from '@/components/loaders/DashboardSkeleton.vue'
import DashboardDateRangePicker from '@/components/DashboardDateRangePicker.vue'
import { useAuth } from '@/lib/auth'
import { listenForRealtimeInvalidation } from '@/lib/realtime'
import { currentDashboardDateRange, isValidDashboardResponse } from '@/lib/dashboard'

const route = useRoute()
const router = useRouter()
const { clearCurrentUser, getToken, ensureCurrentUser } = useAuth()
const dashboard = ref(null)
const dateFrom = ref('')
const dateTo = ref('')
const defaultDateRange = currentDashboardDateRange()
const loading = ref(true)
const state = ref('loading')
let activeController = null
let requestSequence = 0
let mounted = true
let leaveRealtime = null

const metrics = computed(() => dashboard.value ? [
    { label: 'Total Documents', value: dashboard.value.summary.total_documents, icon: FileText, tone: 'from-blue-500 to-blue-700' },
    { label: 'Incoming Movements', value: dashboard.value.summary.incoming_movements, icon: Inbox, tone: 'from-emerald-500 to-emerald-700' },
    { label: 'Outgoing Movements', value: dashboard.value.summary.outgoing_movements, icon: Send, tone: 'from-orange-400 to-orange-600' },
    { label: 'In Transit', value: dashboard.value.summary.in_transit_documents, icon: Truck, tone: 'from-violet-500 to-violet-700' },
    { label: 'Received', value: dashboard.value.summary.received_documents, icon: CheckCircle2, tone: 'from-rose-500 to-rose-700' },
] : [])
const scopeLabel = computed(() => !dashboard.value ? '' : dashboard.value.scope.type === 'system' ? 'All offices' : dashboard.value.scope.office.name)
const formatDashboardDateTime = value => {
    if (!value) return 'N/A'

    const date = new Date(value)
    if (Number.isNaN(date.getTime())) return 'N/A'

    const parts = new Intl.DateTimeFormat('en-US', {
        month: '2-digit',
        day: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: true,
        timeZone: 'Asia/Manila',
    }).formatToParts(date)

    const part = type => parts.find(item => item.type === type)?.value || ''

    return `${part('month')}/${part('day')}/${part('year')} ${part('hour')}:${part('minute')} ${part('dayPeriod')}`
}
const formatDashboardDate = value => {
    const dateTime = formatDashboardDateTime(value)

    return dateTime === 'N/A' ? dateTime : dateTime.split(' ')[0]
}
const formatDashboardTime = value => {
    const dateTime = formatDashboardDateTime(value)

    return dateTime === 'N/A' ? dateTime : dateTime.split(' ').slice(1).join(' ')
}
const dateRangeLabel = computed(() => {
    if (!dashboard.value?.filters.date_from) return 'All dates'

    return dashboard.value.filters.date_from === dashboard.value.filters.date_to
        ? dashboard.value.filters.date_from
        : `${dashboard.value.filters.date_from} to ${dashboard.value.filters.date_to}`
})
const pieStyle = items => {
    const colors = ['#2563eb', '#16a34a', '#f97316', '#7c3aed', '#e11d48', '#0891b2']
    const total = items.reduce((sum, item) => sum + item.count, 0)
    if (!total) return { background: '#e2e8f0' }
    let position = 0
    const segments = items.map((item, index) => {
        const next = position + (item.count / total) * 100
        const segment = `${colors[index % colors.length]} ${position}% ${next}%`
        position = next
        return segment
    })
    return { background: `conic-gradient(${segments.join(', ')})` }
}
const pieColor = index => ['#2563eb', '#16a34a', '#f97316', '#7c3aed', '#e11d48', '#0891b2'][index % 6]
const documentStatusClass = status => {
    switch (String(status).toLowerCase()) {
        case 'received':
            return 'bg-blue-100 text-blue-700'
        case 'forwarded':
        case 'awaiting receipt':
            return 'bg-indigo-100 text-indigo-700'
        case 'pending':
            return 'bg-yellow-100 text-yellow-700'
        case 'approved':
            return 'bg-green-100 text-green-700'
        case 'completed':
            return 'bg-emerald-100 text-emerald-700'
        case 'returned':
            return 'bg-orange-100 text-orange-700'
        case 'cancelled':
            return 'bg-red-100 text-red-700'
        case 'archived':
            return 'bg-slate-100 text-slate-700'
        default:
            return 'bg-gray-100 text-gray-700'
    }
}
const routingEventLabel = eventType => eventType === 'received' ? 'Received' : 'Forwarded'
const priorityClass = priority => ({
    urgent: 'bg-red-100 text-red-700 dark:bg-red-950/70 dark:text-red-200',
    high: 'bg-orange-100 text-orange-700 dark:bg-orange-950/70 dark:text-orange-200',
    normal: 'bg-blue-100 text-blue-700 dark:bg-blue-950/70 dark:text-blue-200',
    low: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
}[String(priority).toLowerCase()] || 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200')
const isUrgent = document => String(document.priority?.name).toLowerCase() === 'urgent'

const clearLocalAuthentication = async () => {
    localStorage.removeItem('auth_token')
    localStorage.removeItem('auth_user')
    clearCurrentUser()
    await router.replace('/login')
}

const responseState = async response => {
    if (response.status !== 403) return response.status === 401 ? 'unauthorized' : 'failure'
    try {
        const body = await response.json()
        return ['Your user account is not assigned to an office.', 'Your user account is not assigned to a valid office.'].includes(body?.message) ? 'office-denied' : 'permission-denied'
    } catch {
        return 'permission-denied'
    }
}

const loadDashboard = async (from = '', to = '') => {
    activeController?.abort()
    const controller = new AbortController()
    activeController = controller
    const sequence = ++requestSequence
    loading.value = true
    state.value = 'loading'
    dashboard.value = null
    try {
        const query = new URLSearchParams()
        if (from) query.set('date_from', from)
        if (to) query.set('date_to', to)
        const response = await fetch(`/api/dashboard/summary${query.size ? `?${query}` : ''}`, {
            headers: { Accept: 'application/json', Authorization: `Bearer ${getToken()}` },
            signal: controller.signal,
        })
        if (!mounted || sequence !== requestSequence) return
        if (!response.ok) {
            const nextState = await responseState(response)
            if (!mounted || sequence !== requestSequence) return
            if (nextState === 'unauthorized') {
                await clearLocalAuthentication()
                return
            }
            dashboard.value = null
            state.value = nextState
            return
        }
        const data = await response.json()
        if (!mounted || sequence !== requestSequence) return
        if (!isValidDashboardResponse(data)
            || data.filters.month !== null
            || data.filters.date_from !== (from || null)
            || data.filters.date_to !== (to || null)) {
            dashboard.value = null
            state.value = 'failure'
            return
        }
        dashboard.value = data
        state.value = 'success'
    } catch (error) {
        if (error?.name === 'AbortError' || !mounted || sequence !== requestSequence) return
        dashboard.value = null
        state.value = 'failure'
    } finally {
        if (mounted && sequence === requestSequence) loading.value = false
    }
}

const updateDateRange = () => router.push({
    path: route.path,
    query: { date_from: dateFrom.value, date_to: dateTo.value },
})
const clearDateRange = () => {
    dateFrom.value = defaultDateRange.from
    dateTo.value = defaultDateRange.to
    return router.push({
        path: route.path,
        query: { date_from: dateFrom.value, date_to: dateTo.value },
    })
}
const retry = () => loadDashboard(dateFrom.value, dateTo.value)

watch(() => [route.query.date_from, route.query.date_to], ([from, to]) => {
    const hasCompleteRange = typeof from === 'string' && typeof to === 'string'
    dateFrom.value = hasCompleteRange ? from : defaultDateRange.from
    dateTo.value = hasCompleteRange ? to : defaultDateRange.to

    return loadDashboard(dateFrom.value, dateTo.value)
}, { immediate: true })

onMounted(() => {
    ensureCurrentUser().then(user => {
        if (!user) return
        const channel = ['Administrator', 'Records Officer'].includes(user.role?.role_name || user.role?.name)
            ? 'doc-track.documents.system'
            : `doc-track.documents.office.${user.office_id}`
        leaveRealtime = listenForRealtimeInvalidation([channel], () => loadDashboard(dateFrom.value, dateTo.value))
    }).catch(() => undefined)
})

onBeforeUnmount(() => {
    mounted = false
    requestSequence += 1
    activeController?.abort()
    leaveRealtime?.()
})
</script>

<template>
    <section class="min-h-screen bg-[#f4f7fb] p-4 text-slate-900 dark:bg-[#111827] dark:text-slate-100 sm:p-6" :aria-busy="loading" aria-labelledby="dashboard-heading">
        <div class="w-full space-y-5">
            <div class="rounded-2xl border border-slate-200 bg-white px-5 py-5 shadow-[0_10px_30px_rgb(15_41_70/0.07)] dark:border-slate-700 dark:bg-slate-900/95 dark:shadow-[0_18px_42px_rgb(0_0_0/0.35)] sm:px-7">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="flex flex-wrap items-center gap-[15pt]">
                        <h2 id="dashboard-heading" class="text-[28px] font-bold tracking-[-0.03em] text-slate-900 dark:text-white">Dashboard</h2>
                        <span class="inline-flex rounded-full bg-blue-50 px-3 py-1 text-[11pt] font-semibold text-blue-700 dark:bg-blue-950/70 dark:text-blue-200">{{ scopeLabel || 'Loading report scope...' }}</span>
                    </div>
                    <form class="flex w-full flex-wrap items-end gap-2 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/90 sm:w-auto" @submit.prevent="updateDateRange">
                        <DashboardDateRangePicker :from="dateFrom" :to="dateTo" aria-label="Dashboard date range" :disabled="loading" @update:from="dateFrom = $event" @update:to="dateTo = $event" />
                        <Button type="submit" class="border border-blue-900 bg-blue-900 text-white hover:bg-blue-950 hover:text-white" :disabled="loading || !dateFrom || !dateTo">
                            <Filter class="mr-2 h-4 w-4" />
                            Apply
                        </Button>
                        <Button type="button" variant="outline" class="dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700" :disabled="loading || (!dateFrom && !dateTo)" @click="clearDateRange">
                            <X class="mr-2 h-4 w-4" />
                            Clear
                        </Button>
                    </form>
                </div>
            </div>

            <p class="sr-only" aria-live="polite">{{ loading ? 'Loading dashboard summary.' : state === 'success' ? `Dashboard summary loaded for ${dateRangeLabel}.` : 'Dashboard summary could not be loaded.' }}</p>
            <DashboardSkeleton v-if="loading && !dashboard" />
            <div v-else-if="state !== 'success'" class="rounded-2xl border border-red-200 bg-red-50 p-6 text-center text-[13pt] text-red-800 shadow-sm dark:border-red-500/50 dark:bg-red-950/40 dark:text-red-100" role="alert">
                <p v-if="state === 'permission-denied'">You do not have permission to view dashboard reports.</p>
                <p v-else-if="state === 'office-denied'">Dashboard reporting is unavailable because your account has no valid office assignment.</p>
                <p v-else>Dashboard summary is temporarily unavailable.</p>
                <Button v-if="state === 'failure'" type="button" class="mt-4" @click="retry">
                    <RotateCcw class="mr-2 h-4 w-4" />
                    Retry
                </Button>
            </div>

            <template v-else>
                <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-blue-100 bg-blue-50 px-5 py-3 text-[11pt] font-semibold text-blue-900 dark:border-blue-400/30 dark:bg-blue-950/40 dark:text-blue-100">
                    <span>Scope: {{ scopeLabel }}</span>
                    <span class="rounded-full bg-white px-3 py-1 text-blue-700 shadow-sm dark:bg-slate-800 dark:text-blue-200">Period: {{ dateRangeLabel }}</span>
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-5">
                    <Card v-for="metric in metrics" :key="metric.label" class="gap-0 overflow-hidden border-0 bg-gradient-to-br py-0 text-white shadow-[0_12px_20px_rgb(15_41_70/0.15)]" :class="metric.tone">
                        <CardContent class="relative px-5 py-4">
                            <component :is="metric.icon" class="absolute right-4 top-5 size-14 text-white/30" aria-hidden="true" />
                            <p class="relative text-[30px] font-bold leading-none tabular-nums">{{ metric.value }}</p>
                            <p class="relative mt-3 text-[11pt] font-semibold text-white/95">{{ metric.label }}</p>
                        </CardContent>
                    </Card>
                </div>

                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900/95">
                    <div class="mb-4"><h3 class="text-lg font-bold text-slate-800 dark:text-slate-100">Document distribution</h3><p class="text-sm text-slate-500 dark:text-slate-400">Status, current office, and origin office</p></div>
                    <div class="grid gap-6 xl:grid-cols-3">
                    <Card v-for="distribution in [['Documents by status', dashboard.status_distribution, 'status'], ['Documents by current office', dashboard.current_office_distribution, 'office'], ['Documents by origin office', dashboard.origin_office_distribution, 'office']]" :key="distribution[0]" class="gap-0 overflow-hidden border-slate-200 py-0 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                        <CardHeader class="border-b border-slate-100 bg-white px-5 py-3 !pb-3 dark:border-slate-700 dark:bg-slate-800"><CardTitle class="text-[11pt] font-bold text-slate-800 dark:text-slate-100">{{ distribution[0] }}</CardTitle></CardHeader>
                        <CardContent class="px-4 pb-4 pt-3">
                            <p v-if="distribution[1].length === 0" class="py-4 text-center text-[12pt] text-gray-500 dark:text-slate-400">No matching documents for this period.</p>
                            <div v-else class="flex gap-4">
                            <div class="h-28 w-28 shrink-0 rounded-full ring-4 ring-white dark:ring-slate-800" :style="pieStyle(distribution[1])" role="img" :aria-label="`${distribution[0]} pie chart`"></div>
                            <ul class="min-w-0 flex-1 space-y-2.5">
                                <li v-for="(item, index) in distribution[1]" :key="`${distribution[0]}-${item[distribution[2]].id ?? 'none'}`" class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3">
                                    <div class="min-w-0">
                                        <div class="flex items-start gap-2 text-[12pt] leading-snug text-slate-800 dark:text-slate-200"><span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: pieColor(index) }" aria-hidden="true"></span><span class="break-words">{{ item[distribution[2]].name }}</span></div>
                                    </div>
                                    <span class="text-[12pt] font-semibold leading-none text-slate-900 dark:text-white">{{ item.count }}</span>
                                </li>
                            </ul>
                            </div>
                        </CardContent>
                    </Card>
                </div>
                </section>

                <Card class="gap-0 overflow-hidden border-slate-200 py-0 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                    <CardHeader class="border-b border-slate-100 bg-white px-5 py-[5pt] !pb-[5pt] dark:border-slate-700 dark:bg-slate-800">
                        <CardTitle class="text-[13pt] font-bold text-slate-800 dark:text-slate-100">Recent Documents</CardTitle>
                    </CardHeader>
                    <CardContent class="p-0">
                        <p v-if="dashboard.recent_documents.length === 0" class="py-8 text-center text-[10.4pt] text-gray-500 dark:text-slate-400">No documents were registered in this period.</p>
                        <div v-else class="max-h-[38rem] overflow-auto" aria-label="Recent documents in the selected reporting period">
                            <table class="w-full min-w-[58rem] border-separate border-spacing-0 text-left text-[11.5pt]">
                                <thead class="sticky top-0 bg-blue-900 text-[10.5pt] tracking-wide text-white dark:bg-blue-900 dark:text-white">
                                    <tr>
                                        <th scope="col" class="px-5 py-3 text-left font-bold">QR Code</th>
                                        <th scope="col" class="px-5 py-3 text-left font-bold">Document Details</th>
                                        <th scope="col" class="px-5 py-3 text-left font-bold">Status</th>
                                        <th scope="col" class="px-5 py-3 text-left font-bold">Priority</th>
                                        <th scope="col" class="px-5 py-3 text-center font-bold">Registered</th>
                                        <th scope="col" class="px-5 py-3 text-left font-bold">Latest Routing Activity</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white dark:divide-slate-700 dark:bg-slate-900">
                                    <tr v-for="document in dashboard.recent_documents" :key="document.id" class="hover:bg-blue-50/50 dark:hover:bg-slate-800/80" :class="isUrgent(document) ? 'bg-red-400/25 hover:bg-red-400/30 dark:bg-red-500/20 dark:hover:bg-red-500/30' : ''">
                                        <td class="px-5 py-3 align-top font-semibold text-blue-800 dark:text-blue-300">
                                            <RouterLink :to="`/documents/${document.id}`" class="outline-none hover:underline focus-visible:rounded focus-visible:ring-2 focus-visible:ring-blue-500">
                                                {{ document.qr_code || 'No QR code' }}
                                            </RouterLink>
                                        </td>
                                        <td class="px-5 py-3 align-top font-medium text-slate-800 dark:text-slate-100">{{ document.document_details }}</td>
                                        <td class="px-5 py-3 align-top"><span class="inline-flex rounded-full px-2.5 py-1 text-[10.5pt] font-semibold" :class="documentStatusClass(document.status.name)">{{ document.status.name }}</span></td>
                                        <td class="px-5 py-3 align-top"><span class="inline-flex rounded-full px-2.5 py-1 text-[10.5pt] font-semibold" :class="priorityClass(document.priority?.name)">{{ document.priority?.name || 'Unassigned' }}</span></td>
                                        <td class="px-5 py-3 text-center align-top text-slate-600 dark:text-slate-300">
                                            <time :datetime="document.created_at" class="block">
                                                <span class="block">{{ formatDashboardDate(document.created_at) }}</span>
                                                <span class="block text-[10.5pt] text-slate-500 dark:text-slate-400">{{ formatDashboardTime(document.created_at) }}</span>
                                            </time>
                                        </td>
                                        <td class="px-5 py-3 align-top">
                                            <template v-if="document.latest_routing_activity">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[10.5pt] font-semibold text-slate-800 dark:bg-slate-800 dark:text-slate-100">{{ routingEventLabel(document.latest_routing_activity.event_type) }}</span>
                                                    <time :datetime="document.latest_routing_activity.occurred_at" class="text-[10.5pt] text-slate-500 dark:text-slate-400">{{ formatDashboardDateTime(document.latest_routing_activity.occurred_at) }}</time>
                                                </div>
                                                <span class="mt-1 block font-medium text-slate-700 dark:text-slate-200">{{ document.latest_routing_activity.from_office.name }} to {{ document.latest_routing_activity.to_office.name }}</span>
                                            </template>
                                            <span v-else class="text-slate-400 dark:text-slate-500">No routing activity yet</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>
            </template>
        </div>
    </section>
</template>
