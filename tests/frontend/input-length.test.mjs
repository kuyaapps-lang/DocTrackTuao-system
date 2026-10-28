import test from 'node:test'
import assert from 'node:assert/strict'
import { inputMaxLength } from '../../resources/js/lib/input-length.js'

test('single-line inputs use a maximum of 20 while retaining stricter limits', () => {
    assert.equal(inputMaxLength('text'), 20)
    assert.equal(inputMaxLength('search', 150), 20)
    assert.equal(inputMaxLength('text', 10), 10)
})

test('credentials, QR exceptions and non-text controls keep their original limits', () => {
    assert.equal(inputMaxLength('password'), undefined)
    assert.equal(inputMaxLength('email'), undefined)
    assert.equal(inputMaxLength('text', undefined, true), undefined)
    assert.equal(inputMaxLength('search', 255, true), 255)
    assert.equal(inputMaxLength('date'), undefined)
})
