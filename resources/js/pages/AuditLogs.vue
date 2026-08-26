<script setup>
import { onMounted, ref } from 'vue'

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
    <div class="min-h-screen bg-gray-100">
        <div class="border-b bg-white px-6 py-4">
            <h1 class="text-2xl font-bold text-gray-800">Audit Logs</h1>
            <p class="mt-1 text-sm text-gray-500">
                Review recorded system activity in newest-first order.
            </p>
        </div>

        <div class="space-y-4 p-6">
            <Card>
                <CardHeader><CardTitle>Filters</CardTitle></CardHeader>
                <CardContent>
                    <form
                        class="grid gap-4 md:grid-cols-[1fr_1fr_auto] md:items-end"
                        @submit.prevent="applyFilters"
                    >
                        <label class="block text-sm font-semibold text-gray-700">
                            Module
                            <select
                                v-model="filters.module"
                                class="mt-2 h-10 w-full rounded-md border border-gray-300 bg-white px-3 text-sm"
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
                        </label>

                        <label class="block text-sm font-semibold text-gray-700">
                            Action
                            <select
                                v-model="filters.action"
                                class="mt-2 h-10 w-full rounded-md border border-gray-300 bg-white px-3 text-sm"
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
                        </label>

                        <div class="flex gap-2">
                            <Button type="submit" :disabled="loading">Apply</Button>
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="loading"
                                @click="clearFilters"
                            >
                                Clear
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>System Activity</CardTitle>
                    <p class="text-sm text-gray-500">
                        {{ total }} recorded event{{ total === 1 ? '' : 's' }}
                    </p>
                </CardHeader>

                <CardContent>
                    <div v-if="loading" class="py-10 text-center text-gray-500">
                        Loading audit logs...
                    </div>
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
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Time</TableHead>
                                    <TableHead>Actor</TableHead>
                                    <TableHead>Module / Action</TableHead>
                                    <TableHead>Record ID</TableHead>
                                    <TableHead>Description</TableHead>
                                    <TableHead>IP</TableHead>
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

                    <div
                        v-if="!loading && !error && lastPage > 0"
                        class="mt-4 flex items-center justify-between border-t pt-4"
                    >
                        <Button
                            variant="outline"
                            :disabled="page <= 1"
                            @click="changePage(page - 1)"
                        >
                            Previous
                        </Button>
                        <span class="text-sm text-gray-600">
                            Page {{ page }} of {{ lastPage }}
                        </span>
                        <Button
                            variant="outline"
                            :disabled="page >= lastPage"
                            @click="changePage(page + 1)"
                        >
                            Next
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
