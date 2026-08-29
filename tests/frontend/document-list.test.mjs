import test from 'node:test'
import assert from 'node:assert/strict'

import {
    buildDocumentListQuery,
    buildDocumentListRequestQuery,
    DOCUMENT_LIST_DEFAULT_PER_PAGE,
    DOCUMENT_LIST_PER_PAGE_OPTIONS,
    DOCUMENT_SEARCH_DEBOUNCE_MS,
    DOCUMENT_SEARCH_MAX_LENGTH,
    getDocumentPaginationState,
    isValidDocumentListResponse,
    normalizeDocumentSearch,
    normalizeDocumentView,
    normalizeIncomingState,
    parseDocumentListQuery,
    resetDocumentListPage,
} from '../../resources/js/lib/document-list.js'

const validResponse = {
    data: [{ id: 1, tracking_no: 'DOC-1' }],
    meta: {
        current_page: 1,
        last_page: 2,
        per_page: 25,
        total: 30,
        from: 1,
        to: 25,
    },
    links: {
        first: '/api/documents?page=1',
        last: '/api/documents?page=2',
        prev: null,
        next: '/api/documents?page=2',
    },
}

test('normalizes view, state, and bounded search values', () => {
    assert.equal(normalizeDocumentView('incoming'), 'incoming')
    assert.equal(normalizeDocumentView('invalid'), 'all')
    assert.equal(normalizeIncomingState('received'), 'received')
    assert.equal(normalizeIncomingState('invalid'), 'all')
    const overlong = `  ${'x'.repeat(DOCUMENT_SEARCH_MAX_LENGTH + 20)}  `
    assert.equal(
        normalizeDocumentSearch(overlong),
        'x'.repeat(DOCUMENT_SEARCH_MAX_LENGTH)
    )
})

test('parses page and per-page URL state with safe defaults', () => {
    assert.deepEqual(parseDocumentListQuery({
        view: 'incoming',
        search: ' memo ',
        state: 'pending',
        page: '3',
        per_page: '50',
    }), {
        view: 'incoming',
        search: 'memo',
        incomingState: 'pending',
        page: 3,
        perPage: 50,
    })
    assert.deepEqual(parseDocumentListQuery({
        view: 'invalid', page: '0', per_page: '11', state: 'pending',
    }), {
        view: 'all',
        search: '',
        incomingState: 'all',
        page: 1,
        perPage: DOCUMENT_LIST_DEFAULT_PER_PAGE,
    })
})

test('builds canonical URL state and omits defaults', () => {
    assert.deepEqual(buildDocumentListQuery({
        view: 'all', page: 1, perPage: 25,
    }), { view: 'all' })
    assert.deepEqual(buildDocumentListQuery({
        view: 'incoming',
        search: 'budget',
        incomingState: 'received',
        page: 2,
        perPage: 10,
    }), {
        view: 'incoming',
        search: 'budget',
        state: 'received',
        page: '2',
        per_page: '10',
    })
    assert.deepEqual(buildDocumentListQuery({
        view: 'outgoing', incomingState: 'pending',
    }), { view: 'outgoing' })
})

test('resets page without mutating source state', () => {
    const source = Object.freeze({
        view: 'incoming', page: 4, perPage: 50, search: 'old',
    })
    const result = resetDocumentListPage(source, { search: 'new' })

    assert.deepEqual(result, {
        view: 'incoming', page: 1, perPage: 50, search: 'new',
    })
    assert.equal(source.page, 4)
    assert.equal(source.search, 'old')
})

test('builds only approved backend request parameters', () => {
    assert.deepEqual(buildDocumentListRequestQuery({
        view: 'incoming',
        search: 'memo',
        incomingState: 'pending',
        page: 2,
        perPage: 50,
        sort: 'title',
        token: 'secret',
    }), {
        page: '2',
        per_page: '50',
        search: 'memo',
        state: 'pending',
    })
    assert.deepEqual(buildDocumentListRequestQuery({
        view: 'outgoing', incomingState: 'received', page: 1, perPage: 25,
    }), { page: '1', per_page: '25' })
})

test('exports a small fixed debounce interval and allowed page sizes', () => {
    assert.equal(DOCUMENT_SEARCH_DEBOUNCE_MS, 300)
    assert.deepEqual(DOCUMENT_LIST_PER_PAGE_OPTIONS, [10, 25, 50])
})

test('accepts a complete valid paginated response without mutation', () => {
    const payload = structuredClone(validResponse)
    const before = structuredClone(payload)

    assert.equal(isValidDocumentListResponse(payload), true)
    assert.deepEqual(payload, before)
})

test('rejects malformed data, metadata, and links', () => {
    const invalidPayloads = [
        null,
        [],
        { ...validResponse, data: null },
        { ...validResponse, data: [null] },
        { ...validResponse, data: [[]] },
        { ...validResponse, data: [{ id: 0 }] },
        { ...validResponse, data: [{ id: '1' }] },
        { ...validResponse, meta: null },
        { ...validResponse, meta: { ...validResponse.meta, current_page: 0 } },
        { ...validResponse, meta: { ...validResponse.meta, per_page: 11 } },
        { ...validResponse, meta: { ...validResponse.meta, total: -1 } },
        { ...validResponse, meta: { ...validResponse.meta, from: null } },
        { ...validResponse, links: null },
        { ...validResponse, links: { ...validResponse.links, first: null } },
        { ...validResponse, links: { ...validResponse.links, next: 2 } },
    ]

    for (const payload of invalidPayloads) {
        assert.equal(isValidDocumentListResponse(payload), false)
    }
})

test('accepts an empty first page response', () => {
    const payload = structuredClone(validResponse)
    payload.data = []
    payload.meta = {
        current_page: 1,
        last_page: 1,
        per_page: 25,
        total: 0,
        from: null,
        to: null,
    }
    payload.links.last = '/api/documents?page=1'
    payload.links.next = null

    assert.equal(isValidDocumentListResponse(payload), true)
})

test('calculates previous and next boundaries', () => {
    assert.deepEqual(getDocumentPaginationState({
        current_page: 1, last_page: 3,
    }), {
        canGoPrevious: false,
        canGoNext: true,
        previousPage: 1,
        nextPage: 2,
    })
    assert.deepEqual(getDocumentPaginationState({
        current_page: 3, last_page: 3,
    }), {
        canGoPrevious: true,
        canGoNext: false,
        previousPage: 2,
        nextPage: 3,
    })
})
