import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'

import {
    archiveDocumentFailureMessage,
    archiveDocumentRequest,
    canArchiveDocument,
    canCompleteDocument,
    completeDocumentFailureMessage,
    completeDocumentRequest,
    isTerminalDocument,
} from '../../resources/js/lib/document-detail.js'

const activeDocument = {
    status: { status_name: 'Received' },
}

const completedDocument = {
    status: { status_name: 'Completed' },
}

test('complete action appears only for current-office non-terminal documents without pending route', () => {
    assert.equal(canCompleteDocument({
        document: activeDocument,
        routingOptions: { can_act: true },
        pendingRoute: null,
        hasProcessPermission: true,
    }), true)

    for (const state of [
        { hasProcessPermission: false },
        { routingOptions: { can_act: false } },
        { pendingRoute: { id: 1 } },
        { document: { status: { status_name: 'Completed' } } },
        { document: { status: { status_name: 'Archived' } } },
    ]) {
        assert.equal(canCompleteDocument({
            document: activeDocument,
            routingOptions: { can_act: true },
            pendingRoute: null,
            hasProcessPermission: true,
            ...state,
        }), false)
    }
})

test('terminal document helper recognizes completed and archived states', () => {
    assert.equal(isTerminalDocument({ status: { status_name: 'Completed' } }), true)
    assert.equal(isTerminalDocument({ status: { status_name: 'completed' } }), true)
    assert.equal(isTerminalDocument({ status: { status_name: 'Archived' } }), true)
    assert.equal(isTerminalDocument({ status: { status_name: 'Received' } }), false)
    assert.equal(isTerminalDocument(null), false)
})

test('archive action appears only for current-office completed documents without pending route', () => {
    assert.equal(canArchiveDocument({
        document: completedDocument,
        routingOptions: { can_act: true },
        pendingRoute: null,
        hasProcessPermission: true,
    }), true)

    for (const state of [
        { hasProcessPermission: false },
        { routingOptions: { can_act: false } },
        { pendingRoute: { id: 1 } },
        { document: activeDocument },
        { document: { status: { status_name: 'Archived' } } },
    ]) {
        assert.equal(canArchiveDocument({
            document: completedDocument,
            routingOptions: { can_act: true },
            pendingRoute: null,
            hasProcessPermission: true,
            ...state,
        }), false)
    }
})

test('complete request posts to the narrow endpoint with bearer auth', async () => {
    const calls = []
    const payload = {
        message: 'Document completed successfully.',
        document: { id: 42 },
    }
    const result = await completeDocumentRequest({
        documentId: 42,
        token: 'safe-test-token',
        fetchImpl: async (...args) => {
            calls.push(args)
            return {
                ok: true,
                status: 200,
                json: async () => payload,
            }
        },
    })

    assert.deepEqual(result, payload)
    assert.equal(calls.length, 1)
    assert.equal(calls[0][0], '/api/documents/42/complete')
    assert.deepEqual(calls[0][1], {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            Authorization: 'Bearer safe-test-token',
        },
    })
})

test('complete request maps forbidden and conflict failures to fixed safe messages', async () => {
    await assert.rejects(
        completeDocumentRequest({
            documentId: 8,
            token: 'token',
            fetchImpl: async () => ({
                ok: false,
                status: 403,
                json: async () => ({ message: 'private server detail' }),
            }),
        }),
        { message: 'You are not allowed to complete this document.' }
    )

    await assert.rejects(
        completeDocumentRequest({
            documentId: 8,
            token: 'token',
            fetchImpl: async () => ({
                ok: false,
                status: 409,
                json: async () => ({ message: 'private state detail' }),
            }),
        }),
        { message: 'This document cannot be completed in its current state.' }
    )
})

test('archive request posts to the narrow endpoint with bearer auth', async () => {
    const calls = []
    const payload = {
        message: 'Document archived successfully.',
        document: { id: 42 },
    }
    const result = await archiveDocumentRequest({
        documentId: 42,
        token: 'safe-test-token',
        fetchImpl: async (...args) => {
            calls.push(args)
            return {
                ok: true,
                status: 200,
                json: async () => payload,
            }
        },
    })

    assert.deepEqual(result, payload)
    assert.equal(calls.length, 1)
    assert.equal(calls[0][0], '/api/documents/42/archive')
    assert.deepEqual(calls[0][1], {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            Authorization: 'Bearer safe-test-token',
        },
    })
})

test('archive request maps forbidden and conflict failures to fixed safe messages', async () => {
    await assert.rejects(
        archiveDocumentRequest({
            documentId: 8,
            token: 'token',
            fetchImpl: async () => ({
                ok: false,
                status: 403,
                json: async () => ({ message: 'private server detail' }),
            }),
        }),
        { message: 'You are not allowed to archive this document.' }
    )

    await assert.rejects(
        archiveDocumentRequest({
            documentId: 8,
            token: 'token',
            fetchImpl: async () => ({
                ok: false,
                status: 409,
                json: async () => ({ message: 'private state detail' }),
            }),
        }),
        { message: 'This document cannot be archived in its current state.' }
    )
})

test('complete failure message stays bounded for expected statuses', () => {
    assert.equal(
        completeDocumentFailureMessage(422),
        'Unable to complete document. Please check the document state and try again.'
    )
    assert.equal(completeDocumentFailureMessage(500), 'Unable to complete document.')
})

test('archive failure message stays bounded for expected statuses', () => {
    assert.equal(
        archiveDocumentFailureMessage(422),
        'Unable to archive document. Please check the document state and try again.'
    )
    assert.equal(archiveDocumentFailureMessage(500), 'Unable to archive document.')
})

test('document details component confirms completion and refreshes after success', async () => {
    const source = await readFile(
        new URL('../../resources/js/pages/DocumentDetails.vue', import.meta.url),
        'utf8'
    )
    const completeFunction = source.match(
        /const completeDocument = async \(\) => \{[\s\S]*?\n\}/
    )?.[0] || ''

    assert.match(source, /v-if="canComplete"/)
    assert.match(source, /Complete Document/)
    assert.match(completeFunction, /window\.confirm\(/)
    assert.match(completeFunction, /completeDocumentRequest\(/)
    assert.match(completeFunction, /await loadPage\(\)/)
    assert.match(source, /!isTerminalDocument\(document\.value\)/)
})

test('document details component confirms archive and refreshes after success', async () => {
    const source = await readFile(
        new URL('../../resources/js/pages/DocumentDetails.vue', import.meta.url),
        'utf8'
    )
    const archiveFunction = source.match(
        /const archiveDocument = async \(\) => \{[\s\S]*?\n\}/
    )?.[0] || ''

    assert.match(source, /v-if="canArchive"/)
    assert.match(source, /Archive Document/)
    assert.match(source, /canArchiveDocument\(/)
    assert.match(archiveFunction, /window\.confirm\(/)
    assert.match(archiveFunction, /archiveDocumentRequest\(/)
    assert.match(archiveFunction, /await loadPage\(\)/)
    assert.match(source, /!documentIsTerminal\.value/)
})
