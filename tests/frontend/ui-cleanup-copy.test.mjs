import assert from 'node:assert/strict'
import test from 'node:test'
import { readFile } from 'node:fs/promises'

const readSource = async path => readFile(
    new URL(`../../${path}`, import.meta.url),
    'utf8'
)

test('defense UI cleanup removes visible mojibake from touched screens', async () => {
    const files = [
        'resources/js/pages/Login.vue',
        'resources/js/layouts/AppShell.vue',
        'resources/js/pages/DocumentDetails.vue',
        'resources/js/pages/Documents.vue',
    ]

    for (const file of files) {
        const source = await readSource(file)

        assert.doesNotMatch(source, /Â|â|Ã/)
    }
})

test('user management keeps one confirm-password visibility toggle per form', async () => {
    const source = await readSource('resources/js/pages/Users.vue')
    const toggles = source.match(/@click="showPasswordConfirmation = !showPasswordConfirmation"/g) || []

    assert.equal(toggles.length, 2)
})

test('document details keeps terminal action confirms with clearer warning copy', async () => {
    const source = await readSource('resources/js/pages/DocumentDetails.vue')

    assert.match(source, /window\.confirm\(/)
    assert.match(source, /Complete this document now\?\\n\\nAfter completion/)
    assert.match(source, /Archive this completed document now\?\\n\\nArchived documents/)
    assert.match(source, /Registered QR &middot; Linked to this document/)
    assert.match(source, /&times;/)
})

test('dashboard documents and public tracking show clearer helper copy', async () => {
    const dashboard = await readSource('resources/js/pages/Dashboard.vue')
    const documents = await readSource('resources/js/pages/Documents.vue')
    const tracking = await readSource('resources/js/pages/DocumentTracking.vue')

    assert.match(dashboard, /Documents by status/)
    assert.match(dashboard, /No routing activity was recorded in this period/)
    assert.match(documents, /Try a different keyword or clear the filter/)
    assert.match(documents, /Search by tracking number, title, type, or office/)
    assert.match(tracking, /Document Tracking/)
    assert.match(tracking, /Limited public details are shown for this protected document/)
})

test('documents UI keeps process 23e color and date polish', async () => {
    const documents = await readSource('resources/js/pages/Documents.vue')
    const details = await readSource('resources/js/pages/DocumentDetails.vue')

    assert.match(documents, /rounded-lg border border-blue-100 bg-blue-50 px-3 pt-3/)
    assert.match(documents, /border-blue-700 bg-white text-blue-900 shadow-sm/)
    assert.match(documents, /<TableHeader class="bg-blue-900 text-white">/)
    assert.match(documents, /<TableHead class="text-white font-semibold">\s+Tracking No\./)
    assert.match(documents, /id="documents-per-page"\s+v-model\.number="perPage"\s+class="h-10 w-20 rounded-md/)
    assert.match(documents, /formatDocumentDateTime/)

    assert.match(details, /formatDocumentDateField/)
    assert.match(details, /formatDocumentDateTime/)
    assert.match(details, /{{ formatDate\(row\.date\) }}/)
    assert.doesNotMatch(details, /formatHistoryDateOnly|formatHistoryTimeOnly/)
})

test('dashboard and shell polish copy stays user friendly', async () => {
    const dashboard = await readSource('resources/js/pages/Dashboard.vue')
    const shell = await readSource('resources/js/layouts/AppShell.vue')
    const sidebar = await readSource('resources/js/components/AppSidebar.vue')
    const login = await readSource('resources/js/pages/Login.vue')
    const resolver = await readSource('resources/js/pages/QrResolver.vue')

    assert.match(dashboard, /Reporting period/)
    assert.match(dashboard, /Recent Routing Activity/)
    assert.match(dashboard, /formatDashboardDateTime/)
    assert.doesNotMatch(dashboard, /System-wide reporting/)
    assert.doesNotMatch(dashboard, /Period:/)
    assert.match(shell, /rounded-lg border border-blue-100 bg-blue-50/)
    assert.match(sidebar, /Document Management System/)
    assert.match(sidebar, /<Menu aria-hidden="true" \/>/)
    assert.doesNotMatch(`${sidebar}\n${login}\n${resolver}`, /Document Tracking System/)
})

test('QR UI keeps compact blue header palette', async () => {
    const qrCodes = await readSource('resources/js/pages/QrCodes.vue')
    const resolver = await readSource('resources/js/pages/QrResolver.vue')
    const styles = await readSource('resources/css/app.css')

    assert.match(qrCodes, /<CardHeader class="bg-blue-900 px-4 py-2 text-white">/)
    assert.match(qrCodes, /<CardTitle class="text-base font-semibold">/)
    assert.match(qrCodes, /QR Code Administration/)
    assert.match(qrCodes, /Review and manage office QR requests/)
    assert.match(qrCodes, /<thead class="bg-blue-900 text-white">/)
    assert.match(qrCodes, /class="text-xs text-blue-100"/)
    assert.match(styles, /--font-sans: 'Century Gothic', 'Segoe UI', Arial, sans-serif;/)
    assert.match(resolver, /class="bg-blue-900 px-4 py-2 text-center text-white"/)
    assert.match(resolver, /class="text-sm font-semibold"/)
})

test('process 23c dashboard and users polish keeps alignment scoped to frontend', async () => {
    const dashboard = await readSource('resources/js/pages/Dashboard.vue')
    const users = await readSource('resources/js/pages/Users.vue')
    const dashboardHelper = await readSource('resources/js/lib/dashboard.js')

    assert.match(dashboard, /id="dashboard-heading" class="text-\[28px\] font-bold/)
    assert.match(dashboard, /text-\[13pt\] font-bold text-blue-900/)
    assert.match(dashboard, /<CardTitle class="text-\[15pt\] font-semibold">Recent Documents<\/CardTitle>/)
    assert.match(dashboard, /class="text-\[13pt\] font-semibold text-gray-900"/)
    assert.match(dashboard, /<CardHeader class="bg-blue-900 px-3 py-1\.5 text-left text-white">/)
    assert.match(dashboard, /<CardContent class="px-3 py-3 text-center">/)
    assert.match(dashboard, /aria-label="Recent documents in the selected reporting period"/)
    assert.match(dashboard, /documentStatusClass\(document\.status\.name\)/)
    assert.match(dashboard, /aria-label="Recent routing activity in the selected reporting period"/)
    assert.match(dashboard, /routingEventLabel\(activity\.event_type\)/)
    assert.doesNotMatch(dashboard, /<TableHead scope="col" class="text-center font-semibold">Tracking no\.<\/TableHead>/)

    assert.match(users, /<TableHead class="text-center">\s+Role\s+<\/TableHead>/)
    assert.match(users, /<TableHead class="text-center">\s+Office\s+<\/TableHead>/)
    assert.match(users, /<TableHead class="text-center">\s+Action\s+<\/TableHead>/)
    assert.match(users, /<div class="flex justify-center gap-2">/)

    assert.doesNotMatch(dashboardHelper, /office_id|requesting_office|processing_office/)
})
