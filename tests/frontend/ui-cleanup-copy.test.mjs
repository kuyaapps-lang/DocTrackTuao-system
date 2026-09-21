import assert from 'node:assert/strict'
import test from 'node:test'
import { readFile } from 'node:fs/promises'

const readSource = async path => readFile(
    new URL(`../../${path}`, import.meta.url),
    'utf8'
)

test('defense UI cleanup removes visible mojibake from touched screens', async () => {
    const files = [
        'resources/js/pages/Login.vue',
        'resources/js/layouts/AppShell.vue',
        'resources/js/pages/DocumentDetails.vue',
        'resources/js/pages/Documents.vue',
    ]

    for (const file of files) {
        const source = await readSource(file)

        assert.doesNotMatch(source, /Â|â|Ã/)
    }
})

test('user management keeps one confirm-password visibility toggle per form', async () => {
    const source = await readSource('resources/js/pages/Users.vue')
    const toggles = source.match(/@click="showPasswordConfirmation = !showPasswordConfirmation"/g) || []

    assert.equal(toggles.length, 2)
})

test('document details keeps terminal action confirms with clearer warning copy', async () => {
    const source = await readSource('resources/js/pages/DocumentDetails.vue')

    assert.match(source, /window\.confirm\(/)
    assert.match(source, /Complete this document now\?\\n\\nAfter completion/)
    assert.match(source, /Archive this completed document now\?\\n\\nArchived documents/)
    assert.match(source, /Registered QR &middot; Linked to this document/)
    assert.match(source, /&times;/)
})

test('dashboard documents and public tracking show clearer helper copy', async () => {
    const dashboard = await readSource('resources/js/pages/Dashboard.vue')
    const documents = await readSource('resources/js/pages/Documents.vue')
    const tracking = await readSource('resources/js/pages/DocumentTracking.vue')

    assert.match(dashboard, /Documents by status/)
    assert.match(dashboard, /No routing activity was recorded in this period/)
    assert.match(documents, /Try a different keyword or clear the filter/)
    assert.match(documents, /Search by tracking number, title, type, or office/)
    assert.match(tracking, /Document Tracking/)
    assert.match(tracking, /Limited public details are shown for this protected document/)
})
