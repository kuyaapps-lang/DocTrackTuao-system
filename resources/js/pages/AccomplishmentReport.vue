<script setup>
import { computed, onMounted, ref } from 'vue'
import { Printer, RotateCcw } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import DashboardDateRangePicker from '@/components/DashboardDateRangePicker.vue'
import lguLogo from '@/assets/tuao-logo.png'
import { getToken, useAuth } from '@/lib/auth'

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
const { currentUser, ensureCurrentUser } = useAuth()
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
        // Keep the last successful report on screen while a refresh fails.
        // This avoids replacing a usable table with a loading/error skeleton.
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
    const dateFormatter = new Intl.DateTimeFormat('en-PH', {
        timeZone: 'Asia/Manila',
        month: 'long',
        day: '2-digit',
        year: 'numeric',
    })
    const dateForDisplay = value => dateFormatter.format(new Date(`${value}T00:00:00`))
    const from = report.value.filters.date_from || dateFrom.value
    const to = report.value.filters.date_to || dateTo.value
    const period = from === to
        ? `For the Date of ${dateForDisplay(from)}`
        : `For the Period ${dateForDisplay(from)} to ${dateForDisplay(to)}`
    const offices = report.value.scope.type === 'system'
        ? visibleOffices.value
        : [{
            office_name: report.value.scope.office?.name || 'Assigned Office',
            received_documents: report.value.summary.received_documents,
            outgoing_documents: report.value.summary.outgoing_documents,
            status_changes: report.value.summary.status_changes,
            completed_documents: report.value.summary.completed_documents,
            archived_documents: report.value.summary.archived_documents,
        }]
    const includeOfficeColumn = report.value.scope.type === 'system'
    const officeLabel = report.value.scope.type === 'system' && selectedOffice.value !== 'all'
        ? offices[0]?.office_name || 'Selected Office'
        : scopeLabel.value || 'All Offices'
    const totals = offices.reduce((summary, office) => ({
        received: summary.received + Number(office.received_documents || 0),
        released: summary.released + Number(office.outgoing_documents || 0),
        changed: summary.changed + Number(office.status_changes || 0),
        completed: summary.completed + Number(office.completed_documents || 0),
        archived: summary.archived + Number(office.archived_documents || 0),
    }), { received: 0, released: 0, changed: 0, completed: 0, archived: 0 })
    const rows = offices.map(office => `<tr>${includeOfficeColumn ? `<td class="office-column">${escape(office.office_name)}</td>` : ''}<td>${office.received_documents || 0}</td><td>${office.outgoing_documents || 0}</td><td>${office.status_changes || 0}</td><td>${office.completed_documents || 0}</td><td>${office.archived_documents || 0}</td></tr>`).join('')
    const preparedBy = currentUser.value?.name || '____________________________'
    const preparedByDesignation = currentUser.value?.designation || ''
    const certifiedBy = report.value.scope.type === 'office'
        ? report.value.scope.office?.signatory_name
        : selectedOffice.value !== 'all'
            ? visibleOffices.value[0]?.signatory_name
            : null
    const certifiedByDesignation = report.value.scope.type === 'office'
        ? report.value.scope.office?.signatory_designation
        : selectedOffice.value !== 'all'
            ? visibleOffices.value[0]?.signatory_designation
            : null
    // `noopener` makes window.open return null in Chromium, preventing us from
    // writing the report into the new print window.
    const popup = window.open('', '_blank', 'width=960,height=720')
    if (!popup) return
    popup.document.write(`<!doctype html><html><head><title>Accomplishment Report</title><style>@page{size:landscape;margin:0}*{box-sizing:border-box}body{font-family:Arial,Helvetica,sans-serif;color:#111827;margin:0;padding:.5in .5in .5in 1in;font-size:11pt}.letterhead{border-bottom:2px solid #111827;padding-bottom:13px;text-align:center}.logo{display:block;width:70px;height:70px;margin:0 auto 8px;object-fit:contain}.government{text-align:center}.government p{margin:0;line-height:1.35}.republic{font-size:10pt}.province{font-size:11pt;font-weight:700}.lgu{font-size:14pt;font-weight:700;text-transform:uppercase;margin-top:3px!important}h1{text-align:center;font-size:19pt;letter-spacing:.5px;margin:23px 0 5px}.period{text-align:center;font-size:11pt;margin:0 0 23px}.office{font-size:11pt;margin:0 0 12px}.office strong{display:inline-block;min-width:55px}table{border-collapse:collapse;width:100%;font-size:10.5pt}th,td{border:1px solid #111827;padding:9px 11px;text-align:center}th{background:#e5e7eb;font-weight:700}.office-column{text-align:left}tfoot td{font-weight:700;background:#f3f4f6}.signatures{display:grid;grid-template-columns:1fr 1fr;gap:84px;margin-top:63px}.signature{text-align:left}.signature .label{font-size:10pt;margin-bottom:30px}.signature .name{min-height:23px;border-bottom:1px solid #111827;padding:0 8px;text-align:center;font-weight:700}.signature .certified-name{border-bottom:0}.signature .designation{min-height:18px;margin-top:5px;text-align:center;font-size:10pt}.footer{margin-top:24px;text-align:right;color:#4b5563;font-size:8pt}@media print{body{print-color-adjust:exact;-webkit-print-color-adjust:exact}}</style></head><body><header class="letterhead"><img class="logo" src="${escape(lguLogo)}" alt="Local Government Unit of Tuao logo"><div class="government"><p class="republic">Republic of the Philippines</p><p class="province">Province of Cagayan</p><p class="lgu">Local Government Unit of Tuao</p></div></header><h1>ACCOMPLISHMENT REPORT</h1><p class="period">${escape(period)}</p><p class="office"><strong>Office:</strong> ${escape(officeLabel)}</p><table><thead><tr>${includeOfficeColumn ? '<th class="office-column">Office</th>' : ''}<th>No. of Received</th><th>No. of Released</th><th>Status Changed</th><th>Completed</th><th>Archived</th></tr></thead><tbody>${rows}</tbody><tfoot><tr>${includeOfficeColumn ? '<td class="office-column">TOTAL</td>' : ''}<td>${totals.received}</td><td>${totals.released}</td><td>${totals.changed}</td><td>${totals.completed}</td><td>${totals.archived}</td></tr></tfoot></table><section class="signatures"><div class="signature"><div class="label">Prepared by:</div><div class="name">${escape(preparedBy)}</div><div class="designation">${escape(preparedByDesignation)}</div></div><div class="signature"><div class="label">Certified Correct:</div><div class="name certified-name">${escape(certifiedBy || '')}</div><div class="designation">${escape(certifiedByDesignation || '')}</div></div></section><p class="footer">Generated on ${escape(new Date().toLocaleString('en-PH', { timeZone: 'Asia/Manila' }))}</p><script>window.onload=()=>window.print()<\/script></body></html>`)
    popup.document.head.insertAdjacentHTML('beforeend', '<style>.province{font-weight:700}</style>')
    popup.document.close()
    popup.opener = null
}

onMounted(() => {
    ensureCurrentUser().catch(() => null)
    load()
})
</script>

<template>
    <main class="min-h-screen bg-slate-100 p-4 text-slate-900 dark:bg-slate-950 dark:text-slate-100 sm:p-6">
        <Card class="mx-auto max-w-5xl print:shadow-none dark:border-slate-700 dark:bg-slate-900">
            <CardHeader class="print:pb-2">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <CardTitle class="text-xl">Accomplishment Report</CardTitle>
                        <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">Weekly document activity for {{ scopeLabel || 'your office' }}.</p>
                    </div>
                    <Button type="button" class="bg-blue-900 text-white hover:bg-blue-800 hover:text-white dark:bg-blue-900 dark:text-white dark:hover:bg-blue-800 dark:hover:text-white print:hidden" @click="printReport"><Printer class="mr-2 size-4" />Print</Button>
                </div>
                <div class="mt-4 flex flex-wrap items-end gap-3 print:hidden">
                    <DashboardDateRangePicker v-model:from="dateFrom" v-model:to="dateTo" aria-label="Accomplishment report date range" />
                    <select v-if="report?.scope.type === 'system'" v-model="selectedOffice" class="h-10 rounded-md border border-slate-300 bg-white px-3 text-sm dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100" aria-label="Filter by office"><option value="all">All offices</option><option v-for="office in report.offices" :key="office.id" :value="office.id">{{ office.office_name }}</option></select>
                    <select v-if="report?.scope.type === 'system'" v-model="activityFilter" class="h-10 rounded-md border border-slate-300 bg-white px-3 text-sm dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100" aria-label="Filter report activity"><option value="all">All activities</option><option value="received">Received</option><option value="outgoing">Released / Out</option><option value="changed">Status Changed</option><option value="completed">Completed</option><option value="archived">Archived</option></select>
                    <Button type="button" class="bg-blue-900 text-white hover:bg-blue-800 hover:text-white dark:bg-blue-900 dark:text-white dark:hover:bg-blue-800 dark:hover:text-white" :disabled="loading || !dateFrom || !dateTo" @click="load">Apply</Button>
                    <Button type="button" variant="outline" class="border-blue-900 bg-blue-900 text-white hover:bg-blue-800 hover:text-white dark:border-blue-900 dark:bg-blue-900 dark:text-white dark:hover:bg-blue-800 dark:hover:text-white" :disabled="loading" @click="resetWeek"><RotateCcw class="mr-2 size-4" />This week</Button>
                </div>
            </CardHeader>
            <CardContent class="p-6 sm:p-8">
                <p class="mb-6 text-sm text-slate-600 dark:text-slate-300">Reporting period: <strong>{{ rangeLabel }}</strong></p>
                <p v-if="loading && !report" class="py-10 text-center text-slate-500 dark:text-slate-400">Loading report...</p>
                <p v-if="error" class="mb-4 rounded-md border border-red-200 bg-red-50 p-3 text-red-700">{{ error }}</p>
                <template v-if="report">
                    <p v-if="loading" class="mb-3 text-sm text-slate-500 dark:text-slate-400" role="status">Refreshing report...</p>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div v-if="metricVisible('received')" class="rounded-xl border border-emerald-200 bg-emerald-50 p-6 dark:border-emerald-900 dark:bg-emerald-950/40"><p class="text-sm font-semibold text-emerald-800 dark:text-emerald-300">Received</p><p class="mt-2 text-4xl font-bold text-emerald-950 dark:text-emerald-100">{{ selectedOffice === 'all' ? report.summary.received_documents : visibleOffices[0]?.received_documents || 0 }}</p></div>
                        <div v-if="metricVisible('outgoing')" class="rounded-xl border border-blue-200 bg-blue-50 p-6 dark:border-blue-900 dark:bg-blue-950/40"><p class="text-sm font-semibold text-blue-800 dark:text-blue-300">Released / Out</p><p class="mt-2 text-4xl font-bold text-blue-950 dark:text-blue-100">{{ selectedOffice === 'all' ? report.summary.outgoing_documents : visibleOffices[0]?.outgoing_documents || 0 }}</p></div>
                        <div v-if="metricVisible('changed')" class="rounded-xl border border-violet-200 bg-violet-50 p-6 dark:border-violet-900 dark:bg-violet-950/40"><p class="text-sm font-semibold text-violet-800 dark:text-violet-300">Status Changed</p><p class="mt-2 text-4xl font-bold text-violet-950 dark:text-violet-100">{{ selectedOffice === 'all' ? report.summary.status_changes : visibleOffices[0]?.status_changes || 0 }}</p></div>
                        <div v-if="metricVisible('completed')" class="rounded-xl border border-teal-200 bg-teal-50 p-6 dark:border-teal-900 dark:bg-teal-950/40"><p class="text-sm font-semibold text-teal-800 dark:text-teal-300">Completed</p><p class="mt-2 text-4xl font-bold text-teal-950 dark:text-teal-100">{{ selectedOffice === 'all' ? report.summary.completed_documents : visibleOffices[0]?.completed_documents || 0 }}</p></div>
                        <div v-if="metricVisible('archived')" class="rounded-xl border border-slate-300 bg-slate-100 p-6 dark:border-slate-700 dark:bg-slate-800"><p class="text-sm font-semibold text-slate-800 dark:text-slate-300">Archived</p><p class="mt-2 text-4xl font-bold text-slate-950 dark:text-slate-100">{{ selectedOffice === 'all' ? report.summary.archived_documents : visibleOffices[0]?.archived_documents || 0 }}</p></div>
                    </div>
                    <div v-if="report.scope.type === 'system'" class="mt-8 overflow-x-auto">
                        <table class="w-full min-w-[620px] border-collapse text-left text-sm">
                            <thead class="bg-blue-900 text-white"><tr><th class="p-3">Office</th><th v-if="metricVisible('received')" class="p-3">Received</th><th v-if="metricVisible('outgoing')" class="p-3">Released / Out</th><th v-if="metricVisible('changed')" class="p-3">Status Changed</th><th v-if="metricVisible('completed')" class="p-3">Completed</th><th v-if="metricVisible('archived')" class="p-3">Archived</th></tr></thead>
                            <tbody class="dark:divide-y dark:divide-slate-700"><tr v-for="office in visibleOffices" :key="office.id" class="border-b dark:border-slate-700"><td class="p-3 font-medium">{{ office.office_name }}</td><td v-if="metricVisible('received')" class="p-3">{{ office.received_documents }}</td><td v-if="metricVisible('outgoing')" class="p-3">{{ office.outgoing_documents }}</td><td v-if="metricVisible('changed')" class="p-3">{{ office.status_changes }}</td><td v-if="metricVisible('completed')" class="p-3">{{ office.completed_documents }}</td><td v-if="metricVisible('archived')" class="p-3">{{ office.archived_documents }}</td></tr></tbody>
                        </table>
                    </div>
                </template>
            </CardContent>
        </Card>
    </main>
</template>
