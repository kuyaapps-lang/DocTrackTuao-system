<script setup>
import { computed, onMounted, ref } from 'vue'
import { Printer, RotateCcw } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import DashboardDateRangePicker from '@/components/DashboardDateRangePicker.vue'
import { getToken } from '@/lib/auth'

const manilaWeek = () => {
    const parts = new Intl.DateTimeFormat('en-US', { timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit', weekday: 'short' }).formatToParts(new Date())
    const value = type => parts.find(part => part.type === type)?.value
    const today = new Date(Date.UTC(Number(value('year')), Number(value('month')) - 1, Number(value('day'))))
    today.setUTCDate(today.getUTCDate() - ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].indexOf(value('weekday')))
    const format = date => date.toISOString().slice(0, 10)
    const end = new Date(today)
    end.setUTCDate(end.getUTCDate() + 6)
    return { from: format(today), to: format(end) }
}

const defaultRange = manilaWeek()
const dateFrom = ref(defaultRange.from)
const dateTo = ref(defaultRange.to)
const report = ref(null)
const loading = ref(false)
const error = ref('')
const selectedOffice = ref('all')
const scopeLabel = computed(() => report.value?.scope.type === 'system' ? 'All offices' : report.value?.scope.office?.name || '')
const rangeLabel = computed(() => `${report.value?.filters.date_from || dateFrom.value} to ${report.value?.filters.date_to || dateTo.value}`)
const visibleOffices = computed(() => (report.value?.offices || []).filter(office => selectedOffice.value === 'all' || Number(office.id) === Number(selectedOffice.value)))
const activityFilter = ref('all')
const metricVisible = key => activityFilter.value === 'all' || activityFilter.value === key

const load = async () => {
    loading.value = true
    error.value = ''
    try {
        const query = new URLSearchParams({ date_from: dateFrom.value, date_to: dateTo.value })
        const response = await fetch(`/api/reports/accomplishment?${query}`, { headers: { Accept: 'application/json', Authorization: `Bearer ${getToken()}` } })
        const data = await response.json()
        if (!response.ok) throw new Error(data.message || 'Unable to load the accomplishment report.')
        report.value = data
    } catch (exception) {
        report.value = null
        error.value = exception.message || 'Unable to load the accomplishment report.'
    } finally {
        loading.value = false
    }
}

const resetWeek = () => {
    const range = manilaWeek()
    dateFrom.value = range.from
    dateTo.value = range.to
    load()
}

const printReport = () => {
    if (!report.value) return

    const escape = value => String(value ?? '').replace(/[&<>'"]/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' })[character])
    const rows = report.value.scope.type === 'system'
        ? visibleOffices.value.map(office => `<tr><td>${escape(office.office_name)}</td>${metricVisible('received') ? `<td>${office.received_documents}</td>` : ''}${metricVisible('outgoing') ? `<td>${office.outgoing_documents}</td>` : ''}${metricVisible('changed') ? `<td>${office.status_changes}</td>` : ''}</tr>`).join('')
        : ''
    const metrics = [
        metricVisible('received') ? ['Received', selectedOffice.value === 'all' ? report.value.summary.received_documents : visibleOffices.value[0]?.received_documents || 0] : null,
        metricVisible('outgoing') ? ['Released / Out', selectedOffice.value === 'all' ? report.value.summary.outgoing_documents : visibleOffices.value[0]?.outgoing_documents || 0] : null,
        metricVisible('changed') ? ['Status Changed', selectedOffice.value === 'all' ? report.value.summary.status_changes : visibleOffices.value[0]?.status_changes || 0] : null,
    ].filter(Boolean).map(([label, value]) => `<div class="metric"><strong>${escape(label)}</strong><span>${value}</span></div>`).join('')
    const headings = `${metricVisible('received') ? '<th>Received</th>' : ''}${metricVisible('outgoing') ? '<th>Released / Out</th>' : ''}${metricVisible('changed') ? '<th>Status Changed</th>' : ''}`
    const popup = window.open('', '_blank', 'noopener,noreferrer,width=960,height=720')
    if (!popup) return
    popup.document.write(`<!doctype html><html><head><title>Accomplishment Report</title><style>body{font-family:Arial,sans-serif;color:#172033;margin:36px}h1,h2,p{margin:0}header{text-align:center;border-bottom:2px solid #193d8f;padding-bottom:16px;margin-bottom:24px}.meta{margin:4px 0;color:#475569}.metrics{display:flex;gap:12px;margin:24px 0}.metric{border:1px solid #cbd5e1;border-radius:8px;padding:14px;min-width:150px}.metric strong,.metric span{display:block}.metric span{font-size:28px;margin-top:8px}table{width:100%;border-collapse:collapse;margin-top:24px}th{background:#193d8f;color:white;text-align:left}th,td{padding:10px;border:1px solid #cbd5e1}footer{margin-top:32px;font-size:12px;color:#64748b}@media print{body{margin:18px}}</style></head><body><header><h1>Municipality of Tuao</h1><h2>Document Management System</h2><p>Accomplishment Report</p></header><p class="meta"><strong>Reporting period:</strong> ${escape(rangeLabel.value)}</p><p class="meta"><strong>Scope:</strong> ${escape(scopeLabel.value)}</p><div class="metrics">${metrics}</div>${rows ? `<table><thead><tr><th>Office</th>${headings}</tr></thead><tbody>${rows}</tbody></table>` : ''}<footer>Generated on ${escape(new Date().toLocaleString('en-PH', { timeZone: 'Asia/Manila' }))}</footer><script>window.onload=()=>window.print()<\/script></body></html>`)
    popup.document.close()
}

onMounted(load)
</script>

<template>
    <main class="min-h-screen bg-slate-100 p-4 sm:p-6">
        <Card class="mx-auto max-w-5xl print:shadow-none">
            <CardHeader class="print:pb-2">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <CardTitle class="text-xl">Accomplishment Report</CardTitle>
                        <p class="mt-1 text-sm text-slate-600">Weekly document activity for {{ scopeLabel || 'your office' }}.</p>
                    </div>
                    <Button type="button" class="print:hidden" @click="printReport"><Printer class="mr-2 size-4" />Print</Button>
                </div>
                <div class="mt-4 flex flex-wrap items-end gap-3 print:hidden">
                    <DashboardDateRangePicker v-model:from="dateFrom" v-model:to="dateTo" aria-label="Accomplishment report date range" />
                    <select v-if="report?.scope.type === 'system'" v-model="selectedOffice" class="h-10 rounded-md border border-slate-300 bg-white px-3 text-sm" aria-label="Filter by office"><option value="all">All offices</option><option v-for="office in report.offices" :key="office.id" :value="office.id">{{ office.office_name }}</option></select>
                    <select v-if="report?.scope.type === 'system'" v-model="activityFilter" class="h-10 rounded-md border border-slate-300 bg-white px-3 text-sm" aria-label="Filter report activity"><option value="all">All activities</option><option value="received">Received</option><option value="outgoing">Released / Out</option><option value="changed">Status Changed</option></select>
                    <Button type="button" :disabled="loading || !dateFrom || !dateTo" @click="load">Apply</Button>
                    <Button type="button" variant="outline" :disabled="loading" @click="resetWeek"><RotateCcw class="mr-2 size-4" />This week</Button>
                </div>
            </CardHeader>
            <CardContent>
                <p class="mb-6 text-sm text-slate-600">Reporting period: <strong>{{ rangeLabel }}</strong></p>
                <p v-if="loading" class="py-10 text-center text-slate-500">Loading report...</p>
                <p v-else-if="error" class="rounded-md border border-red-200 bg-red-50 p-3 text-red-700">{{ error }}</p>
                <div v-else-if="report" class="grid gap-4 sm:grid-cols-3">
                    <div v-if="metricVisible('received')" class="rounded-xl border border-emerald-200 bg-emerald-50 p-6"><p class="text-sm font-semibold text-emerald-800">Received</p><p class="mt-2 text-4xl font-bold text-emerald-950">{{ selectedOffice === 'all' ? report.summary.received_documents : visibleOffices[0]?.received_documents || 0 }}</p></div>
                    <div v-if="metricVisible('outgoing')" class="rounded-xl border border-blue-200 bg-blue-50 p-6"><p class="text-sm font-semibold text-blue-800">Released / Out</p><p class="mt-2 text-4xl font-bold text-blue-950">{{ selectedOffice === 'all' ? report.summary.outgoing_documents : visibleOffices[0]?.outgoing_documents || 0 }}</p></div>
                    <div v-if="metricVisible('changed')" class="rounded-xl border border-violet-200 bg-violet-50 p-6"><p class="text-sm font-semibold text-violet-800">Status Changed</p><p class="mt-2 text-4xl font-bold text-violet-950">{{ selectedOffice === 'all' ? report.summary.status_changes : visibleOffices[0]?.status_changes || 0 }}</p></div>
                </div>
                <div v-if="report?.scope.type === 'system'" class="mt-8 overflow-x-auto">
                    <table class="w-full min-w-[620px] border-collapse text-left text-sm">
                        <thead class="bg-blue-900 text-white"><tr><th class="p-3">Office</th><th v-if="metricVisible('received')" class="p-3">Received</th><th v-if="metricVisible('outgoing')" class="p-3">Released / Out</th><th v-if="metricVisible('changed')" class="p-3">Status Changed</th></tr></thead>
                        <tbody><tr v-for="office in visibleOffices" :key="office.id" class="border-b"><td class="p-3 font-medium">{{ office.office_name }}</td><td v-if="metricVisible('received')" class="p-3">{{ office.received_documents }}</td><td v-if="metricVisible('outgoing')" class="p-3">{{ office.outgoing_documents }}</td><td v-if="metricVisible('changed')" class="p-3">{{ office.status_changes }}</td></tr></tbody>
                    </table>
                </div>
            </CardContent>
        </Card>
    </main>
</template>
