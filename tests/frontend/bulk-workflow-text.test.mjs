import test from 'node:test'
import assert from 'node:assert/strict'
import { sanitizeBulkWorkflowText } from '../../resources/js/lib/bulk-workflow-text.js'

test('bulk workflow text allows only letters, numbers, spaces, hyphens, and parentheses up to 30 characters', () => {
    assert.equal(sanitizeBulkWorkflowText('For Review (Phase-2) #1!'), 'For Review (Phase-2) 1')
    assert.equal(sanitizeBulkWorkflowText('123456789012345678901234567890XYZ'), '123456789012345678901234567890')
})
