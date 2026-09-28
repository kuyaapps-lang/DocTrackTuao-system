import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'

import {
    normalizeRegistrationQrInput,
    publicQrUrl,
} from '../../resources/js/lib/qr-registration.js'

test('normalizes raw and legacy scanned QR values without accepting events', () => {
    assert.equal(normalizeRegistrationQrInput('ABCDE-2345678'), 'ABCDE-2345678')
    assert.equal(
        normalizeRegistrationQrInput('http://192.168.100.107:8000/q/246FC-Y9EHJR8'),
        '246FC-Y9EHJR8'
    )
    assert.equal(normalizeRegistrationQrInput({ type: 'click' }), '')
})

test('new QR labels use the Apache URL instead of a legacy Artisan port', () => {
    assert.equal(
        publicQrUrl('ABCDE-2345678', {
            href: 'http://192.168.100.107:8000/documents?old=1#old',
        }),
        'http://192.168.100.107/q/ABCDE-2345678'
    )
})

test('registration modal resets scanner input and verifies the normalized QR value', async () => {
    const source = await readFile(new URL('../../resources/js/pages/Documents.vue', import.meta.url), 'utf8')

    assert.match(source, /const openCreateForm = async \(\) => \{[\s\S]*?qrInput\.value = ''/)
    assert.match(source, /@click="openCreateForm\(\)"/)
    assert.doesNotMatch(source, /const openCreateForm = async \(initialToken/)
    assert.match(source, /const token = normalizeRegistrationQrInput\(qrInput\.value\)/)
    assert.match(source, /qrInput\.value = token/)
    assert.match(source, /!qrVerified\.value \|\| !qrToken\.value/)
    assert.doesNotMatch(source, /QR verified\. Complete the document information below to permanently link it\./)
    assert.doesNotMatch(source, /Scanned QR Token/)
    assert.doesNotMatch(source, /This QR is currently unused and will become registered after this form is saved successfully\./)
    assert.match(source, /<output aria-label="Verified QR code"[\s\S]*?\{\{ qrToken \}\}/)
    assert.match(source, /Document Date <span class="text-red-600">\*<\/span>/)
    assert.match(source, /md:w-auto md:grid-cols-2/)
    assert.match(source, /<DocTrackDatePicker\s+v-model="form\.document_date"\s+required/)
    assert.match(source, /<DocTrackDatePicker\s+v-model="form\.due_date"\s+clearable/)
    assert.match(source, /class="border-black bg-black text-white hover:bg-black\/90 hover:text-white"/)
    assert.match(source, /:disabled="qrVerifying" @click="closeCreateForm">Cancel<\/Button>/)
    assert.match(source, /class="border-black bg-black text-\[17px\] text-white hover:bg-black\/90 hover:text-white"/)
    assert.match(source, /class="h-12 px-5 text-\[18px\] leading-none bg-blue-900/)
})
