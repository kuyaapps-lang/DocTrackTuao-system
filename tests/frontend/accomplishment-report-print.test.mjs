import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'

const pagePath = new URL('../../resources/js/pages/AccomplishmentReport.vue', import.meta.url)

test('printed accomplishment reports move the full report header into an automatic repeating table header group', async () => {
    const source = await readFile(pagePath, 'utf8')

    assert.match(source, /const headerElements = \['\.letterhead', 'h1', '\.period', '\.office'\]/)
    assert.match(source, /printTable\.tHead\.prepend\(headerRow\)/)
    assert.match(source, /headerCell\.colSpan = includeOfficeColumn \? 6 : 5/)
    assert.match(source, /thead\{display:table-header-group!important\}/)
    assert.match(source, /popup\.document\.querySelector\('script'\)\?\.remove\(\)/)
    assert.match(source, /popup\.setTimeout\(\(\) => popup\.print\(\), 150\)/)
})
