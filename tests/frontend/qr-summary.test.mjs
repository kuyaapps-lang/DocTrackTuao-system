import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import {
    createQrSummaryManager,
    emptyQrSummary,
    isValidQrSummary,
    qrSummaryFailureMessage,
} from '../../resources/js/lib/qrSummary.js'
import { createInventoryManager } from '../../resources/js/lib/qrInventory.js'

const valid = Object.freeze({
    total_issued: 4,
    counts: Object.freeze({ unused: 1, registered: 1, void: 1 }),
    latest_issued_at: '2026-09-04T08:00:00+00:00',
})
const empty = Object.freeze(emptyQrSummary())
const response = (status, body = valid) => ({
    status,
    ok: status >= 200 && status < 300,
    json: async () => structuredClone(body),
})
const deferred = () => {
    let resolve
    let reject
    const promise = new Promise((resolvePromise, rejectPromise) => {
        resolve = resolvePromise
        reject = rejectPromise
    })
    return { promise, resolve, reject }
}
const managerFor = (fetchImpl, states = [], options = {}) => createQrSummaryManager({
    fetchImpl,
    getToken: () => 'test-token',
    onUnauthorized: options.onUnauthorized || (() => {}),
    onState: state => states.push(state),
})

test('accepts exact successful and empty token-free summary shapes', async () => {
    assert.equal(isValidQrSummary(valid), true)
    assert.equal(isValidQrSummary(empty), true)
    for (const payload of [valid, empty]) {
        const calls = []
        const manager = managerFor(async (url, options) => {
            calls.push([url, options])
            return response(200, payload)
        })
        assert.equal((await manager.load()).kind, 'success')
        assert.deepEqual(manager.snapshot().summary, payload)
        assert.equal(calls[0][0], '/api/qr-codes/summary')
        assert.equal(calls[0][1].headers.Authorization, 'Bearer test-token')
        assert.equal('signal' in calls[0][1], true)
    }
})

test('rejects malformed extra and internally inconsistent response shapes', () => {
    for (const payload of [
        null,
        { ...valid, qr_token: 'sensitive' },
        { ...valid, total_issued: -1 },
        { ...valid, total_issued: 2 },
        { ...valid, latest_issued_at: null },
        { ...valid, counts: { ...valid.counts, extra: 1 } },
        { ...valid, latest_issued_at: 'not-a-date' },
    ]) assert.equal(isValidQrSummary(payload), false)
})

test('uses fixed safe 401 403 malformed-json and network messages', async () => {
    assert.equal(qrSummaryFailureMessage(401), 'Authentication is required.')
    assert.equal(qrSummaryFailureMessage(403), 'You are not authorized to view QR records.')
    assert.equal(qrSummaryFailureMessage(), 'Unable to load QR summary. Please try again.')

    let unauthorized = 0
    const unauthorizedManager = managerFor(async () => response(401, { message: 'unsafe auth detail' }), [], {
        onUnauthorized: () => { unauthorized += 1 },
    })
    assert.equal((await unauthorizedManager.load()).kind, 'unauthorized')
    assert.equal(unauthorized, 1)

    const cases = [
        async () => response(403, { message: 'unsafe permission detail' }),
        async () => ({ status: 200, ok: true, json: async () => { throw new Error('unsafe parser detail') } }),
        async () => { throw new Error('unsafe network detail') },
    ]
    for (const [index, fetchImpl] of cases.entries()) {
        const manager = managerFor(fetchImpl)
        const result = await manager.load()
        assert.equal(result.kind, 'error')
        assert.equal(manager.snapshot().error, index === 0
            ? qrSummaryFailureMessage(403)
            : qrSummaryFailureMessage())
        assert.doesNotMatch(manager.snapshot().error, /unsafe|parser|network/)
    }
})

test('abort retry and superseded completions follow owned request lifecycle', async () => {
    const abortManager = managerFor(async () => {
        throw Object.assign(new Error('unsafe abort'), { name: 'AbortError' })
    })
    assert.equal((await abortManager.load()).kind, 'aborted')
    assert.equal(abortManager.snapshot().error, '')

    let attempts = 0
    const retryManager = managerFor(async () => ++attempts === 1
        ? response(503, { message: 'unsafe retry body' })
        : response(200, valid))
    assert.equal((await retryManager.load()).kind, 'error')
    assert.equal((await retryManager.retry()).kind, 'success')
    assert.equal(attempts, 2)

    for (const outcome of ['success', 'failure']) {
        const old = deferred()
        const newest = { ...valid, total_issued: 5, counts: { unused: 2, registered: 1, void: 1 } }
        let call = 0
        let oldSignal
        const manager = managerFor(async (url, options) => {
            if (++call === 1) {
                oldSignal = options.signal
                return old.promise
            }
            return response(200, newest)
        })
        const first = manager.load()
        const second = manager.load()
        assert.equal(oldSignal.aborted, true)
        assert.equal((await second).kind, 'success')
        if (outcome === 'success') old.resolve(response(200, valid))
        else old.reject(new Error('unsafe stale failure'))
        assert.equal((await first).kind, 'stale')
        assert.deepEqual(manager.snapshot().summary, newest)
    }
})

test('dispose aborts and suppresses late success failure and authentication handling', async () => {
    for (const outcome of ['success', 'failure', 'unauthorized']) {
        const pendingResponse = deferred()
        const states = []
        let signal
        let unauthorized = 0
        const manager = managerFor(async (url, options) => {
            signal = options.signal
            return pendingResponse.promise
        }, states, { onUnauthorized: () => { unauthorized += 1 } })
        const pending = manager.load()
        const published = states.length
        manager.dispose()
        assert.equal(signal.aborted, true)
        if (outcome === 'success') pendingResponse.resolve(response(200, valid))
        if (outcome === 'failure') pendingResponse.reject(new Error('unsafe late failure'))
        if (outcome === 'unauthorized') pendingResponse.resolve(response(401, { message: 'unsafe late auth' }))
        assert.equal((await pending).kind, 'disposed')
        assert.equal(states.length, published)
        assert.equal(unauthorized, 0)
        assert.equal((await manager.retry()).kind, 'disposed')
    }
})

test('summary and inventory own independent requests and state', async () => {
    const summaryPending = deferred()
    const summaryStates = []
    const inventoryStates = []
    const summary = managerFor(async () => summaryPending.promise, summaryStates)
    const inventory = createInventoryManager({
        fetchImpl: async () => response(503),
        getToken: () => 'test-token',
        onUnauthorized: () => {},
        onState: state => inventoryStates.push(state),
    })
    const pending = summary.load()
    assert.equal((await inventory.load()).kind, 'error')
    assert.equal(summary.snapshot().loading, true)
    assert.equal(summary.snapshot().error, '')
    summaryPending.resolve(response(200, valid))
    assert.equal((await pending).kind, 'success')
    assert.equal(inventoryStates.at(-1).error.length > 0, true)
})

test('production QR page uses summary lifecycle refreshes and does not retry mutations', () => {
    const source = fs.readFileSync(new URL('../../resources/js/pages/QrCodes.vue', import.meta.url), 'utf8')
    assert.match(source, /createQrSummaryManager/)
    assert.match(source, /onVoided:\s*\(\)\s*=>\s*{\s*void fetchSummary\(\)/s)
    assert.match(source, /lastGeneratedBatch\.value[\s\S]*await fetchSummary\(\)/)
    assert.match(source, /summaryManager\.dispose\(\)/)
    assert.doesNotMatch(source, /fetchQrCodes|qrCodes\.value/)
    const mutationCalls = [...source.matchAll(/fetch\(\s*'\/api\/qr-codes'/g)]
    assert.equal(mutationCalls.length, 1)
})
