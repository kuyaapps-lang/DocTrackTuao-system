import test from 'node:test'
import assert from 'node:assert/strict'
import { filterDocumentTypes } from '../../resources/js/lib/document-type-search.js'

test('finds existing types by name, partial word and initials', () => {
    const types = [{ id: 1, type_name: 'Purchase Request' }, { id: 2, type_name: 'Travel Order' }]
    assert.deepEqual(filterDocumentTypes(types, 'chase').map(type => type.id), [1])
    assert.deepEqual(filterDocumentTypes(types, 'P.R.').map(type => type.id), [1])
    assert.deepEqual(filterDocumentTypes(types, 'unknown'), [])
    assert.deepEqual(filterDocumentTypes(types, ''), types)
})
