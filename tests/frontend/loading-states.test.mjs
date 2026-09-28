import assert from 'node:assert/strict'
import test from 'node:test'
import { readFile } from 'node:fs/promises'

const read = path => readFile(new URL(`../../${path}`, import.meta.url), 'utf8')

test('shared branded and skeleton loaders cover initial and page data loading', async () => {
    const [app, styles, dashboard, documents, audit, users, qrCodes, details] = await Promise.all([
        read('resources/js/App.vue'),
        read('resources/css/app.css'),
        read('resources/js/pages/Dashboard.vue'),
        read('resources/js/pages/Documents.vue'),
        read('resources/js/pages/AuditLogs.vue'),
        read('resources/js/pages/Users.vue'),
        read('resources/js/pages/QrCodes.vue'),
        read('resources/js/pages/DocumentDetails.vue'),
    ])

    assert.match(app, /AppLoading/)
    assert.match(app, /router\.isReady\(\)/)
    assert.match(styles, /doctrack-skeleton-shimmer/)
    assert.match(styles, /prefers-reduced-motion: reduce/)
    assert.match(dashboard, /DashboardSkeleton v-if="loading && !dashboard"/)
    assert.match(documents, /TableSkeleton v-if="loading" :columns="6"/)
    assert.match(audit, /TableSkeleton v-if="loading" :columns="6"/)
    assert.match(users, /TableSkeleton v-if="resetRequestsLoading" :columns="4"/)
    assert.match(users, /TableSkeleton v-if="loading" :columns="6"/)
    assert.match(qrCodes, /TableSkeleton v-if="requestsLoading"/)
    assert.match(qrCodes, /TableSkeleton v-if="inventoryLoading" :columns="5"/)
    assert.match(details, /DocumentDetailsSkeleton v-if="loading"/)
})
