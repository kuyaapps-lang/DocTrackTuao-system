import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'

const read = path => readFile(new URL(path, import.meta.url), 'utf8')

test('camera QR scanner requests a camera through the cross-browser decoder and stops it after a scan', async () => {
    const source = await read('../../resources/js/components/CameraQrScanner.vue')

    assert.match(source, /import \{ BrowserQRCodeReader \} from '@zxing\/browser'/)
    assert.match(source, /navigator\.mediaDevices\.getUserMedia\(constraints\)/)
    assert.match(source, /decodeFromVideoElement\(/)
    assert.match(source, /facingMode: \{ ideal: 'environment' \}/)
    assert.match(source, /controls\?\.stop\(\)/)
    assert.match(source, /stream\?\.getTracks\(\)\.forEach\(track => track\.stop\(\)\)/)
    assert.match(source, /emit\('scan', token\)/)
    assert.match(source, /Camera preview/)
    assert.match(source, /role="dialog"/)
})

test('QR entry screens expose the camera scanner beside their existing inputs', async () => {
    const [bulk, inquiry, registration] = await Promise.all([
        read('../../resources/js/components/BulkStatusScanner.vue'),
        read('../../resources/js/pages/DocumentTracking.vue'),
        read('../../resources/js/pages/Documents.vue'),
    ])

    assert.match(bulk, /<CameraQrScanner/)
    assert.match(inquiry, /<CameraQrScanner/)
    assert.match(registration, /<CameraQrScanner/)
})
