import test from 'node:test'
import assert from 'node:assert/strict'
import { sanitizeRegistrationDescription, sanitizeRegistrationTitle } from '../../resources/js/lib/registration-text.js'

test('registration title keeps letters, numbers, spaces and hyphens only', () => {
    assert.equal(sanitizeRegistrationTitle('Memo-2026 A1!'), 'Memo-2026 A1')
    assert.equal(sanitizeRegistrationTitle('Budget / Report'), 'Budget  Report')
})

test('registration description keeps its allowed punctuation', () => {
    assert.equal(sanitizeRegistrationDescription('Memo, @Mayor #1 (draft) / 2026-A'), 'Memo, @Mayor #1 (draft) / 2026-A')
    assert.equal(sanitizeRegistrationDescription('Remove: $%^&*_+'), 'Remove ')
})
