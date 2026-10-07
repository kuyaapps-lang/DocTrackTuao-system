import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'

test('incoming documents begins at 50 and appends ten records when its scroll sentinel is visible', async () => {
    const source = await readFile(
        new URL('../../resources/js/pages/Documents.vue', import.meta.url),
        'utf8'
    )

    assert.match(source, /perPage: parsedInitialQuery\.view === 'incoming' \? 50/)
    assert.match(source, /const loadMoreIncomingDocuments = async \(\) =>/)
    assert.match(source, /perPage: 10/)
    assert.match(source, /IntersectionObserver/)
    assert.match(source, /ref="incomingSentinel"/)
})
