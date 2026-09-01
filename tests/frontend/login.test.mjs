import assert from 'node:assert/strict'
import test from 'node:test'

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
