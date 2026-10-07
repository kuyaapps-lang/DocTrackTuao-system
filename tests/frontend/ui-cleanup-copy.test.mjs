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
    assert.match(source, /Permanent QR assigned to this physical document/)
    assert.doesNotMatch(source, /Registered QR &middot; Linked to this document|Print Linked QR/)
    assert.match(source, /&times;/)
})

test('dashboard documents and public tracking show clearer helper copy', async () => {
    const dashboard = await readSource('resources/js/pages/Dashboard.vue')
    const documents = await readSource('resources/js/pages/Documents.vue')
    const tracking = await readSource('resources/js/pages/DocumentTracking.vue')

    assert.match(dashboard, /Documents by status/)
    assert.match(dashboard, /No routing activity yet/)
    assert.match(documents, /Try a different keyword or clear the filter/)
    assert.match(documents, /placeholder="Tracking number, QR code, title, type, or office"/)
    assert.match(tracking, /Document Tracking/)
    assert.match(tracking, /Limited public details are shown for this protected document/)
})

test('focused document lists keep their table and date polish', async () => {
    const documents = await readSource('resources/js/pages/Documents.vue')
    const details = await readSource('resources/js/pages/DocumentDetails.vue')

    assert.doesNotMatch(documents, /aria-label="Document views"/)
    assert.match(documents, /<Table class="min-w-\[46rem\] table-auto/)
    assert.match(documents, /<TableHeader class="bg-blue-900 text-white">/)
    assert.match(documents, /<TableHead class="text-white font-semibold">\s+QR Code/)
    assert.doesNotMatch(documents, /id="documents-per-page"/)
    assert.match(documents, /<CardContent\s+id="document-list-panel"\s+role="tabpanel"\s+:aria-busy="loading"\s+class="px-\[10px\] pb-\[10px\] pt-\[7px\] \[&_\*\]:!text-\[13pt\]"/)
    assert.match(documents, /formatDocumentDateTime/)
    assert.match(documents, /<TableCell\s+class="min-w-0 break-all whitespace-normal font-medium"\s*>/)
    assert.match(documents, /<TableCell class="min-w-0 break-words whitespace-normal">/)
    assert.match(documents, /max-w-xs whitespace-normal break-words/)

    assert.match(details, /formatDocumentDateField/)
    assert.match(details, /formatDocumentDateTime/)
    assert.match(details, /formatHistoryDateTime\(row\.date\)\.date/)
    assert.match(details, /formatHistoryDateTime\(row\.date\)\.time/)
    assert.doesNotMatch(details, /formatHistoryDateOnly|formatHistoryTimeOnly/)
})

test('dashboard and shell polish copy stays user friendly', async () => {
    const dashboard = await readSource('resources/js/pages/Dashboard.vue')
    const shell = await readSource('resources/js/layouts/AppShell.vue')
    const sidebar = await readSource('resources/js/components/AppSidebar.vue')
    const login = await readSource('resources/js/pages/Login.vue')
    const resolver = await readSource('resources/js/pages/QrResolver.vue')

    assert.match(dashboard, /Period: \{\{ dateRangeLabel \}\}/)
    assert.match(dashboard, /Latest Routing Activity/)
    assert.doesNotMatch(dashboard, /Recent Routing Activity/)
    assert.match(dashboard, /formatDashboardDateTime/)
    assert.doesNotMatch(dashboard, /System-wide reporting/)
    assert.doesNotMatch(dashboard, /Reporting month/)
    assert.match(sidebar, /aria-label="Open profile menu"/)
    assert.match(sidebar, /aria-label="Profile menu"/)
    assert.match(sidebar, /Profile/)
    assert.match(sidebar, /Settings/)
    assert.match(sidebar, /Document Management System/)
    assert.match(sidebar, /aria-controls="desktop-navigation"/)
    assert.match(sidebar, /@click="\$emit\('toggle-desktop'\)"/)
    assert.match(sidebar, /h-\[80px\] w-\[80px\]/)
    assert.match(sidebar, /h-\[40px\] w-\[40px\]/)
    assert.match(sidebar, /@click="\$emit\('logout'\)"/)
    assert.doesNotMatch(shell, /CircleUserRound/)
    assert.match(shell, /<ThemeToggle \/>/)
    assert.doesNotMatch(`${sidebar}\n${login}\n${resolver}`, /Document Tracking System/)
})

test('QR UI keeps compact blue header palette', async () => {
    const qrCodes = await readSource('resources/js/pages/QrCodes.vue')
    const resolver = await readSource('resources/js/pages/QrResolver.vue')
    const styles = await readSource('resources/css/app.css')

    assert.match(qrCodes, /<CardHeader class="bg-blue-900 px-4 py-2 text-white">/)
    assert.match(qrCodes, /<CardTitle class="text-base font-semibold">/)
    assert.match(qrCodes, /<CardContent class="\[&_\*\]:!text-\[13pt\]">/)
    assert.match(qrCodes, /Office QR Requests/)
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

    assert.match(dashboard, /sm:items-start sm:justify-between/)
    assert.match(dashboard, /id="dashboard-heading" class="text-\[28px\] font-bold/)
    assert.match(dashboard, /bg-white px-5 py-5 shadow-\[0_10px_30px_rgb/)
    assert.match(dashboard, /class="flex w-full flex-wrap items-end gap-2 rounded-xl bg-slate-50 p-3 dark:bg-slate-800\/90 sm:w-auto"/)
    assert.match(dashboard, /<DashboardDateRangePicker :from="dateFrom" :to="dateTo" aria-label="Dashboard date range"/)
    assert.match(dashboard, /rounded-xl border border-blue-100 bg-blue-50 px-5 py-3 text-\[11pt\] font-semibold text-blue-900 dark:border-blue-400\/30 dark:bg-blue-950\/40 dark:text-blue-100/)
    assert.match(dashboard, /<CardTitle class="text-\[13pt\] font-bold text-slate-800 dark:text-slate-100">Recent Documents<\/CardTitle>/)
    assert.match(dashboard, /const metrics = computed\(\(\) => dashboard\.value \? \[/)
    assert.match(dashboard, /icon: FileText, tone: 'from-blue-500 to-blue-700'/)
    assert.match(dashboard, /bg-gradient-to-br py-0 text-white/)
    assert.match(dashboard, /<component :is="metric\.icon"/)
    assert.match(dashboard, /\{\{ metric\.value \}\}/)
    assert.match(dashboard, /:style="\{ backgroundColor: pieColor\(index\) \}"/)
    assert.match(dashboard, /<span class="break-words">\{\{ item\[distribution\[2\]\]\.name \}\}<\/span>/)
    assert.match(dashboard, /<span class="text-\[12pt\] font-semibold leading-none text-slate-900 dark:text-white">\{\{ item\.count \}\}<\/span>/)
    assert.match(dashboard, /aria-label="Recent documents in the selected reporting period"/)
    assert.doesNotMatch(dashboard, /Latest 50/)
    assert.match(dashboard, /<table class="w-full min-w-\[52rem\] text-left text-\[11\.5pt\]">/)
    assert.match(dashboard, />QR Code<\/th>/)
    assert.match(dashboard, />Document Details<\/th>/)
    assert.match(dashboard, />Latest Routing Activity<\/th>/)
    assert.match(dashboard, /<th scope="col" class="px-3 py-2 text-center font-bold">Registered<\/th>/)
    assert.match(dashboard, /formatDashboardDate\(document\.created_at\)/)
    assert.match(dashboard, /formatDashboardTime\(document\.created_at\)/)
    assert.match(dashboard, /<td class="px-3 py-1\.5 font-semibold text-blue-800 dark:text-blue-300">/)
    assert.match(dashboard, /<RouterLink :to="`\/documents\/\$\{document\.id\}`"/)
    assert.match(dashboard, /\{\{ document\.qr_code \|\| 'No QR code' \}\}/)
    assert.match(dashboard, /\{\{ document\.document_details \}\}/)
    assert.match(dashboard, /document\.latest_routing_activity/)
    assert.match(dashboard, /documentStatusClass\(document\.status\.name\)/)
    assert.match(dashboard, /routingEventLabel\(document\.latest_routing_activity\.event_type\)/)
    assert.match(dashboard, /formatDashboardDateTime\(document\.latest_routing_activity\.occurred_at\)/)
    assert.doesNotMatch(dashboard, /:class="routingEventClass\(document\.latest_routing_activity\.event_type\)"/)
    assert.doesNotMatch(dashboard, /View document/)
    assert.doesNotMatch(dashboard, /aria-label="Recent routing activity in the selected reporting period"/)
    assert.doesNotMatch(dashboard, /<TableHead scope="col" class="text-center font-semibold">Tracking no\.<\/TableHead>/)

    assert.match(users, /<TableHeader class="bg-blue-900 text-white">/)
    assert.match(users, /Full Name/)
    assert.match(users, /Username \*/)
    assert.match(users, /<TableHead class="text-center text-white font-semibold">\s+Role\s+<\/TableHead>/)
    assert.match(users, /<TableHead class="text-center text-white font-semibold">\s+Office\s+<\/TableHead>/)
    assert.match(users, /<TableHead class="text-center text-white font-semibold">\s+Action\s+<\/TableHead>/)
    assert.match(users, /<div class="flex justify-center gap-2">/)

    assert.doesNotMatch(dashboardHelper, /office_id|requesting_office|processing_office/)
})

test('process 24a keeps the soft dashboard foundation scoped to the Vue shell', async () => {
    const styles = await readSource('resources/css/app.css')
    const shell = await readSource('resources/js/layouts/AppShell.vue')
    const sidebar = await readSource('resources/js/components/AppSidebar.vue')
    const dashboard = await readSource('resources/js/pages/Dashboard.vue')

    assert.match(styles, /--font-sans: 'Century Gothic', 'Segoe UI', Arial, sans-serif;/)
    assert.match(styles, /--background: oklch\(0\.965 0\.018 257\);/)
    assert.match(styles, /\.doctrack-shell::before/)
    assert.match(styles, /\.dark \{/)
    assert.match(styles, /\.dark \.doctrack-shell::before/)
    assert.match(styles, /linear-gradient\(rgb\(15 41 70 \/ 0\.055\) 1px, transparent 1px\)/)
    assert.match(styles, /\[data-slot="card"\]/)
    assert.match(styles, /\[data-slot="table"\]/)
    assert.match(shell, /bg-slate-100 bg-\[#eaf1ff\] text-slate-800/)
    assert.match(shell, /bg-\[#eaf1ff\]/)
    assert.match(shell, /doctrack-shell/)
    assert.match(sidebar, /rounded-2xl border border-white\/80 bg-white\/95 p-3 text-sm/)
    assert.match(shell, /ThemeToggle/)
    assert.match(sidebar, /bg-slate-100 bg-white\/70 text-slate-800/)
    assert.match(sidebar, /dark:bg-slate-900\/95/)
    assert.match(sidebar, /dark:bg-slate-800\/80/)
    assert.match(sidebar, /rounded-xl px-3 py-2 text-\[10\.5pt\]/)
    assert.match(dashboard, /min-h-screen bg-\[#f4f7fb\] p-4 text-slate-900 dark:bg-\[#111827\] dark:text-slate-100 sm:p-6/)
    assert.match(dashboard, /rounded-2xl border border-slate-200 bg-white px-5 py-5 .*dark:bg-slate-900\/95/)
    assert.match(dashboard, /rounded-xl border border-blue-100 bg-blue-50 .*dark:bg-blue-950\/40/)
})

test('shared foundation initializes theme preference and keeps button styling', async () => {
    const app = await readSource('resources/js/app.js')
    const shell = await readSource('resources/js/layouts/AppShell.vue')
    const button = await readSource('resources/js/components/ui/button/index.js')
    const login = await readSource('resources/js/pages/Login.vue')

    assert.match(app, /initializeTheme/)
    assert.match(app, /lib\/theme/)
    assert.match(shell, /ThemeToggle/)
    assert.match(button, /bg-blue-700 text-white hover:bg-blue-800/)
    assert.match(login, /from-cyan-600 to-blue-700/)
    assert.match(button, /h-11 px-5/)
    assert.doesNotMatch(button, /hover:-translate-y-px|shadow-\[/)
})

test('process 24a retains the dashboard data view without a registration trend', async () => {
    const dashboard = await readSource('resources/js/pages/Dashboard.vue')

    assert.doesNotMatch(dashboard, /registrationTrend|Document Registration Trend|formatTrendDate/)
})

test('shared interface styling covers administrator-only routes and form controls', async () => {
    const styles = await readSource('resources/css/app.css')
    const details = await readSource('resources/js/pages/DocumentDetails.vue')
    const changePassword = await readSource('resources/js/pages/ChangePassword.vue')
    const users = await readSource('resources/js/pages/Users.vue')

    assert.match(styles, /button,\s+input,\s+select,\s+textarea/)
    assert.match(styles, /font-family: 'Century Gothic', 'Segoe UI', Arial, sans-serif;/)
    assert.match(styles, /#app,\s+#app button,\s+#app input,\s+#app select,\s+#app textarea/)
    assert.match(styles, /font-family: 'Century Gothic', 'Segoe UI', Arial, sans-serif;/)
    assert.match(details, /min-h-screen bg-slate-100/)
    assert.match(changePassword, /min-h-screen bg-slate-100 p-6/)
    assert.match(users, /<TableHeader class="bg-blue-900 text-white">/)
})
