import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'

const pagePath = new URL('../../resources/js/pages/ChangeStatus.vue', import.meta.url)
const releasePagePath = new URL('../../resources/js/pages/ReleaseDocument.vue', import.meta.url)
const receivedPagePath = new URL('../../resources/js/pages/ReceivedDocument.vue', import.meta.url)
const scannerPath = new URL('../../resources/js/components/BulkStatusScanner.vue', import.meta.url)

test('bulk status page loads manual actions and saves a scanned document through the existing processing endpoint', async () => {
    const source = await readFile(pagePath, 'utf8')

    assert.match(source, /fetch\('\/api\/processing-actions'/)
    assert.match(source, /fetch\(`\/api\/documents\?search=\$\{encodeURIComponent\(qrToken\)\}&per_page=25`/)
    assert.match(source, /method: 'PUT'/)
    assert.match(source, /\/api\/documents\/\$\{document\.id\}\/processing/)
    assert.match(source, /current_action_id: Number\(actionId\.value\)/)
    assert.match(source, /scanner\.value\?\.focus\(\)/)
})

test('scanner emits a normalized QR token after Enter and refocuses for the next bulk scan', async () => {
    const source = await readFile(scannerPath, 'utf8')

    assert.match(source, /normalizeRegistrationQrInput\(value\.value\)/)
    assert.match(source, /@keydown\.enter\.prevent="submit"/)
    assert.match(source, /defineExpose\(\{ focus \}\)/)
})

test('bulk release page loads route-permitted destination offices and forwards each scanned document', async () => {
    const source = await readFile(releasePagePath, 'utf8')

    assert.match(source, /fetch\('\/api\/routing-offices'/)
    assert.match(source, /fetch\(`\/api\/documents\?search=\$\{encodeURIComponent\(qrToken\)\}&per_page=25`/)
    assert.match(source, /method: 'POST'/)
    assert.match(source, /\/api\/documents\/\$\{document\.id\}\/forward/)
    assert.match(source, /to_office_id: Number\(officeId\.value\)/)
    assert.match(source, /scanner\.value\?\.focus\(\)/)
})

test('bulk receive page can optionally save a current action after the existing guarded receipt', async () => {
    const source = await readFile(receivedPagePath, 'utf8')

    assert.match(source, /fetch\('\/api\/processing-actions'/)
    assert.match(source, /fetch\(`\/api\/documents\?search=\$\{encodeURIComponent\(qrToken\)\}&per_page=25`/)
    assert.match(source, /method: 'POST'/)
    assert.match(source, /\/api\/documents\/\$\{document\.id\}\/receive/)
    assert.match(source, /\/api\/documents\/\$\{document\.id\}\/processing/)
    assert.match(source, /current_action_id: Number\(actionId\.value\)/)
    assert.match(source, /scanner\.value\?\.focus\(\)/)
})
