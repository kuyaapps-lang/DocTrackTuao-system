import assert from 'node:assert/strict'
import test from 'node:test'
import { readFile } from 'node:fs/promises'
import { runInNewContext } from 'node:vm'
import { computed, ref } from 'vue'

import { loginErrorMessage } from '../../resources/js/lib/login.js'

test('login throttling always uses a generic retry-later message', () => {
    assert.equal(
        loginErrorMessage(429, 'account-specific backend detail'),
        'Too many login attempts. Please try again later.'
    )
})

test('other login failures retain the existing safe fallback behavior', () => {
    assert.equal(loginErrorMessage(401, 'Invalid credentials'), 'Invalid credentials')
    assert.equal(loginErrorMessage(500, ''), 'Login failed.')
})

// Execute the actual SFC setup handlers; only imports and UI lifecycle wiring are supplied.
const loadSetup = async (path, bindings, exposed) => {
    const source = await readFile(new URL(path, import.meta.url), 'utf8')
    const setup = source.match(/<script setup>([\s\S]*?)<\/script>/)[1]
        .replace(/^import\s[\s\S]*?from\s+['"][^'"]+['"];?\r?$/gm, '')
    return runInNewContext(`${setup}\n;({ ${exposed.join(', ')} })`, {
        ref, computed, loginErrorMessage,
        onMounted: () => {}, onBeforeUnmount: () => {}, watch: () => {},
        ...bindings,
    })
}

const createStorage = () => {
    const values = new Map([['auth_token', 'old-test-token'], ['auth_user', '{"legacy":true}']])
    const writes = []
    return {
        values, writes,
        getItem: key => {
            assert.notEqual(key, 'auth_user', 'Production must not consume the legacy profile')
            return values.get(key) ?? null
        },
        setItem: (key, value) => {
            writes.push(key)
            values.set(key, String(value))
        },
        removeItem: key => values.delete(key),
    }
}

for (const [redirect, destination] of [
    [undefined, '/dashboard'],
    ['/register-document/ABCDE-1234567', '/register-document/ABCDE-1234567'],
    ['//untrusted.example/path', '/dashboard'],
    ['https://untrusted.example/path', '/dashboard'],
]) {
    test(`production login stores only token and preserves redirect ${String(redirect)}`, async () => {
        const storage = createStorage()
        const destinations = []
        const page = await loadSetup('../../resources/js/pages/Login.vue', {
            localStorage: storage,
            useRoute: () => ({ query: { redirect } }),
            useRouter: () => ({ replace: path => destinations.push(path) }),
            fetch: async () => ({
                ok: true, status: 200,
                json: async () => ({ token: 'new-test-token', user: { id: 42, name: 'Private Profile' } }),
            }),
        }, ['login', 'email', 'password', 'error', 'loading'])
        page.email.value = 'login@example.test'
        page.password.value = 'test-only-password'
        await page.login()
        assert.deepEqual(storage.writes, ['auth_token'])
        assert.equal(storage.values.get('auth_token'), 'new-test-token')
        assert.equal(storage.values.has('auth_user'), false)
        assert.deepEqual(destinations, [destination])
        assert.equal(page.error.value, '')
        assert.equal(page.loading.value, false)
    })
}

const logoutCases = [
    { name: 'successful logout', status: 200, clears: true },
    ...['JSON', 'empty', 'malformed', 'HTML'].map(body => ({
        name: `401 ${body}`, status: 401, clears: true,
    })),
    ...[403, 429, 500, 503].map(status => ({ name: String(status), status })),
    { name: 'malformed non-401', status: 503 },
    { name: 'network', throws: new Error('private network detail') },
    { name: 'abort', throws: new DOMException('private abort detail', 'AbortError') },
]

let authImport = 0
for (const scenario of logoutCases) {
    test(`production logout ${scenario.name} ${scenario.clears ? 'clears' : 'retains'} authentication`, async () => {
        const storageDescriptor = Object.getOwnPropertyDescriptor(globalThis, 'localStorage')
        const fetchDescriptor = Object.getOwnPropertyDescriptor(globalThis, 'fetch')
        try {
            const storage = createStorage()
            globalThis.localStorage = storage
            globalThis.fetch = async () => ({ ok: true, status: 200, json: async () => ({ id: 42 }) })
            const auth = await import(`../../resources/js/lib/auth.js?logout-test=${++authImport}`)
            await auth.ensureCurrentUser()
            storage.values.set('auth_user', '{"legacy":true}')
            const retained = auth.useAuth().currentUser.value
            const destinations = []
            let parsed = 0
            const shell = await loadSetup('../../resources/js/layouts/AppShell.vue', {
                localStorage: storage,
                useAuth: auth.useAuth,
                useRoute: () => ({ meta: {}, fullPath: '/documents' }),
                useRouter: () => ({ replace: path => destinations.push(path) }),
                fetch: async () => {
                    if (scenario.throws) throw scenario.throws
                    return {
                        status: scenario.status,
                        json: async () => { parsed++; throw new SyntaxError('private parser detail') },
                    }
                },
            }, ['logout', 'logoutError', 'logoutPending'])
            await shell.logout()
            assert.equal(parsed, 0)
            assert.equal(shell.logoutPending.value, false)
            if (scenario.clears) {
                assert.equal(storage.values.size, 0)
                assert.equal(auth.useAuth().currentUser.value, null)
                assert.equal(auth.useAuth().authError.value, '')
                assert.deepEqual(destinations, ['/login'])
                assert.equal(shell.logoutError.value, '')
            } else {
                assert.equal(storage.values.get('auth_token'), 'old-test-token')
                assert.equal(storage.values.get('auth_user'), '{"legacy":true}')
                assert.equal(auth.useAuth().currentUser.value, retained)
                assert.deepEqual(destinations, [])
                assert.equal(shell.logoutError.value, 'Unable to logout right now. Please try again.')
            }
        } finally {
            if (storageDescriptor) Object.defineProperty(globalThis, 'localStorage', storageDescriptor)
            else delete globalThis.localStorage
            if (fetchDescriptor) Object.defineProperty(globalThis, 'fetch', fetchDescriptor)
            else delete globalThis.fetch
        }
    })
}
