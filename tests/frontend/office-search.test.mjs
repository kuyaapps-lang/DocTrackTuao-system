import test from 'node:test'
import assert from 'node:assert/strict'
import { filterOffices } from '../../resources/js/lib/office-search.js'

test('office search matches partial names and codes without regard to case', () => {
    const offices = [{ id: 1, office_name: 'Office of the Mayor', office_code: 'MAY' }, { id: 2, office_name: 'Accounting Office', office_code: 'ACC' }]
    assert.deepEqual(filterOffices(offices, 'COUNT').map(office => office.id), [2])
    assert.deepEqual(filterOffices(offices, 'may').map(office => office.id), [1])
    assert.deepEqual(filterOffices(offices, 'missing'), [])
    assert.equal(filterOffices(offices, '').length, 2)
})
