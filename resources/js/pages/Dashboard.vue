<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useAuth } from '@/lib/auth'
import { buildDashboardQuery, buildDashboardRequestUrl, calculateDashboardPercentage, currentDashboardMonth, dashboardRequestKey, isValidDashboardResponse, normalizeDashboardMonth } from '@/lib/dashboard'

const route = useRoute()
const router = useRouter()
const { clearCurrentUser, getToken } = useAuth()
const dashboard = ref(null)
const selectedMonth = ref('')
const loading = ref(true)
const state = ref('loading')
let activeController = null
let requestSequence = 0
let mounted = true
let allTimeRequested = false

const metrics = computed(() => dashboard.value ? [
    ['Total Documents', dashboard.value.summary.total_documents],
    ['Incoming Movements', dashboard.value.summary.incoming_movements],
    ['Outgoing Movements', dashboard.value.summary.outgoing_movements],
    ['In Transit', dashboard.value.summary.in_transit_documents],
    ['Received', dashboard.value.summary.received_documents],
] : [])
const scopeLabel = computed(() => !dashboard.value ? '' : dashboard.value.scope.type === 'system' ? 'All offices' : dashboard.value.scope.office.name)
const formatDashboardMonth = month => {
    if (!month) return 'All time'

    const [year, rawMonth] = month.split('-').map(value => Number.parseInt(value, 10))
    if (!year || !rawMonth) return month

    return new Date(Date.UTC(year, rawMonth - 1, 1))
        .toLocaleDateString('en-US', {
            month: 'long',
            year: 'numeric',
            timeZone: 'UTC',
        })
}
const formatDashboardDateTime = value => {
    if (!value) return 'N/A'

    const date = new Date(value)
    if (Number.isNaN(date.getTime())) return 'N/A'

    const parts = new Intl.DateTimeFormat('en-US', {
        month: 'long',
        day: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: true,
        timeZone: 'Asia/Manila',
    }).formatToParts(date)

    const part = type => parts.find(item => item.type === type)?.value || ''

    return `${part('month')} ${part('day')}, ${part('year')} ${part('hour')}:${part('minute')} ${part('dayPeriod')}`
}
const monthLabel = computed(() => formatDashboardMonth(dashboard.value?.filters.month))
const maxCount = items => Math.max(1, ...items.map(item => item.count))
const barPercentage = (count, items) => calculateDashboardPercentage(count, maxCount(items))

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

const loadDashboard = async month => {
    activeController?.abort()
    const controller = new AbortController()
    activeController = controller
    const sequence = ++requestSequence
    loading.value = true
    state.value = 'loading'
    dashboard.value = null
    try {
        const response = await fetch(buildDashboardRequestUrl(month), {
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
        if (!isValidDashboardResponse(data) || dashboardRequestKey(data.filters.month) !== dashboardRequestKey(month)) {
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

const updateMonth = () => router.push({ path: route.path, query: buildDashboardQuery(selectedMonth.value) })
const clearMonth = () => {
    allTimeRequested = true
    selectedMonth.value = ''
    return updateMonth()
}
const retry = () => loadDashboard(normalizeDashboardMonth(route.query.month))

watch(() => route.query.month, async rawMonth => {
    if (rawMonth === undefined && !allTimeRequested) {
        const defaultMonth = currentDashboardMonth()
        if (defaultMonth !== null) {
            await router.replace({ path: route.path, query: buildDashboardQuery(defaultMonth) })
            return
        }
    }
    allTimeRequested = false
    const month = normalizeDashboardMonth(rawMonth)
    selectedMonth.value = month || ''
    if (rawMonth !== undefined && month === null) {
        await router.replace({ path: route.path, query: {} })
        return
    }
    loadDashboard(month)
}, { immediate: true })

onBeforeUnmount(() => {
    mounted = false
    requestSequence += 1
    activeController?.abort()
})
</script>

<template>
    <section class="min-h-screen bg-slate-100 p-4 sm:p-6" :aria-busy="loading" aria-labelledby="dashboard-heading">
        <div class="mx-auto max-w-7xl space-y-6">
            <div class="rounded-lg border border-blue-100 bg-white p-5 shadow-sm">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 id="dashboard-heading" class="text-2xl font-bold text-blue-950">Dashboard Summary</h2>
                        <p class="mt-1 text-sm text-gray-600">{{ scopeLabel || 'Loading report scope...' }}</p>
                    </div>
                    <form class="flex flex-wrap items-end gap-2" @submit.prevent="updateMonth">
                        <label class="text-sm font-semibold text-gray-700">Reporting month
                            <input v-model="selectedMonth" type="month" class="mt-1 block h-10 rounded-md border border-gray-300 bg-white px-3 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-600" :disabled="loading">
                        </label>
                        <Button type="submit" :disabled="loading">Apply</Button>
                        <Button type="button" variant="outline" :disabled="loading || !selectedMonth" @click="clearMonth">Clear</Button>
                    </form>
                </div>
            </div>

            <p class="sr-only" aria-live="polite">{{ loading ? 'Loading dashboard summary.' : state === 'success' ? `Dashboard summary loaded for ${monthLabel}.` : 'Dashboard summary could not be loaded.' }}</p>
            <div v-if="loading && !dashboard" class="rounded-lg border bg-white p-10 text-center text-gray-600">Loading dashboard summary...</div>
            <div v-else-if="state !== 'success'" class="rounded-lg border border-red-200 bg-red-50 p-6 text-center text-red-800" role="alert">
                <p v-if="state === 'permission-denied'">You do not have permission to view dashboard reports.</p>
                <p v-else-if="state === 'office-denied'">Dashboard reporting is unavailable because your account has no valid office assignment.</p>
                <p v-else>Dashboard summary is temporarily unavailable.</p>
                <Button v-if="state === 'failure'" type="button" class="mt-4" @click="retry">Retry</Button>
            </div>

            <template v-else>
                <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-900">
                    <span class="font-semibold">Scope: {{ scopeLabel }}</span>
                    <span>Reporting period: {{ monthLabel }}</span>
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-5">
                    <Card v-for="metric in metrics" :key="metric[0]" class="overflow-hidden border-blue-100 bg-white py-0"><CardHeader class="bg-blue-900 px-4 py-3 text-white"><CardTitle class="text-sm">{{ metric[0] }}</CardTitle></CardHeader><CardContent class="px-4 py-5"><p class="text-3xl font-bold text-gray-900">{{ metric[1] }}</p></CardContent></Card>
                </div>

                <div class="grid gap-6 xl:grid-cols-3">
                    <Card v-for="distribution in [['Documents by status', dashboard.status_distribution, 'status'], ['Documents by current office', dashboard.current_office_distribution, 'office'], ['Documents by origin office', dashboard.origin_office_distribution, 'office']]" :key="distribution[0]" class="overflow-hidden border-blue-100 py-0">
                        <CardHeader class="bg-emerald-700 px-5 py-4 text-white"><CardTitle>{{ distribution[0] }}</CardTitle></CardHeader>
                        <CardContent>
                            <p v-if="distribution[1].length === 0" class="py-6 text-center text-sm text-gray-500">No matching documents for this period.</p>
                            <ul v-else class="space-y-4">
                                <li v-for="item in distribution[1]" :key="`${distribution[0]}-${item[distribution[2]].id ?? 'none'}`">
                                    <div class="mb-1 flex justify-between gap-3 text-sm"><span>{{ item[distribution[2]].name }}</span><span class="font-semibold">{{ item.count }}</span></div>
                                    <div class="h-2 overflow-hidden rounded bg-gray-200" role="progressbar" :aria-label="`${item[distribution[2]].name}: ${item.count}`" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="barPercentage(item.count, distribution[1])"><div class="h-full rounded bg-blue-600" :style="{ width: `${barPercentage(item.count, distribution[1])}%` }" /></div>
                                </li>
                            </ul>
                        </CardContent>
                    </Card>
                </div>

                <div class="grid gap-6 xl:grid-cols-[minmax(0,0.8fr)_minmax(0,1.4fr)]">
                    <Card class="overflow-hidden border-blue-100 py-0"><CardHeader class="bg-sky-700 px-5 py-4 text-white"><CardTitle>Recent Documents</CardTitle></CardHeader><CardContent class="px-4 py-4">
                        <p v-if="dashboard.recent_documents.length === 0" class="py-6 text-center text-sm text-gray-500">No documents were registered in this period.</p>
                        <div v-else class="max-h-72 max-w-full overflow-auto"><Table><caption class="sr-only">Recent documents in the selected reporting period</caption><TableHeader><TableRow><TableHead scope="col">Tracking no.</TableHead><TableHead scope="col">Status</TableHead><TableHead scope="col">Created</TableHead></TableRow></TableHeader><TableBody><TableRow v-for="document in dashboard.recent_documents" :key="document.id"><TableCell class="whitespace-nowrap font-medium">{{ document.tracking_no }}</TableCell><TableCell>{{ document.status.name }}</TableCell><TableCell class="whitespace-nowrap"><time :datetime="document.created_at">{{ formatDashboardDateTime(document.created_at) }}</time></TableCell></TableRow></TableBody></Table></div>
                    </CardContent></Card>
                    <Card class="overflow-hidden border-blue-100 py-0"><CardHeader class="bg-indigo-800 px-5 py-4 text-white"><CardTitle>Recent Routing Activity</CardTitle></CardHeader><CardContent class="px-4 py-4">
                        <p v-if="dashboard.recent_routing_activity.length === 0" class="py-8 text-center text-sm text-gray-500">No routing activity was recorded in this period.</p>
                        <div v-else class="max-w-full overflow-x-auto"><Table><caption class="sr-only">Recent routing activity in the selected reporting period</caption><TableHeader><TableRow><TableHead scope="col">Document</TableHead><TableHead scope="col">Event</TableHead><TableHead scope="col">Route</TableHead><TableHead scope="col">Time</TableHead></TableRow></TableHeader><TableBody><TableRow v-for="(activity, index) in dashboard.recent_routing_activity" :key="`${activity.document.id}-${activity.event_type}-${activity.occurred_at}-${index}`"><TableCell class="whitespace-nowrap font-medium">{{ activity.document.tracking_no }}</TableCell><TableCell class="capitalize">{{ activity.event_type }}</TableCell><TableCell class="min-w-72">{{ activity.from_office.name }} to {{ activity.to_office.name }}</TableCell><TableCell class="whitespace-nowrap"><time :datetime="activity.occurred_at">{{ formatDashboardDateTime(activity.occurred_at) }}</time></TableCell></TableRow></TableBody></Table></div>
                    </CardContent></Card>
                </div>
            </template>
        </div>
    </section>
</template>
