import test from 'node:test'
import assert from 'node:assert/strict'
import {
    buildInventoryUrl,
    canBeginVoid,
    canVoidInventoryItem,
    createInventoryManager,
    inventoryFailureMessage,
    isValidInventoryResponse,
    voidConfirmationText,
} from '../../resources/js/lib/qrInventory.js'

const jsonResponse = (status, body = {}) => ({
    ok: status >= 200 && status < 300,
    status,
    json: async () => body,
})

const requestedPage = url => new URL(url, 'http://local.test').searchParams.get('page')

const pagePayload = ({ page, total, ids }) => ({
    data: ids.map(id => ({ ...item, id })),
    meta: {
        current_page: page,
        last_page: Math.max(1, Math.ceil(total / 10)),
        per_page: 10,
        total,
        from: total === 0 ? null : ((page - 1) * 10) + 1,
        to: total === 0 ? null : ((page - 1) * 10) + ids.length,
    },
})

const managerFor = (fetchImpl, states = [], options = {}) => createInventoryManager({
    fetchImpl,
    getToken: () => 'test-token',
    onUnauthorized: options.onUnauthorized || (() => {}),
    onVoided: options.onVoided || (() => {}),
    onState: state => states.push(state),
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

const item = Object.freeze({
    id: 205,
    status: 'unused',
    issued_at: '2026-09-03T03:13:05+00:00',
    linked: false,
})

const response = Object.freeze({
    data: Object.freeze([item]),
    meta: Object.freeze({
        current_page: 1,
        last_page: 1,
        per_page: 10,
        total: 1,
        from: 1,
        to: 1,
    }),
})

test('accepts the strict token-free inventory contract without mutation', () => {
    const payload = structuredClone(response)
    const before = structuredClone(payload)
    assert.equal(isValidInventoryResponse(payload), true)
    assert.deepEqual(payload, before)
    assert.equal(JSON.stringify(payload).includes('qr_token'), false)
})

test('rejects extra token and unrelated inventory fields', () => {
    for (const extra of [
        { qr_token: 'excluded' }, { document_id: 9 }, { generated_by: 3 },
        { email: 'excluded@example.test' }, { title: 'excluded' },
    ]) {
        const payload = structuredClone(response)
        Object.assign(payload.data[0], extra)
        assert.equal(isValidInventoryResponse(payload), false)
    }
})

test('rejects malformed responses and unsafe pagination boundaries', () => {
    const changes = [
        { data: null },
        { meta: { ...response.meta, current_page: 0 } },
        { meta: { ...response.meta, current_page: 3 } },
        { meta: { ...response.meta, per_page: 11 } },
        { meta: { ...response.meta, total: -1 } },
        { meta: { ...response.meta, from: null } },
        { meta: { ...response.meta, last_page: 3 } },
        { meta: { ...response.meta, to: 9 } },
        { meta: { ...response.meta, extra: true } },
    ]
    for (const change of changes) {
        assert.equal(isValidInventoryResponse({ ...structuredClone(response), ...change }), false)
    }
})

test('rejects unsafe item identifiers timestamps statuses and booleans', () => {
    for (const change of [
        { id: -1 }, { id: 1.5 }, { id: Number.MAX_SAFE_INTEGER + 1 }, { id: '1' },
        { issued_at: 'not-a-time' }, { issued_at: '2026-02-30T00:00:00Z' },
        { status: 'stale' }, { linked: 0 },
    ]) {
        const payload = structuredClone(response)
        Object.assign(payload.data[0], change)
        assert.equal(isValidInventoryResponse(payload), false)
    }
})

test('rejects incomplete pages and non-deterministic row ordering', () => {
    const fullPage = {
        data: Array.from({ length: 10 }, (_, index) => ({
            ...item,
            id: 220 - index,
        })),
        meta: {
            current_page: 1,
            last_page: 2,
            per_page: 10,
            total: 20,
            from: 1,
            to: 10,
        },
    }
    assert.equal(isValidInventoryResponse(fullPage), true)
    assert.equal(isValidInventoryResponse({ ...fullPage, data: fullPage.data.slice(0, 9) }), false)

    const incorrectlyOrdered = structuredClone(fullPage)
    incorrectlyOrdered.data[1].id = 999
    assert.equal(isValidInventoryResponse(incorrectlyOrdered), false)
})

test('accepts an exact empty inventory page', () => {
    const payload = {
        data: [],
        meta: { current_page: 1, last_page: 1, per_page: 10, total: 0, from: null, to: null },
    }
    assert.equal(isValidInventoryResponse(payload), true)
})

test('builds bounded inventory URLs without mutating input', () => {
    const options = { page: 2, perPage: 25, status: 'unused' }
    const before = structuredClone(options)
    assert.equal(buildInventoryUrl(options), '/api/qr-codes/inventory?page=2&per_page=25&status=unused')
    assert.deepEqual(options, before)
    assert.equal(buildInventoryUrl({ status: 'hostile' }).includes('hostile'), false)
    assert.equal(buildInventoryUrl({ page: -1, perPage: 999 }), '/api/qr-codes/inventory?page=1&per_page=10')
})

test('only unused unlinked records are voidable', () => {
    assert.equal(canVoidInventoryItem(item), true)
    for (const change of [
        { status: 'registered' }, { status: 'void' }, { status: 'quarantined' },
        { linked: true }, { id: 0 },
    ]) assert.equal(canVoidInventoryItem({ ...item, ...change }), false)
})

test('confirmation identity contains only safe ID and issuance time', () => {
    const message = voidConfirmationText(item)
    assert.match(message, /#205/)
    assert.match(message, /2026-09-03T03:13:05/)
    assert.equal(message.includes('token'), false)
    assert.equal(voidConfirmationText({ ...item, linked: true }), '')
})

test('pending state prevents duplicate void submissions', () => {
    assert.equal(canBeginVoid(null, item), true)
    assert.equal(canBeginVoid(205, item), false)
    assert.equal(canBeginVoid(999, item), false)
})

test('uses fixed safe authentication lifecycle and transport messages', () => {
    assert.equal(inventoryFailureMessage(401), 'Authentication is required.')
    assert.equal(inventoryFailureMessage(403), 'You are not authorized to manage QR records.')
    assert.equal(inventoryFailureMessage(409), 'This QR record changed and can no longer be voided.')
    assert.equal(inventoryFailureMessage(422), 'The QR inventory request is invalid.')
    assert.equal(inventoryFailureMessage(500), 'Unable to load QR inventory. Please try again.')
    assert.equal(inventoryFailureMessage(undefined), 'Unable to load QR inventory. Please try again.')
})

test('a refreshed successful response safely reflects void lifecycle', () => {
    const refreshed = structuredClone(response)
    refreshed.data[0].status = 'void'
    assert.equal(isValidInventoryResponse(refreshed), true)
    assert.equal(canVoidInventoryItem(refreshed.data[0]), false)
})

test('voiding the only last-page item recovers once to canonical page one without stale actions', async () => {
    const states = []
    const calls = []
    const pageTwo = pagePayload({ page: 2, total: 11, ids: [205] })
    const pageOne = pagePayload({ page: 1, total: 10, ids: [204, 203, 202, 201, 200, 199, 198, 197, 196, 195] })
    const manager = managerFor(async (url, options = {}) => {
        calls.push([url, options.method || 'GET'])
        if (options.method === 'POST') return jsonResponse(200)
        if (requestedPage(url) === '2' && calls.length === 1) return jsonResponse(200, pageTwo)
        if (requestedPage(url) === '2') return jsonResponse(422)
        return jsonResponse(200, pageOne)
    }, states)

    assert.equal((await manager.load({ page: 2, perPage: 10, status: 'unused' })).kind, 'success')
    const source = structuredClone(pageTwo)
    assert.equal((await manager.voidItem(pageTwo.data[0])).kind, 'success')
    assert.deepEqual(pageTwo, source)

    const final = manager.snapshot()
    assert.equal(final.page, 1)
    assert.equal(final.requestUrl, '/api/qr-codes/inventory?page=1&per_page=10&status=unused')
    assert.equal(final.error, '')
    assert.match(final.notice, /#205/)
    assert.equal(final.items.some(row => row.id === 205), false)
    assert.equal(final.items.some(canVoidInventoryItem), true)
    assert.equal(states.slice(2).some(state => state.items.some(row => row.id === 205)), false)
    assert.equal(calls.filter(([url]) => requestedPage(url) === '2').length, 2)
    assert.equal(calls.filter(([url]) => requestedPage(url) === '1').length, 1)
})

test('409 collapse refresh recovers once and preserves only the fixed conflict notice', async () => {
    const states = []
    const pageTwo = pagePayload({ page: 2, total: 11, ids: [205] })
    const pageOne = pagePayload({ page: 1, total: 10, ids: [204, 203, 202, 201, 200, 199, 198, 197, 196, 195] })
    let pageTwoReads = 0
    const manager = managerFor(async (url, options = {}) => {
        if (options.method === 'POST') return jsonResponse(409, { message: 'unsafe raw conflict' })
        if (requestedPage(url) === '2') {
            pageTwoReads += 1
            return pageTwoReads === 1 ? jsonResponse(200, pageTwo) : jsonResponse(422)
        }
        return jsonResponse(200, pageOne)
    }, states)

    await manager.load({ page: 2, perPage: 10, status: 'unused' })
    const result = await manager.voidItem(pageTwo.data[0])
    const final = manager.snapshot()
    assert.equal(result.kind, 'conflict')
    assert.equal(result.refreshed.recovered, true)
    assert.equal(final.page, 1)
    assert.equal(final.error, '')
    assert.equal(final.notice, inventoryFailureMessage(409))
    assert.equal(final.notice.includes('unsafe raw conflict'), false)
    assert.equal(final.items.some(row => row.id === 205), false)
})

test('failed collapsed-page recovery is bounded safe empty and retryable', async () => {
    const pageTwo = pagePayload({ page: 2, total: 11, ids: [205] })
    let reads = 0
    const manager = managerFor(async (url, options = {}) => {
        if (options.method === 'POST') return jsonResponse(200)
        reads += 1
        if (reads === 1) return jsonResponse(200, pageTwo)
        return requestedPage(url) === '2' ? jsonResponse(422) : jsonResponse(503, { message: 'unsafe detail' })
    })

    await manager.load({ page: 2, perPage: 10, status: 'unused' })
    const result = await manager.voidItem(pageTwo.data[0])
    const final = manager.snapshot()
    assert.equal(result.refreshed.kind, 'error')
    assert.deepEqual(final.items, [])
    assert.equal(final.meta, null)
    assert.equal(final.error, inventoryFailureMessage())
    assert.equal(final.error.includes('unsafe detail'), false)
    assert.equal(typeof manager.retry, 'function')
    assert.equal(reads, 3)
})

test('real network rejection and current AbortError produce safe non-actionable states', async () => {
    for (const failure of [
        new Error('unsafe network diagnostic'),
        Object.assign(new Error('unsafe abort diagnostic'), { name: 'AbortError' }),
    ]) {
        const manager = managerFor(async () => { throw failure })
        const result = await manager.load({ page: 1, perPage: 10, status: 'unused' })
        const final = manager.snapshot()
        assert.deepEqual(final.items, [])
        assert.equal(final.loading, false)
        assert.equal(final.error, failure.name === 'AbortError' ? '' : inventoryFailureMessage())
        assert.equal(result.kind, failure.name === 'AbortError' ? 'aborted' : 'error')
    }
})

test('void network rejection and AbortError also clear every actionable row safely', async () => {
    for (const failure of [
        new Error('unsafe void network diagnostic'),
        Object.assign(new Error('unsafe void abort diagnostic'), { name: 'AbortError' }),
    ]) {
        const initial = pagePayload({ page: 1, total: 1, ids: [205] })
        let loaded = false
        const manager = managerFor(async (url, options = {}) => {
            if (options.method === 'POST') throw failure
            loaded = true
            return jsonResponse(200, initial)
        })
        await manager.load({ page: 1, perPage: 10, status: 'unused' })
        assert.equal(loaded, true)
        const result = await manager.voidItem(initial.data[0])
        const final = manager.snapshot()
        assert.deepEqual(final.items, [])
        assert.equal(final.meta, null)
        assert.equal(final.error, failure.name === 'AbortError' ? '' : 'Unable to void this QR record. Please try again.')
        assert.equal(result.kind, failure.name === 'AbortError' ? 'aborted' : 'error')
    }
})

test('newer inventory request aborts and suppresses older success and failure completions', async () => {
    for (const oldOutcome of ['success', 'failure']) {
        let settleOld
        const oldPromise = new Promise((resolve, reject) => {
            settleOld = oldOutcome === 'success'
                ? () => resolve(jsonResponse(200, pagePayload({ page: 1, total: 1, ids: [205] })))
                : () => reject(new Error('unsafe stale failure'))
        })
        const newest = pagePayload({ page: 1, total: 1, ids: [300] })
        let call = 0
        const manager = managerFor(async () => (++call === 1 ? oldPromise : jsonResponse(200, newest)))
        const oldRequest = manager.load({ page: 1, perPage: 10, status: 'unused' })
        const newRequest = manager.load({ page: 1, perPage: 10, status: 'unused' })
        assert.equal((await newRequest).kind, 'success')
        settleOld()
        assert.equal((await oldRequest).kind, 'stale')
        assert.deepEqual(manager.snapshot().items.map(row => row.id), [300])
        assert.equal(manager.snapshot().error, '')
    }
})

test('duplicate void submission is rejected while the real request is pending', async () => {
    let finishVoid
    const pendingVoid = new Promise(resolve => { finishVoid = () => resolve(jsonResponse(200)) })
    const initial = pagePayload({ page: 1, total: 1, ids: [205] })
    let loaded = false
    const manager = managerFor(async (url, options = {}) => {
        if (options.method === 'POST') return pendingVoid
        if (!loaded) {
            loaded = true
            return jsonResponse(200, initial)
        }
        return jsonResponse(200, pagePayload({ page: 1, total: 0, ids: [] }))
    })
    await manager.load({ page: 1, perPage: 10, status: 'unused' })
    const first = manager.voidItem(initial.data[0])
    assert.equal((await manager.voidItem(initial.data[0])).kind, 'duplicate')
    assert.deepEqual(manager.snapshot().items, [])
    finishVoid()
    assert.equal((await first).kind, 'success')
})

test('dispose aborts inventory and suppresses late success failure and 401 callbacks', async () => {
    for (const outcome of ['success', 'failure', 'unauthorized']) {
        const request = deferred()
        const states = []
        let signal
        let unauthorized = 0
        const manager = managerFor(async (url, options) => {
            signal = options.signal
            return request.promise
        }, states, { onUnauthorized: () => { unauthorized += 1 } })
        const pending = manager.load({ page: 1, perPage: 10, status: 'unused' })
        const publishedBeforeDispose = states.length

        manager.dispose()
        manager.dispose()
        assert.equal(signal.aborted, true)

        if (outcome === 'success') request.resolve(jsonResponse(200, response))
        if (outcome === 'failure') request.reject(new Error('unsafe late inventory failure'))
        if (outcome === 'unauthorized') request.resolve(jsonResponse(401, { message: 'unsafe late auth detail' }))

        assert.equal((await pending).kind, 'disposed')
        assert.equal(states.length, publishedBeforeDispose)
        assert.equal(unauthorized, 0)
    }
})

test('dispose aborts void and suppresses every late outcome callback refresh and mutation retry', async () => {
    for (const outcome of ['success', 'conflict', 'unauthorized', 'validation', 'network', 'abort']) {
        const request = deferred()
        const states = []
        let voidSignal
        let fetchCalls = 0
        let unauthorized = 0
        let voided = 0
        const initial = pagePayload({ page: 1, total: 1, ids: [205] })
        const manager = managerFor(async (url, options = {}) => {
            fetchCalls += 1
            if (options.method === 'POST') {
                voidSignal = options.signal
                return request.promise
            }
            return jsonResponse(200, initial)
        }, states, {
            onUnauthorized: () => { unauthorized += 1 },
            onVoided: () => { voided += 1 },
        })

        await manager.load({ page: 1, perPage: 10, status: 'unused' })
        const pending = manager.voidItem(initial.data[0])
        const publishedBeforeDispose = states.length
        manager.dispose()
        assert.equal(voidSignal.aborted, true)

        if (outcome === 'success') request.resolve(jsonResponse(200))
        if (outcome === 'conflict') request.resolve(jsonResponse(409, { message: 'unsafe late conflict' }))
        if (outcome === 'unauthorized') request.resolve(jsonResponse(401, { message: 'unsafe late auth' }))
        if (outcome === 'validation') request.resolve(jsonResponse(422, { message: 'unsafe late validation' }))
        if (outcome === 'network') request.reject(new Error('unsafe late network failure'))
        if (outcome === 'abort') request.reject(Object.assign(new Error('unsafe late abort'), { name: 'AbortError' }))

        assert.equal((await pending).kind, 'disposed')
        assert.equal(states.length, publishedBeforeDispose)
        assert.equal(fetchCalls, 2)
        assert.equal(unauthorized, 0)
        assert.equal(voided, 0)
    }
})

test('disposed coordinator refuses fetch retry recovery and void while a remount works normally', async () => {
    let disposedFetches = 0
    const disposedManager = managerFor(async () => {
        disposedFetches += 1
        return jsonResponse(500)
    })
    disposedManager.dispose()

    assert.equal((await disposedManager.load()).kind, 'disposed')
    assert.equal((await disposedManager.load({ page: 2 }, true)).kind, 'disposed')
    assert.equal((await disposedManager.retry()).kind, 'disposed')
    assert.equal((await disposedManager.voidItem(item)).kind, 'disposed')
    assert.equal(disposedFetches, 0)

    const authoritative = pagePayload({ page: 1, total: 1, ids: [300] })
    const remounted = managerFor(async () => jsonResponse(200, authoritative))
    assert.equal((await remounted.load({ page: 1, perPage: 10, status: 'unused' })).kind, 'success')
    assert.deepEqual(remounted.snapshot().items.map(row => row.id), [300])
})

test('disposed late rejections are handled without an unhandled rejection', async () => {
    const request = deferred()
    const unhandled = []
    const listener = reason => unhandled.push(reason)
    process.on('unhandledRejection', listener)

    try {
        const manager = managerFor(async () => request.promise)
        const pending = manager.load()
        manager.dispose()
        request.reject(new Error('late handled rejection'))
        assert.equal((await pending).kind, 'disposed')
        await new Promise(resolve => setImmediate(resolve))
        assert.deepEqual(unhandled, [])
    } finally {
        process.off('unhandledRejection', listener)
    }
})
