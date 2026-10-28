import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'

test('document detail release modal loads and displays only suggested route offices', async () => {
    const source = await readFile(new URL('../../resources/js/pages/DocumentDetails.vue', import.meta.url), 'utf8')
    assert.match(source, /fetch\(`\/api\/document-types\/\$\{documentTypeId\}\/suggested-route`/)
    assert.match(source, /suggestedForwardOfficeIds\.value = data\.office_ids/)
    assert.match(source, /v-for="office in suggestedForwardOffices"/)
    assert.doesNotMatch(source, /v-for="office in routingOptions\?\.offices \|\| \[\]"/)
    assert.match(source, /Step \{\{ office\.routePosition \}\}: \{\{ office\.office_name \}\}/)
})
