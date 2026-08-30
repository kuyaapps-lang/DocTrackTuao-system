import test from 'node:test'
import assert from 'node:assert/strict'
import { buildDashboardQuery, buildDashboardRequestUrl, calculateDashboardPercentage, dashboardRequestKey, isValidDashboardResponse, isValidDashboardTimestamp, normalizeDashboardMonth } from '../../resources/js/lib/dashboard.js'

const validResponse = {
    filters: { month: null, timezone: 'Asia/Manila' }, scope: { type: 'system', office: null },
    summary: { total_documents: 2, incoming_movements: 3, outgoing_movements: 4, in_transit_documents: 1, received_documents: 1 },
    status_distribution: [{ status: { id: 1, name: 'Received' }, count: 2 }],
    current_office_distribution: [{ office: { id: 2, name: 'Mayor' }, count: 2 }],
    origin_office_distribution: [{ office: { id: null, name: 'Unassigned' }, count: 1 }],
    recent_documents: [{ id: 8, tracking_no: 'DOC-008', status: { id: null, name: 'Unassigned' }, created_at: '2026-08-20T01:00:00+00:00' }],
    recent_routing_activity: [{ document: { id: 8, tracking_no: 'DOC-008' }, event_type: 'forwarded', from_office: { id: 1, name: 'Records' }, to_office: { id: 2, name: 'Mayor' }, occurred_at: '2026-08-20T02:00:00+00:00' }],
}

test('normalizes strict supported months', () => {
    assert.equal(normalizeDashboardMonth('2026-08'), '2026-08')
    for (const value of [undefined, null, ['2026-08'], '2026-8', '0000-08', '2026-13']) assert.equal(normalizeDashboardMonth(value), null)
})
test('builds canonical URL and omits all-time default', () => {
    assert.deepEqual(buildDashboardQuery(null), {}); assert.deepEqual(buildDashboardQuery('invalid'), {}); assert.deepEqual(buildDashboardQuery('2026-08'), { month: '2026-08' })
    assert.equal(buildDashboardRequestUrl(null), '/api/dashboard/summary'); assert.equal(buildDashboardRequestUrl('2026-08'), '/api/dashboard/summary?month=2026-08')
})
test('uses stable request keys', () => { assert.equal(dashboardRequestKey(null), 'all-time'); assert.equal(dashboardRequestKey('invalid'), 'all-time'); assert.equal(dashboardRequestKey('2026-08'), '2026-08') })
test('accepts complete response without mutation', () => { const payload = structuredClone(validResponse); const before = structuredClone(payload); assert.equal(isValidDashboardResponse(payload), true); assert.deepEqual(payload, before) })
test('accepts office scope and empty arrays', () => { const payload = structuredClone(validResponse); payload.scope = { type: 'office', office: { id: 4, name: 'Treasury' } }; for (const key of ['status_distribution', 'current_office_distribution', 'origin_office_distribution', 'recent_documents', 'recent_routing_activity']) payload[key] = []; assert.equal(isValidDashboardResponse(payload), true) })
test('rejects unsafe filters scopes and metrics', () => {
    const invalid = [{ ...validResponse, extra: true }, { ...validResponse, filters: { month: '2026-13', timezone: 'Asia/Manila' } }, { ...validResponse, scope: { type: 'system', office: { id: 1, name: 'Hidden' } } }, { ...validResponse, scope: { type: 'office', office: null } }, { ...validResponse, summary: { ...validResponse.summary, total_documents: -1 } }, { ...validResponse, summary: { ...validResponse.summary, total_documents: Infinity } }, { ...validResponse, summary: { ...validResponse.summary, incoming_movements: 1.5 } }]
    for (const payload of invalid) assert.equal(isValidDashboardResponse(payload), false)
})
test('validates distributions', () => {
    const changes = [{ status_distribution: [{ status: { id: 1, name: '' }, count: 1 }] }, { status_distribution: [{ status: { id: 1, name: 'Open' }, count: -1 }] }, { current_office_distribution: [{ office: { id: '1', name: 'Mayor' }, count: 1 }] }, { origin_office_distribution: [{ office: { id: null, name: 'Unassigned' }, count: NaN }] }]
    for (const change of changes) assert.equal(isValidDashboardResponse({ ...validResponse, ...change }), false)
})
test('validates recent documents', () => {
    const items = [{ ...validResponse.recent_documents[0], id: 0 }, { ...validResponse.recent_documents[0], tracking_no: '' }, { ...validResponse.recent_documents[0], status: { id: 1, name: '' } }, { ...validResponse.recent_documents[0], created_at: null }, { ...validResponse.recent_documents[0], created_at: 'not-a-timestamp' }, { ...validResponse.recent_documents[0], title: 'Excluded' }]
    for (const item of items) assert.equal(isValidDashboardResponse({ ...validResponse, recent_documents: [item] }), false)
})
test('validates recent routing activity', () => {
    const items = [{ ...validResponse.recent_routing_activity[0], event_type: 'deleted' }, { ...validResponse.recent_routing_activity[0], occurred_at: '' }, { ...validResponse.recent_routing_activity[0], from_office: { id: null, name: 'Unassigned' } }, { ...validResponse.recent_routing_activity[0], document: { id: 8, tracking_no: '', title: 'Secret' } }]
    for (const item of items) assert.equal(isValidDashboardResponse({ ...validResponse, recent_routing_activity: [item] }), false)
})

test('accepts all five summary metrics at zero', () => {
    const payload = structuredClone(validResponse)
    for (const key of Object.keys(payload.summary)) payload.summary[key] = 0
    assert.equal(isValidDashboardResponse(payload), true)
})

test('rejects unsafe integer identifiers metrics and counts', () => {
    const unsafe = Number.MAX_SAFE_INTEGER + 1
    const payloads = []
    const officeScope = structuredClone(validResponse)
    officeScope.scope = { type: 'office', office: { id: unsafe, name: 'Office' } }
    payloads.push(officeScope)
    const metric = structuredClone(validResponse)
    metric.summary.total_documents = unsafe
    payloads.push(metric)
    for (const [key, reference] of [
        ['status_distribution', 'status'],
        ['current_office_distribution', 'office'],
        ['origin_office_distribution', 'office'],
    ]) {
        const countPayload = structuredClone(validResponse)
        countPayload[key][0].count = unsafe
        payloads.push(countPayload)
        const idPayload = structuredClone(validResponse)
        idPayload[key][0][reference].id = unsafe
        payloads.push(idPayload)
    }
    const document = structuredClone(validResponse)
    document.recent_documents[0].id = unsafe
    payloads.push(document)
    const route = structuredClone(validResponse)
    route.recent_routing_activity[0].document.id = unsafe
    payloads.push(route)
    for (const payload of payloads) assert.equal(isValidDashboardResponse(payload), false)
})

test('rejects all unsafe numeric forms', () => {
    for (const value of [-1, 1.5, Infinity, NaN, '1', Number.MAX_SAFE_INTEGER + 1]) {
        const metric = structuredClone(validResponse)
        metric.summary.received_documents = value
        assert.equal(isValidDashboardResponse(metric), false)
        const count = structuredClone(validResponse)
        count.status_distribution[0].count = value
        assert.equal(isValidDashboardResponse(count), false)
    }
})

test('rejects every missing top-level field', () => {
    for (const key of Object.keys(validResponse)) {
        const payload = structuredClone(validResponse)
        delete payload[key]
        assert.equal(isValidDashboardResponse(payload), false, key)
    }
})

test('rejects missing required nested fields', () => {
    const cases = [
        ['filters', 'month'], ['filters', 'timezone'], ['scope', 'type'], ['scope', 'office'],
        ...Object.keys(validResponse.summary).map(key => ['summary', key]),
    ]
    for (const [container, key] of cases) {
        const payload = structuredClone(validResponse)
        delete payload[container][key]
        assert.equal(isValidDashboardResponse(payload), false, `${container}.${key}`)
    }
    for (const [arrayKey, referenceKey] of [
        ['status_distribution', 'status'],
        ['current_office_distribution', 'office'],
        ['origin_office_distribution', 'office'],
    ]) {
        for (const key of [referenceKey, 'count']) {
            const payload = structuredClone(validResponse)
            delete payload[arrayKey][0][key]
            assert.equal(isValidDashboardResponse(payload), false, `${arrayKey}.${key}`)
        }
        for (const key of ['id', 'name']) {
            const payload = structuredClone(validResponse)
            delete payload[arrayKey][0][referenceKey][key]
            assert.equal(isValidDashboardResponse(payload), false, `${arrayKey}.${referenceKey}.${key}`)
        }
    }
    for (const key of Object.keys(validResponse.recent_documents[0])) {
        const payload = structuredClone(validResponse)
        delete payload.recent_documents[0][key]
        assert.equal(isValidDashboardResponse(payload), false, `recent_documents.${key}`)
    }
    for (const key of Object.keys(validResponse.recent_routing_activity[0])) {
        const payload = structuredClone(validResponse)
        delete payload.recent_routing_activity[0][key]
        assert.equal(isValidDashboardResponse(payload), false, `recent_routing_activity.${key}`)
    }
})

test('rejects each omitted office-scope field independently', () => {
    const officeScopedFixture = structuredClone(validResponse)
    officeScopedFixture.scope = {
        type: 'office',
        office: { id: 4, name: 'Treasury' },
    }
    const fixtureBefore = structuredClone(officeScopedFixture)

    for (const field of ['id', 'name']) {
        const payload = structuredClone(officeScopedFixture)
        delete payload.scope.office[field]

        assert.equal(
            isValidDashboardResponse(payload),
            false,
            `omitted scope.office.${field}`
        )
        assert.deepEqual(officeScopedFixture, fixtureBefore)
    }
})

test('rejects each omitted nested recent-item field independently', () => {
    const fixtureBefore = structuredClone(validResponse)
    const omissions = [
        ['recent_documents', 0, 'status', 'id'],
        ['recent_documents', 0, 'status', 'name'],
        ['recent_routing_activity', 0, 'document', 'id'],
        ['recent_routing_activity', 0, 'document', 'tracking_no'],
        ['recent_routing_activity', 0, 'from_office', 'id'],
        ['recent_routing_activity', 0, 'from_office', 'name'],
        ['recent_routing_activity', 0, 'to_office', 'id'],
        ['recent_routing_activity', 0, 'to_office', 'name'],
    ]

    for (const path of omissions) {
        const payload = structuredClone(validResponse)
        const field = path.at(-1)
        const container = path
            .slice(0, -1)
            .reduce((value, key) => value[key], payload)
        delete container[field]

        const label = path
            .map(key => Number.isInteger(key) ? '[]' : key)
            .join('.')
            .replace('.[]', '[]')

        assert.equal(
            isValidDashboardResponse(payload),
            false,
            `omitted ${label}`
        )
        assert.deepEqual(validResponse, fixtureBefore)
    }
})

test('rejects null and object substitutions for each array', () => {
    for (const key of ['status_distribution', 'current_office_distribution', 'origin_office_distribution', 'recent_documents', 'recent_routing_activity']) {
        for (const replacement of [null, {}]) {
            const payload = structuredClone(validResponse)
            payload[key] = replacement
            assert.equal(isValidDashboardResponse(payload), false, key)
        }
    }
})

test('strictly accepts legitimate contract timestamps', () => {
    for (const value of ['2026-08-20T01:00:00+00:00', '2024-02-29T23:59:59+14:00', '2025-01-01T00:00:00-12:30']) {
        assert.equal(isValidDashboardTimestamp(value), true, value)
    }
})

test('rejects calendar-invalid contract timestamps', () => {
    for (const value of ['2026-02-29T01:00:00+00:00', '2026-02-30T01:00:00+00:00', '2026-04-31T01:00:00+00:00', '2026-13-01T01:00:00+00:00', '0000-01-01T01:00:00+00:00']) {
        assert.equal(isValidDashboardTimestamp(value), false, value)
    }
})

test('rejects malformed times offsets partial timestamps and trailing text', () => {
    for (const value of ['2026-08-20T24:00:00+00:00', '2026-08-20T01:60:00+00:00', '2026-08-20T01:00:60+00:00', '2026-08-20T01:00:00+14:01', '2026-08-20T01:00:00+15:00', '2026-08-20T01:00:00Z', '2026-08-20', '2026-08-20T01:00:00+00:00 trailing', '2026-08-20T01:00:00.123+00:00']) {
        assert.equal(isValidDashboardTimestamp(value), false, value)
    }
})

test('rejects a complete response containing an invalid raw timestamp', () => {
    const document = structuredClone(validResponse)
    document.recent_documents[0].created_at = '2026-02-30T01:00:00+00:00'
    assert.equal(isValidDashboardResponse(document), false)
    const route = structuredClone(validResponse)
    route.recent_routing_activity[0].occurred_at = '2026-04-31T01:00:00+00:00'
    assert.equal(isValidDashboardResponse(route), false)
})

test('calculates bounded dashboard percentages', () => {
    assert.equal(calculateDashboardPercentage(0, 0), 0)
    assert.equal(calculateDashboardPercentage(0, 10), 0)
    assert.equal(calculateDashboardPercentage(1, 4), 25)
    assert.equal(calculateDashboardPercentage(10, 10), 100)
    assert.equal(calculateDashboardPercentage(11, 10), 100)
    for (const values of [[1, 0], [-1, 10], [1.5, 10], [1, Infinity], ['1', 10], [Number.MAX_SAFE_INTEGER + 1, Number.MAX_SAFE_INTEGER]]) {
        assert.equal(calculateDashboardPercentage(...values), 0)
    }
})

test('percentage and response validation helpers do not mutate inputs', () => {
    const payload = structuredClone(validResponse)
    const before = structuredClone(payload)
    const values = Object.freeze([1, 4])
    assert.equal(calculateDashboardPercentage(...values), 25)
    assert.deepEqual(values, [1, 4])
    assert.equal(isValidDashboardResponse(payload), true)
    assert.deepEqual(payload, before)
})
