import test from 'node:test'
import assert from 'node:assert/strict'
import { firstSuggestedDestination, suggestedDestinations } from '../../resources/js/lib/suggested-destination.js'
import { isValidRegistrationQrToken } from '../../resources/js/lib/qr-registration.js'

test('only the first suggested office is preselected when it is available', () => {
    const offices = [{ id: 2, office_name: 'Accounting' }, { id: 3, office_name: 'Budget' }]
    assert.equal(firstSuggestedDestination([2, 3], offices).id, 2)
    assert.equal(firstSuggestedDestination([1, 2], offices), null)
    assert.equal(firstSuggestedDestination([], offices), null)
})

test('available route offices retain route order and original step numbers', () => {
    const offices = [{ id: 2 }, { id: 3 }, { id: 4 }]
    assert.deepEqual(suggestedDestinations([1, 3, 2, 3], offices), [
        { id: 3, routePosition: 2 },
        { id: 2, routePosition: 3 },
    ])
    assert.deepEqual(suggestedDestinations([], offices), [])
})

test('release lookup requires a valid QR token rather than a general search', () => {
    assert.equal(isValidRegistrationQrToken('ABCDE-2345678'), true)
    assert.equal(isValidRegistrationQrToken('document title'), false)
})
