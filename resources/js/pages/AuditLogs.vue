<script setup>
import { computed, onMounted, ref } from 'vue'
import {
    ChevronLeft,
    ChevronRight,
    Activity,
    Filter,
    Layers,
    ScrollText,
    X,
} from 'lucide-vue-next'

import { Button } from '@/components/ui/button'
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card'
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table'
import { useAuth } from '@/lib/auth'
import TableSkeleton from '@/components/loaders/TableSkeleton.vue'

const modules = [
    ['authentication', 'Authentication'],
    ['users', 'Users'],
    ['documents', 'Documents'],
    ['document_routing', 'Document Routing'],
    ['document_processing', 'Document Processing'],
    ['qr_codes', 'QR Codes'],
    ['attachments', 'Attachments'],
]

const actions = [
    ['login', 'Login'],
    ['logout', 'Logout'],
    ['created', 'Created'],
    ['updated', 'Updated'],
    ['deleted', 'Deleted'],
    ['forwarded', 'Forwarded'],
    ['received', 'Received'],
    ['processing_updated', 'Processing Updated'],
    ['generated', 'Generated'],
    ['registered', 'Registered'],
    ['voided', 'Voided'],
    ['uploaded', 'Uploaded'],
]

const { ensureCurrentUser, getToken } = useAuth()
const auditLogs = ref([])
const loading = ref(true)
const error = ref('')
const forbidden = ref(false)
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)
const filters = ref({ module: '', action: '' })
const appliedFilters = ref({ module: '', action: '' })

const paginationState = computed(() => ({
    canGoPrevious: page.value > 1,
    canGoNext: page.value < lastPage.value,
    previousPage: Math.max(1, page.value - 1),
    nextPage: Math.min(lastPage.value, page.value + 1),
}))

const paginationItems = computed(() => {
    const finalPage = Math.max(1, lastPage.value)
    const currentPage = Math.min(Math.max(1, page.value), finalPage)

    if (finalPage <= 7) {
        return Array.from(
            { length: finalPage },
            (_, index) => ({ type: 'page', page: index + 1 })
        )
    }

    const pages = [1, currentPage - 1, currentPage, currentPage + 1, finalPage]
        .filter(item => item >= 1 && item <= finalPage)
        .filter((item, index, items) => items.indexOf(item) === index)
        .sort((left, right) => left - right)

    return pages.flatMap((item, index) => {
        const previous = pages[index - 1]
        const ellipsis = previous && item - previous > 1
            ? [{ type: 'ellipsis', key: `ellipsis-${previous}-${item}` }]
            : []

        return [...ellipsis, { type: 'page', page: item }]
    })
})

const fetchAuditLogs = async (requestedPage = 1) => {
    loading.value = true
    error.value = ''
    forbidden.value = false

    try {
        await ensureCurrentUser()

        const parameters = new URLSearchParams({
            page: String(requestedPage),
            per_page: '25',
        })

        if (appliedFilters.value.module) {
            parameters.set('module', appliedFilters.value.module)
        }

        if (appliedFilters.value.action) {
            parameters.set('action', appliedFilters.value.action)
        }

        const response = await fetch(`/api/audit-logs?${parameters.toString()}`, {
            headers: {
                Accept: 'application/json',
                Authorization: `Bearer ${getToken()}`,
            },
        })
        const data = await response.json()

        if (!response.ok) {
            forbidden.value = response.status === 403
            throw new Error(
                response.status === 403
                    ? 'You do not have permission to view audit logs.'
                    : data.message || 'Unable to load audit logs.'
            )
        }

        auditLogs.value = data.data || []
        page.value = data.current_page || 1
        lastPage.value = data.last_page || 1
        total.value = data.total || 0
    } catch (err) {
        error.value = err.message || 'Unable to load audit logs.'
        auditLogs.value = []
    } finally {
        loading.value = false
    }
}

const applyFilters = () => {
    appliedFilters.value = { ...filters.value }
    fetchAuditLogs(1)
}

const clearFilters = () => {
    filters.value = { module: '', action: '' }
    appliedFilters.value = { ...filters.value }
    fetchAuditLogs(1)
}

const changePage = (requestedPage) => {
    if (loading.value || requestedPage < 1 || requestedPage > lastPage.value) {
        return
    }

    fetchAuditLogs(requestedPage)
}

const formatDate = (value) => {
    if (!value) {
        return 'N/A'
    }

    return new Intl.DateTimeFormat('en-PH', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value))
}

const displayValue = (value) => {
    return String(value || '')
        .replaceAll('_', ' ')
        .replace(/\b\w/g, character => character.toUpperCase())
}

onMounted(() => {
    fetchAuditLogs()
})
</script>

<template>
    <div class="min-h-screen bg-slate-100">
        <div class="border-b border-white/80 bg-white px-6 py-4 shadow-[0_4px_14px_rgb(92_113_138/0.07)]">
            <h1 class="text-2xl font-bold text-gray-800">Audit Logs</h1>
            <p class="mt-1 text-sm text-gray-500">
                Review recorded system activity in newest-first order.
            </p>
        </div>

        <div class="space-y-4 p-6">
            <Card class="relative overflow-hidden !bg-white before:pointer-events-none before:absolute before:inset-x-0 before:top-0 before:h-6 before:bg-blue-900">
                <CardHeader class="bg-blue-900 text-white">
                    <CardTitle class="flex items-center gap-2">
                        <Filter class="h-5 w-5" />
                        Filters
                    </CardTitle>
                </CardHeader>
                <CardContent class="bg-white">
                    <form
                        class="grid gap-4 md:grid-cols-[1fr_1fr_auto] md:items-end"
                        @submit.prevent="applyFilters"
                    >
                        <label class="block text-sm font-semibold text-gray-700">
                            Module
                            <span class="relative mt-2 block">
                                <span class="pointer-events-none absolute left-2 top-1/2 inline-flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-full bg-blue-100 text-blue-800">
                                    <Layers class="h-4 w-4" />
                                </span>
                                <select
                                    v-model="filters.module"
                                    class="h-11 w-full rounded-md border border-gray-300 bg-white pl-12 pr-3 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                                    :disabled="loading"
                                >
                                    <option value="">All modules</option>
                                    <option
                                        v-for="option in modules"
                                        :key="option[0]"
                                        :value="option[0]"
                                    >
                                        {{ option[1] }}
                                    </option>
                                </select>
                            </span>
                        </label>

                        <label class="block text-sm font-semibold text-gray-700">
                            Action
                            <span class="relative mt-2 block">
                                <span class="pointer-events-none absolute left-2 top-1/2 inline-flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-full bg-emerald-100 text-emerald-800">
                                    <Activity class="h-4 w-4" />
                                </span>
                                <select
                                    v-model="filters.action"
                                    class="h-11 w-full rounded-md border border-gray-300 bg-white pl-12 pr-3 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                                    :disabled="loading"
                                >
                                    <option value="">All actions</option>
                                    <option
                                        v-for="option in actions"
                                        :key="option[0]"
                                        :value="option[0]"
                                    >
                                        {{ option[1] }}
                                    </option>
                                </select>
                            </span>
                        </label>

                        <div class="flex gap-2">
                            <Button type="submit" class="bg-blue-900 text-[11.5pt] text-white hover:bg-blue-950 hover:text-white" :disabled="loading">
                                <Filter class="mr-2 h-4 w-4" />
                                Apply
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                class="bg-black text-white hover:bg-black/90 hover:text-white"
                                :disabled="loading"
                                @click="clearFilters"
                            >
                                <X class="mr-2 h-4 w-4" />
                                Clear
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>

            <Card class="relative overflow-hidden !bg-white before:pointer-events-none before:absolute before:inset-x-0 before:top-0 before:h-6 before:bg-blue-900">
                <CardHeader class="bg-blue-900 text-white">
                    <CardTitle class="flex items-center gap-2">
                        <ScrollText class="h-5 w-5" />
                        System Activity
                    </CardTitle>
                    <p class="text-sm text-blue-100">
                        {{ total }} recorded event{{ total === 1 ? '' : 's' }}
                    </p>
                </CardHeader>

                <CardContent class="bg-white">
                    <TableSkeleton v-if="loading" :columns="6" />
                    <div
                        v-else-if="error"
                        class="rounded-md border border-red-200 bg-red-50 p-4 text-center text-red-700"
                    >
                        {{ forbidden
                            ? 'Access forbidden. You do not have permission to view audit logs.'
                            : error }}
                    </div>
                    <div
                        v-else-if="auditLogs.length === 0"
                        class="py-10 text-center text-gray-500"
                    >
                        No audit logs match the selected filters.
                    </div>

                    <div v-else class="overflow-x-auto">
                        <Table class="text-[11.5pt] [&_td]:py-[7px] [&_th]:text-[12.5pt]">
                            <TableHeader class="bg-blue-900 text-white">
                                <TableRow>
                                    <TableHead class="text-white font-semibold">Time</TableHead>
                                    <TableHead class="text-white font-semibold">Actor</TableHead>
                                    <TableHead class="text-white font-semibold">Module / Action</TableHead>
                                    <TableHead class="text-white font-semibold">Record ID</TableHead>
                                    <TableHead class="text-white font-semibold">Description</TableHead>
                                    <TableHead class="text-white font-semibold">IP</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="auditLog in auditLogs" :key="auditLog.id">
                                    <TableCell class="whitespace-nowrap">
                                        {{ formatDate(auditLog.created_at) }}
                                    </TableCell>
                                    <TableCell>
                                        {{ auditLog.actor?.name || 'System / deleted user' }}
                                    </TableCell>
                                    <TableCell>
                                        <div class="font-semibold text-gray-900">
                                            {{ displayValue(auditLog.module) }}
                                        </div>
                                        <div class="text-sm text-gray-500">
                                            {{ displayValue(auditLog.action) }}
                                        </div>
                                    </TableCell>
                                    <TableCell>{{ auditLog.record_id ?? 'N/A' }}</TableCell>
                                    <TableCell class="max-w-xl whitespace-normal">
                                        {{ auditLog.description || 'N/A' }}
                                    </TableCell>
                                    <TableCell class="whitespace-nowrap">
                                        {{ auditLog.ip_address || 'N/A' }}
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>

                    <div v-if="!loading && !error && lastPage > 0" class="mt-4 flex flex-col items-center gap-3 border-t pt-4">
                        <p class="text-center text-sm text-gray-600">{{ total }} total results</p>
                        <nav class="max-w-full overflow-x-auto rounded-full bg-white p-1 shadow-[0_8px_18px_rgb(15_41_70/0.12)]" aria-label="Audit log pagination">
                            <div class="flex min-w-max items-center gap-1">
                                <button type="button" class="inline-flex h-10 items-center gap-1 rounded-full px-3 font-semibold text-blue-900 transition-colors hover:bg-blue-50 disabled:cursor-not-allowed disabled:opacity-40" :disabled="loading || !paginationState.canGoPrevious" @click="changePage(paginationState.previousPage)">
                                    <ChevronLeft class="h-4 w-4" />
                                    Prev
                                </button>
                                <template v-for="item in paginationItems" :key="item.type === 'page' ? item.page : item.key">
                                    <span v-if="item.type === 'ellipsis'" class="flex size-10 items-center justify-center font-bold text-blue-900" aria-hidden="true">…</span>
                                    <button v-else type="button" class="size-10 rounded-full font-semibold transition-colors" :class="item.page === page ? 'bg-blue-900 text-white shadow-[inset_0_1px_2px_rgb(15_41_70/0.18)]' : 'text-blue-900 hover:bg-blue-50'" :aria-current="item.page === page ? 'page' : undefined" :disabled="loading" @click="changePage(item.page)">{{ item.page }}</button>
                                </template>
                                <button type="button" class="inline-flex h-10 items-center gap-1 rounded-full px-3 font-semibold text-blue-900 transition-colors hover:bg-blue-50 disabled:cursor-not-allowed disabled:opacity-40" :disabled="loading || !paginationState.canGoNext" @click="changePage(paginationState.nextPage)">
                                    Next
                                    <ChevronRight class="h-4 w-4" />
                                </button>
                            </div>
                        </nav>
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
