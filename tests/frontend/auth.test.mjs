import assert from 'node:assert/strict'
import test from 'node:test'

import { resolveAuthenticationNavigation } from '../../resources/js/lib/auth-guard.js'

let importSequence = 0

const createStorage = (initial = {}) => {
    const values = new Map(Object.entries(initial))

    return {
        getItem: key => values.has(key) ? values.get(key) : null,
        removeItem: key => values.delete(key),
        setItem: (key, value) => values.set(key, String(value)),
    }
}

const response = (status, data) => ({
    ok: status >= 200 && status < 300,
    status,
    json: async () => data,
})

const loadFreshAuth = async () => {
    importSequence += 1
    return import(`../../resources/js/lib/auth.js?auth-test=${importSequence}`)
}

const protectedRoute = {
    path: '/documents',
    fullPath: '/documents',
    meta: {
        permission: 'documents.view',
    },
}

for (const unauthorizedResponse of [
    {
        name: 'valid JSON body',
        bodyDetail: 'raw-json-401-detail',
        json: async () => ({ message: 'raw-json-401-detail' }),
    },
    {
        name: 'empty body',
        bodyDetail: 'empty-body-parser-detail',
        json: async () => {
            throw new SyntaxError('empty-body-parser-detail')
        },
    },
    {
        name: 'malformed body',
        bodyDetail: 'malformed-body-parser-detail',
        json: async () => {
            throw new SyntaxError('malformed-body-parser-detail')
        },
    },
    {
        name: 'HTML proxy body',
        bodyDetail: '<html>proxy-generated-detail</html>',
        json: async () => {
            throw new SyntaxError('<html>proxy-generated-detail</html>')
        },
    },
]) {
    test(`401 with ${unauthorizedResponse.name} clears auth before parsing and redirects safely`, async () => {
        const originalStorage = globalThis.localStorage
        const originalFetch = globalThis.fetch
        const originalLog = console.log
        const originalError = console.error
        const logCalls = []
        let bodyParseCalls = 0

        try {
            globalThis.localStorage = createStorage({
                auth_token: 'local-test-token',
                auth_user: '{"stale":true}',
            })
            console.log = (...values) => logCalls.push(values)
            console.error = (...values) => logCalls.push(values)

            const auth = await loadFreshAuth()
            globalThis.fetch = async () => response(200, {
                id: 7,
                name: 'Authenticated User',
                permissions: ['documents.view'],
            })
            await auth.ensureCurrentUser(true)
            assert.equal(auth.useAuth().currentUser.value?.id, 7)

            globalThis.fetch = async () => ({
                ok: false,
                status: 401,
                json: async () => {
                    bodyParseCalls += 1
                    return unauthorizedResponse.json()
                },
            })

            await assert.rejects(
                auth.ensureCurrentUser(true),
                { message: 'Authentication is required.' }
            )
            assert.equal(bodyParseCalls, 0)
            assert.equal(localStorage.getItem('auth_token'), null)
            assert.equal(localStorage.getItem('auth_user'), null)
            assert.equal(auth.useAuth().currentUser.value, null)
            assert.equal(
                auth.useAuth().authError.value,
                'Authentication is required.'
            )
            assert.equal(
                auth.useAuth().authError.value.includes(
                    unauthorizedResponse.bodyDetail
                ),
                false
            )

            const redirect = await resolveAuthenticationNavigation(
                protectedRoute,
                auth
            )
            assert.deepEqual(redirect, { path: '/login' })

            const publicLogin = await resolveAuthenticationNavigation({
                path: '/login',
                fullPath: '/login',
                meta: { public: true },
            }, auth)
            assert.equal(publicLogin, true)
            assert.deepEqual(logCalls, [])
        } finally {
            globalThis.localStorage = originalStorage
            globalThis.fetch = originalFetch
            console.log = originalLog
            console.error = originalError
        }
    })
}

for (const failure of [
    {
        name: 'non-401 response',
        fetch: async () => response(503, {
            message: 'raw-temporary-response-detail',
        }),
    },
    {
        name: 'network failure',
        fetch: async () => {
            throw new Error('raw-network-exception-detail')
        },
    },
    {
        name: 'malformed non-401 response',
        fetch: async () => ({
            ok: false,
            status: 503,
            json: async () => {
                throw new SyntaxError('raw-non-401-parser-detail')
            },
        }),
    },
]) {
    test(`${failure.name} retains authentication and does not force a redirect`, async () => {
        const originalStorage = globalThis.localStorage
        const originalFetch = globalThis.fetch

        try {
            globalThis.localStorage = createStorage({
                auth_token: 'local-test-token',
                auth_user: '{"cached":true}',
            })

            const auth = await loadFreshAuth()
            globalThis.fetch = async () => response(200, {
                id: 8,
                name: 'Retained User',
                permissions: ['documents.view'],
            })
            await auth.ensureCurrentUser(true)

            globalThis.fetch = failure.fetch
            await assert.rejects(
                auth.ensureCurrentUser(true),
                {
                    message: 'Unable to verify authentication right now.',
                }
            )

            assert.equal(
                localStorage.getItem('auth_token'),
                'local-test-token'
            )
            assert.equal(
                localStorage.getItem('auth_user'),
                '{"cached":true}'
            )
            assert.equal(auth.useAuth().currentUser.value?.id, 8)
            assert.equal(
                auth.useAuth().authError.value,
                'Unable to verify authentication right now.'
            )

            const decision = await resolveAuthenticationNavigation(
                protectedRoute,
                auth
            )
            assert.equal(decision, true)
        } finally {
            globalThis.localStorage = originalStorage
            globalThis.fetch = originalFetch
        }
    })
}

test('guard preserves safe QR redirect and permission-denial behavior', async () => {
    const qrRedirect = await resolveAuthenticationNavigation({
        name: 'qr-document-registration',
        path: '/register-document/ABCDE-1234567',
        fullPath: '/register-document/ABCDE-1234567',
        meta: { permission: 'documents.create' },
    }, {
        getToken: () => null,
        ensureCurrentUser: async () => null,
        can: () => false,
    })
    assert.deepEqual(qrRedirect, {
        path: '/login',
        query: {
            redirect: '/register-document/ABCDE-1234567',
        },
    })

    const forbidden = await resolveAuthenticationNavigation(
        protectedRoute,
        {
            getToken: () => 'local-test-token',
            ensureCurrentUser: async () => ({ id: 9 }),
            can: () => false,
        }
    )
    assert.deepEqual(forbidden, {
        path: '/dashboard',
        query: { forbidden: '1' },
    })
})
