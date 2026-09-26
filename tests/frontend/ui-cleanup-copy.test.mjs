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
    assert.match(documents, /<Table class="table-fixed">/)
    assert.match(documents, /<TableHeader class="bg-blue-900 text-white">/)
    assert.match(documents, /<TableHead class="text-white font-semibold">\s+Tracking No\./)
    assert.match(documents, /id="documents-per-page"\s+v-model\.number="perPage"\s+class="h-10 w-20 rounded-md/)
    assert.match(documents, /<CardContent\s+id="document-list-panel"\s+role="tabpanel"\s+:aria-busy="loading"\s+class="\[&_\*\]:!text-\[13pt\]"/)
    assert.match(documents, /formatDocumentDateTime/)
    assert.match(documents, /<TableCell\s+class="min-w-0 break-all whitespace-normal font-medium"\s*>/)
    assert.match(documents, /<TableCell class="min-w-0 break-words whitespace-normal">/)
    assert.match(documents, /max-w-xs whitespace-normal break-words/)

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
    assert.match(shell, /class="rounded-full"/)
    assert.match(sidebar, /Document Management System/)
    assert.match(sidebar, /<Menu aria-hidden="true" \/>/)
    assert.match(shell, /<CircleUserRound aria-hidden="true" class="h-6 w-6" \/>/)
    assert.match(shell, /aria-label="Account menu"/)
    assert.doesNotMatch(shell, /Theme|setThemePreference|useTheme|menuitemradio/)
    assert.match(shell, /{{ officeLabel }}/)
    assert.match(shell, /@click="logout"/)
    assert.doesNotMatch(`${sidebar}\n${login}\n${resolver}`, /Document Tracking System/)
})

test('QR UI keeps compact blue header palette', async () => {
    const qrCodes = await readSource('resources/js/pages/QrCodes.vue')
    const resolver = await readSource('resources/js/pages/QrResolver.vue')
    const styles = await readSource('resources/css/app.css')

    assert.match(qrCodes, /<CardHeader class="bg-blue-900 px-4 py-2 text-white">/)
    assert.match(qrCodes, /<CardTitle class="text-base font-semibold">/)
    assert.match(qrCodes, /<CardContent class="\[&_\*\]:!text-\[13pt\]">/)
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

    assert.match(dashboard, /sm:items-start sm:justify-between/)
    assert.match(dashboard, /id="dashboard-heading" class="text-\[25px\] font-bold/)
    assert.match(dashboard, /bg-white px-5 pb-5 pt-3/)
    assert.match(dashboard, /class="flex w-full flex-col gap-2 sm:w-auto sm:min-w-\[18rem\]"/)
    assert.match(dashboard, /class="mt-1 block h-11 w-full min-w-0 rounded-xl/)
    assert.match(dashboard, /class="grid grid-cols-2 gap-2"/)
    assert.match(dashboard, /text-\[13pt\] font-bold text-blue-900/)
    assert.match(dashboard, /<CardTitle class="text-\[11pt\] font-semibold">Recent Documents<\/CardTitle>/)
    assert.match(dashboard, /class="flex items-center justify-between gap-3"/)
    assert.match(dashboard, /<span class="block truncate text-\[10\.4pt\] font-semibold text-gray-900">\{\{ document\.tracking_no \}\}<\/span>/)
    assert.match(dashboard, /class="mt-2 flex justify-start"/)
    assert.match(dashboard, /class="flex flex-wrap items-center gap-3"/)
    assert.match(dashboard, /gap-y-1 text-\[10\.4pt\]/)
    assert.match(dashboard, /<CardHeader class="bg-blue-900 px-3 py-1\.5 text-left text-white"><CardTitle class="text-\[11pt\]/)
    assert.match(dashboard, /<CardContent class="px-3 pb-3 pt-1 text-center">/)
    assert.match(dashboard, /<p class="text-\[11\.5pt\] font-bold tabular-nums text-slate-900">\{\{ metric\[1\] \}\}<\/p>/)
    assert.match(dashboard, /<div class="mb-1 truncate text-\[12pt\]">\{\{ item\[distribution\[2\]\]\.name \}\}<\/div>/)
    assert.match(dashboard, /<span class="text-\[12pt\] font-semibold leading-none">\{\{ item\.count \}\}<\/span>/)
    assert.match(dashboard, /aria-label="Recent documents in the selected reporting period"/)
    assert.match(dashboard, /<CardContent class="px-4 pb-4 pt-0">/)
    assert.match(dashboard, /documentStatusClass\(document\.status\.name\)/)
    assert.match(dashboard, /aria-label="Recent routing activity in the selected reporting period"/)
    assert.match(dashboard, /max-h-72 space-y-2 overflow-auto pr-1" aria-label="Recent routing activity/)
    assert.match(dashboard, /routingEventLabel\(activity\.event_type\)/)
    assert.doesNotMatch(dashboard, /<TableHead scope="col" class="text-center font-semibold">Tracking no\.<\/TableHead>/)

    assert.match(users, /<TableHeader class="bg-blue-900 text-white">/)
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
    assert.doesNotMatch(styles, /\.dark \{|\.dark \.doctrack-shell|dark:/)
    assert.match(styles, /linear-gradient\(rgb\(15 41 70 \/ 0\.055\) 1px, transparent 1px\)/)
    assert.match(styles, /\[data-slot="card"\]/)
    assert.match(styles, /\[data-slot="table"\]/)
    assert.match(shell, /bg-slate-100 bg-\[#eaf1ff\] text-slate-800/)
    assert.match(shell, /bg-\[#eaf1ff\]/)
    assert.match(shell, /doctrack-shell/)
    assert.match(shell, /rounded-2xl border border-white\/80 bg-white\/90 p-4 text-sm/)
    assert.doesNotMatch(shell, /dark:|useTheme|setThemePreference/)
    assert.match(sidebar, /bg-slate-100 bg-white\/70 text-slate-800/)
    assert.doesNotMatch(sidebar, /dark:/)
    assert.match(sidebar, /rounded-xl px-3 py-2 text-\[12pt\]/)
    assert.match(dashboard, /rounded-2xl border border-slate-200 bg-white px-5 pb-5 pt-3/)
    assert.match(dashboard, /rounded-2xl border border-blue-100 bg-blue-50/)
})

test('manual light foundation removes theme selector and keeps shared button styling', async () => {
    const app = await readSource('resources/js/app.js')
    const shell = await readSource('resources/js/layouts/AppShell.vue')
    const button = await readSource('resources/js/components/ui/button/index.js')
    const login = await readSource('resources/js/pages/Login.vue')

    assert.doesNotMatch(app, /initializeTheme|lib\/theme/)
    assert.doesNotMatch(shell, /useTheme|Theme|Light|Dark|System|menuitemradio/)
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
