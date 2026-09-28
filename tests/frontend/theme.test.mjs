import assert from 'node:assert/strict'
import test from 'node:test'
import { readFile } from 'node:fs/promises'
import {
    DARK_THEME,
    LIGHT_THEME,
    THEME_STORAGE_KEY,
    applyTheme,
    resolveTheme,
    savedTheme,
} from '../../resources/js/lib/theme.js'

const read = path => readFile(new URL(`../../${path}`, import.meta.url), 'utf8')

const storage = value => ({ getItem: key => key === THEME_STORAGE_KEY ? value : null })
const root = () => ({
    classList: {
        values: new Set(),
        toggle(name, enabled) { enabled ? this.values.add(name) : this.values.delete(name) },
    },
    dataset: {},
    style: {},
})

test('theme preference uses only valid saved light or dark values', () => {
    assert.equal(savedTheme(storage(DARK_THEME)), DARK_THEME)
    assert.equal(savedTheme(storage(LIGHT_THEME)), LIGHT_THEME)
    assert.equal(savedTheme(storage('system')), null)
})

test('theme falls back to device preference only without a saved choice', () => {
    const darkDevice = () => ({ matches: true })
    const lightDevice = () => ({ matches: false })

    assert.equal(resolveTheme({ storage: storage(null), matchMedia: darkDevice }), DARK_THEME)
    assert.equal(resolveTheme({ storage: storage(null), matchMedia: lightDevice }), LIGHT_THEME)
    assert.equal(resolveTheme({ storage: storage(LIGHT_THEME), matchMedia: darkDevice }), LIGHT_THEME)
})

test('theme application keeps the root class dataset and color scheme aligned', () => {
    const element = root()
    assert.equal(applyTheme(DARK_THEME, element), DARK_THEME)
    assert.equal(element.classList.values.has('dark'), true)
    assert.equal(element.dataset.theme, DARK_THEME)
    assert.equal(element.style.colorScheme, DARK_THEME)

    applyTheme(LIGHT_THEME, element)
    assert.equal(element.classList.values.has('dark'), false)
    assert.equal(element.dataset.theme, LIGHT_THEME)
})

test('theme toggle and early boot styling are wired through shared frontend files', async () => {
    const [shell, toggle, styles, boot] = await Promise.all([
        read('resources/js/layouts/AppShell.vue'),
        read('resources/js/components/ThemeToggle.vue'),
        read('resources/css/app.css'),
        read('resources/views/welcome.blade.php'),
    ])

    assert.match(shell, /<ThemeToggle/)
    assert.match(toggle, /Switch to dark theme/)
    assert.match(toggle, /toggleTheme\(\)/)
    assert.match(styles, /\.dark \{/)
    assert.match(styles, /\.dark \[data-slot="card"\]/)
    assert.match(styles, /\.dark \.doctrack-skeleton/)
    assert.match(styles, /\.dark \[data-slot="table-row"\]:hover/)
    assert.match(styles, /\.dark :is\(\.bg-green-100, \.bg-emerald-100\)/)
    assert.match(styles, /\.dark \.bg-red-100/)
    assert.match(styles, /outline: 3px solid #93c5fd/)
    assert.match(boot, /doctrack_theme/)
    assert.match(boot, /prefers-color-scheme: dark/)
})
