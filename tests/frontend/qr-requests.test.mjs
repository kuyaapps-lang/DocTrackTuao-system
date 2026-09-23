import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'

import {
    createQrRequestPayload,
    fetchQrCodeRequests,
    isQrCodeRequestListResponse,
    qrRequestFailureMessage,
    reviewQrCodeRequest,
    submitQrCodeRequest,
} from '../../resources/js/lib/qrRequests.js'

const request = Object.freeze({
    id: 9,
    quantity: 2,
    purpose: 'Front desk labels.',
    status: 'approved',
    requested_by: { id: 3, name: 'Records Officer' },
    requested_office: { id: 4, office_name: 'Accounting' },
    reviewed_by: { id: 1, name: 'Administrator' },
    reviewed_at: '2026-09-22T10:00:00+00:00',
    review_note: 'Approved.',
    qr_codes: Object.freeze([
        {
            id: 21,
            qr_token: 'ABCDE-2345678',
            status: 'unused',
            linked: false,
            scan_path: '/q/ABCDE-2345678',
        },
    ]),
    created_at: '2026-09-22T09:00:00+00:00',
    updated_at: '2026-09-22T10:00:00+00:00',
})

const jsonResponse = (status, body = {}) => ({
    ok: status >= 200 && status < 300,
    status,
    json: async () => body,
})

test('validates QR request list response and assigned QR rows', () => {
    const payload = { data: [structuredClone(request)] }
    assert.equal(isQrCodeRequestListResponse(payload), true)

    for (const change of [
        { id: 0 },
        { quantity: 0 },
        { status: 'deleted' },
        { requested_office: { id: 4 } },
        { qr_codes: [{ ...request.qr_codes[0], qr_token: 123 }] },
    ]) {
        const invalid = { data: [structuredClone({ ...request, ...change })] }
        assert.equal(isQrCodeRequestListResponse(invalid), false)
    }
})

test('QR request helpers call narrow endpoints with safe payloads', async () => {
    const calls = []
    const fetchImpl = async (url, options = {}) => {
        calls.push([url, options])
        if (url === '/api/qr-code-requests') {
            if (options.method === 'POST') {
                assert.deepEqual(JSON.parse(options.body), {
                    quantity: 3,
                    purpose: 'Office batch',
                })
                return jsonResponse(201, { request: structuredClone(request) })
            }
            return jsonResponse(200, { data: [structuredClone(request)] })
        }
        if (url === '/api/qr-code-requests/9/approve') {
            assert.deepEqual(JSON.parse(options.body), { review_note: '' })
            return jsonResponse(200, { request: structuredClone(request) })
        }
        if (url === '/api/qr-code-requests/9/reject') {
            assert.deepEqual(JSON.parse(options.body), { review_note: 'Not needed' })
            return jsonResponse(200, { request: structuredClone({ ...request, status: 'rejected' }) })
        }
        return jsonResponse(404)
    }

    const getToken = () => 'test-token'

    assert.deepEqual(createQrRequestPayload({
        quantity: 3,
        purpose: '  Office batch  ',
    }), {
        quantity: 3,
        purpose: 'Office batch',
    })

    assert.equal((await fetchQrCodeRequests({ fetchImpl, getToken }))[0].id, 9)
    assert.equal((await submitQrCodeRequest({
        fetchImpl,
        getToken,
        form: { quantity: 3, purpose: 'Office batch' },
    })).request.id, 9)
    assert.equal((await reviewQrCodeRequest({
        fetchImpl,
        getToken,
        requestId: 9,
        action: 'approve',
    })).request.status, 'approved')
    assert.equal((await reviewQrCodeRequest({
        fetchImpl,
        getToken,
        requestId: 9,
        action: 'reject',
        reviewNote: 'Not needed',
    })).request.status, 'rejected')

    assert.equal(calls.every(([, options]) => {
        return options.headers.Authorization === 'Bearer test-token'
    }), true)
})

test('QR request helpers use fixed safe failure messages', async () => {
    assert.equal(qrRequestFailureMessage(401), 'Authentication is required.')
    assert.equal(qrRequestFailureMessage(403), 'You are not authorized to manage QR requests.')
    assert.equal(qrRequestFailureMessage(409), 'This QR request has already been reviewed.')
    assert.equal(qrRequestFailureMessage(422), 'The QR request is invalid.')
    assert.equal(qrRequestFailureMessage(500), 'Unable to load QR requests. Please try again.')

    await assert.rejects(
        () => fetchQrCodeRequests({
            fetchImpl: async () => jsonResponse(403, { message: 'unsafe detail' }),
            getToken: () => 'test-token',
        }),
        { message: 'You are not authorized to manage QR requests.' }
    )

    await assert.rejects(
        () => reviewQrCodeRequest({
            fetchImpl: async () => jsonResponse(200, { request: { ...request, qr_codes: 'bad' } }),
            getToken: () => 'test-token',
            requestId: 9,
            action: 'approve',
        }),
        { message: 'Unable to load QR requests. Please try again.' }
    )
})

test('QR page exposes request workflow and keeps destructive controls admin gated', async () => {
    const source = await readFile(new URL('../../resources/js/pages/QrCodes.vue', import.meta.url), 'utf8')
    const router = await readFile(new URL('../../resources/js/router/index.js', import.meta.url), 'utf8')
    const navigation = await readFile(new URL('../../resources/js/lib/navigation.js', import.meta.url), 'utf8')

    assert.match(source, /Submit Request/)
    assert.match(source, /QR Requests/)
    assert.match(source, /Assigned QR Codes/)
    assert.match(source, /<Card v-if="canRequestQr" class="overflow-hidden">[\s\S]*Submit Request/)
    assert.match(source, /<Card v-if="canRequestQr" class="mt-6 overflow-hidden">[\s\S]*QR Requests/)
    assert.match(source, /<CardHeader class="bg-blue-900 px-4 py-2 text-white">/)
    assert.match(source, /<CardTitle class="text-sm font-semibold">/)
    assert.match(source, /const canIssueQr = computed\(\(\) => permissions\.value\.includes\('qr\.issue'\)\)/)
    assert.match(source, /canApproveQr && request\.status === 'pending'/)
    assert.match(source, /reviewRequest\(request, 'approve'\)/)
    assert.match(source, /reviewRequest\(request, 'reject'\)/)
    assert.match(source, /v-if="canVoidInventoryItem\(item, canVoidQr\)"/)
    assert.match(source, /<Card v-if="canIssueQr" class="mt-6 overflow-hidden">[\s\S]*Direct QR Issuance/)
    assert.doesNotMatch(source, /<Card v-if="canManageQr" class="mt-6">[\s\S]*Direct QR Issuance/)
    assert.match(source, /<Card v-if="canManageQr" class="mt-6 overflow-hidden">[\s\S]*Persisted QR Inventory/)
    assert.match(source, /<thead class="bg-blue-900 text-white">/)
    assert.doesNotMatch(source, /reviewRequest\(request, 'delete'\)/)
    assert.match(router, /permission:\s*'qr\.request'/)
    assert.match(navigation, /permission:\s*'qr\.request'/)
})
