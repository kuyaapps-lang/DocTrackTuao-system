import assert from 'node:assert/strict'
import test from 'node:test'
import { readFile } from 'node:fs/promises'

const readDetails = () => readFile(
    new URL('../../resources/js/pages/DocumentDetails.vue', import.meta.url),
    'utf8',
)

test('document metadata uses paired, safe phone columns without changing md and lg grids', async () => {
    const source = await readDetails()

    assert.match(
        source,
        /grid-cols-2 gap-x-4 gap-y-5 max-\[359px\]:grid-cols-1 md:grid-cols-2 md:gap-5 lg:grid-cols-3/,
    )
    assert.match(
        source,
        /col-span-2 min-w-0 max-\[359px\]:col-span-1 md:col-span-1/,
    )

    const metadata = source.slice(source.indexOf('grid-cols-2 gap-x-4'))
    const labels = [
        'Document Type',
        'Status',
        'Priority',
        'Confidentiality',
        'Origin Office',
        'Current Office',
        'Document Date',
        'Due Date',
        'Registered By',
    ]
    const positions = labels.map(label => metadata.indexOf(label))

    assert.ok(positions.every(position => position >= 0))
    assert.deepEqual([...positions].sort((left, right) => left - right), positions)
    assert.match(source, /Origin Office[\s\S]*?class="mt-1 break-words font-medium text-gray-900"/)
    assert.match(source, /Current Office[\s\S]*?class="mt-1 break-words font-semibold text-blue-700"/)
})
