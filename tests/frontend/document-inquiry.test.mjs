import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'

const readInquiry = () => readFile(
    new URL('../../resources/js/pages/DocumentTracking.vue', import.meta.url),
    'utf8'
)

test('document inquiry lists authorized documents and opens status in a modal', async () => {
    const source = await readInquiry()

    assert.match(source, /\/api\/documents\?per_page=50&sort=received_desc/)
    assert.match(source, /per_page=10&page=\$\{page\}&sort=received_desc/)
    assert.match(source, /IntersectionObserver/)
    assert.match(source, /Accessible Documents/)
    assert.match(source, /Documents created by, received by, or publicly visible to your office/)
    assert.match(source, /role="dialog"/)
    assert.match(source, /Tracking History/)
    assert.match(source, /closeInquiryDocument/)
    assert.doesNotMatch(source, /trackingNumber\.value = result\.qr_code \|\| trackingNo/)
})
