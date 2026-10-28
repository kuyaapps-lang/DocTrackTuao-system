import test from 'node:test'
import assert from 'node:assert/strict'
import { filterActions } from '../../resources/js/lib/action-search.js'

test('action search matches a partial Current Action without regard to case', () => {
    const actions = [{ id: 1, action_name: 'For Review' }, { id: 2, action_name: 'For Signature' }]

    assert.deepEqual(filterActions(actions, 'sign').map(action => action.id), [2])
    assert.deepEqual(filterActions(actions, 'REVIEW').map(action => action.id), [1])
    assert.deepEqual(filterActions(actions, 'missing'), [])
    assert.equal(filterActions(actions, '').length, 2)
})
