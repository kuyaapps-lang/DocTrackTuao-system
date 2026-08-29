import test from 'node:test'
import assert from 'node:assert/strict'

import {
    buildDocumentListQuery,
    DOCUMENT_SEARCH_MAX_LENGTH,
    filterDocuments,
    isValidDocumentListPayload,
    normalizeDocumentSearch,
    normalizeDocumentView,
    normalizeIncomingState,
    parseDocumentListQuery,
} from '../../resources/js/lib/document-list.js'

const documents = [
    {
        id: 1,
        tracking_no: 'DOC-001',
        title: 'Budget request',
        type: { id: 1, type_name: 'Memorandum' },
        current_office: { id: 2, office_name: 'Treasury' },
        routes: [{
            from_office: { id: 3, office_name: 'Mayor Office' },
            to_office: { id: 2, office_name: 'Treasury' },
            received_at: null,
        }],
    },
    {
        id: 2,
        tracking_no: 'DOC-002',
        title: 'Completed review',
        type: { id: 2, type_name: 'Letter' },
        current_office: { id: 4, office_name: 'Records' },
        routes: [{
            from_office: { id: 2, office_name: 'Treasury' },
            to_office: { id: 4, office_name: 'Records' },
            received_at: '2026-08-29T03:00:00.000000Z',
        }],
    },
    {
        id: 3,
        tracking_no: null,
        title: null,
        type: null,
        current_office: null,
        routes: [],
    },
]

test('normalizes valid and invalid document views', () => {
    assert.equal(normalizeDocumentView('all'), 'all')
    assert.equal(normalizeDocumentView('incoming'), 'incoming')
    assert.equal(normalizeDocumentView('outgoing'), 'outgoing')
    assert.equal(normalizeDocumentView('invalid'), 'all')
    assert.equal(normalizeDocumentView(undefined), 'all')
    assert.equal(normalizeDocumentView(['incoming']), 'incoming')
})

test('normalizes search and incoming state safely', () => {
    assert.equal(normalizeDocumentSearch('  Budget  '), 'Budget')
    assert.equal(normalizeDocumentSearch(null), '')
    assert.equal(normalizeIncomingState('pending'), 'pending')
    assert.equal(normalizeIncomingState('received'), 'received')
    assert.equal(normalizeIncomingState('invalid'), 'all')
})

test('trims and bounds search text with one shared maximum', () => {
    const overlong = `  ${'x'.repeat(
        DOCUMENT_SEARCH_MAX_LENGTH + 25
    )}  `
    const normalized = normalizeDocumentSearch(overlong)

    assert.equal(normalized.length, DOCUMENT_SEARCH_MAX_LENGTH)
    assert.equal(normalized, 'x'.repeat(DOCUMENT_SEARCH_MAX_LENGTH))
})

test('parses URL query state and defaults invalid values', () => {
    assert.deepEqual(parseDocumentListQuery({
        view: 'incoming',
        search: '  memo ',
        state: 'received',
    }), {
        view: 'incoming',
        search: 'memo',
        incomingState: 'received',
    })

    assert.deepEqual(parseDocumentListQuery({
        view: 'unknown',
        state: 'received',
    }), {
        view: 'all',
        search: '',
        incomingState: 'all',
    })
})

test('builds canonical URL query without empty or irrelevant filters', () => {
    assert.deepEqual(buildDocumentListQuery({
        view: 'incoming',
        search: ' budget ',
        incomingState: 'pending',
    }), {
        view: 'incoming',
        search: 'budget',
        state: 'pending',
    })

    assert.deepEqual(buildDocumentListQuery({
        view: 'outgoing',
        search: '',
        incomingState: 'received',
    }), {
        view: 'outgoing',
    })
})

test('canonicalizes overlong URL search to the shared maximum', () => {
    const search = 'q'.repeat(DOCUMENT_SEARCH_MAX_LENGTH + 1)
    const parsed = parseDocumentListQuery({ view: 'all', search })

    assert.equal(parsed.search, 'q'.repeat(DOCUMENT_SEARCH_MAX_LENGTH))
    assert.deepEqual(buildDocumentListQuery(parsed), {
        view: 'all',
        search: 'q'.repeat(DOCUMENT_SEARCH_MAX_LENGTH),
    })
})

test('searches only visible safe fields for the active view', () => {
    assert.deepEqual(
        filterDocuments(documents, {
            view: 'all',
            search: 'doc-001',
        }).map(document => document.id),
        [1]
    )
    assert.deepEqual(
        filterDocuments(documents, {
            view: 'all',
            search: 'completed review',
        }).map(document => document.id),
        [2]
    )
    assert.deepEqual(
        filterDocuments(documents, {
            view: 'all',
            search: 'treasury',
        }).map(document => document.id),
        [1]
    )
    assert.deepEqual(
        filterDocuments(documents, {
            view: 'incoming',
            search: 'mayor',
        }).map(document => document.id),
        [1]
    )
    assert.deepEqual(
        filterDocuments(documents, {
            view: 'outgoing',
            search: 'records',
        }).map(document => document.id),
        [2]
    )
    assert.deepEqual(
        filterDocuments(documents, {
            view: 'all',
            search: 'MEMORANDUM',
        }).map(document => document.id),
        [1]
    )
})

test('validates complete document list payloads', () => {
    assert.equal(isValidDocumentListPayload(documents), true)
    assert.equal(isValidDocumentListPayload([]), true)
})

test('rejects malformed document list elements and IDs', () => {
    for (const invalidElement of [
        null,
        1,
        'document',
        [],
        {},
        { id: null },
        { id: 0 },
        { id: -1 },
        { id: 1.5 },
        { id: '1' },
    ]) {
        assert.equal(
            isValidDocumentListPayload([invalidElement]),
            false
        )
    }

    assert.equal(isValidDocumentListPayload({}), false)
})

test('filters incoming pending and received routes', () => {
    assert.deepEqual(
        filterDocuments(documents, {
            view: 'incoming',
            incomingState: 'pending',
        }).map(document => document.id),
        [1]
    )
    assert.deepEqual(
        filterDocuments(documents, {
            view: 'incoming',
            incomingState: 'received',
        }).map(document => document.id),
        [2]
    )
})

test('handles null fields and invalid source values without throwing', () => {
    assert.deepEqual(
        filterDocuments(documents, {
            view: 'incoming',
            search: 'not present',
        }),
        []
    )
    assert.deepEqual(filterDocuments(null), [])
})

test('filtering does not mutate the source array or objects', () => {
    const source = structuredClone(documents)
    const before = structuredClone(source)
    const result = filterDocuments(source, {
        view: 'incoming',
        search: 'treasury',
        incomingState: 'received',
    })

    assert.notEqual(result, source)
    assert.deepEqual(source, before)

    assert.equal(isValidDocumentListPayload(source), true)
    assert.deepEqual(source, before)
})
