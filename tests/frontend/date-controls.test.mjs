import assert from 'node:assert/strict'
import test from 'node:test'
import { readFile } from 'node:fs/promises'

const read = path => readFile(new URL(`../../${path}`, import.meta.url), 'utf8')

test('shared date controls use accessible light and dark styling without changing page inputs', async () => {
    const [styles, dashboard, documents] = await Promise.all([
        read('resources/css/app.css'),
        read('resources/js/pages/Dashboard.vue'),
        read('resources/js/pages/Documents.vue'),
    ])

    assert.match(styles, /input:is\(\[type='date'\], \[type='datetime-local'\], \[type='month'\]\)/)
    assert.match(styles, /font-size: calc\(1em \+ 0\.5px\)/)
    assert.match(styles, /::-webkit-calendar-picker-indicator/)
    assert.match(styles, /\.dark #app input:is\(\[type='date'\], \[type='datetime-local'\], \[type='month'\]\)/)
    assert.match(styles, /color-scheme: dark/)
    assert.match(styles, /\.dark :is\(\.text-red-500, \.text-red-600\)/)
    assert.match(dashboard, /type="month"/)
    assert.match(documents, /type="date"/)
})
