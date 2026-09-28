import assert from 'node:assert/strict'
import test from 'node:test'
import { readFile } from 'node:fs/promises'
import {
    buildCalendarDays,
    formatDateDisplay,
    formatDateValue,
    formatMonthValue,
    parseDateValue,
} from '../../resources/js/lib/date-picker.js'

const read = path => readFile(new URL(`../../${path}`, import.meta.url), 'utf8')

test('date picker keeps ISO API values while displaying a local MM/DD/YYYY value', () => {
    const selected = new Date(2026, 8, 28)

    assert.equal(formatDateValue(selected), '2026-09-28')
    assert.equal(formatDateDisplay('2026-09-28'), '09/28/2026')
    assert.equal(parseDateValue('2026-02-29'), null)
    assert.equal(formatMonthValue(selected), '2026-09')
})

test('date picker calendar has complete week rows including adjacent-month dates', () => {
    const days = buildCalendarDays(new Date(2026, 8, 1))

    assert.equal(days.length, 42)
    assert.equal(days[0].getDay(), 0)
    assert.equal(days.at(-1).getDay(), 6)
})

test('reusable picker supports selection, today, optional clearing, keyboard escape, dark styling, and safe upward placement', async () => {
    const picker = await read('resources/js/components/DocTrackDatePicker.vue')
    const styles = await read('resources/css/app.css')

    assert.match(picker, /emit\('update:modelValue', formatDateValue\(date\)\)/)
    assert.match(picker, /chooseToday/)
    assert.match(picker, /v-if="clearable"/)
    assert.match(picker, /event\.key !== 'Escape'/)
    assert.match(picker, /document\.addEventListener\('pointerdown'/)
    assert.match(picker, /document\.removeEventListener\('keydown'/)
    assert.match(picker, /placement: \{[\s\S]*?'prefer-above'/)
    assert.match(picker, /spaceAbove >= popoverBounds\.height \+ margin/)
    assert.match(picker, /closest\('\.doctrack-date-picker-boundary'\)/)
    assert.match(picker, /document\.addEventListener\('scroll', resolvePlacement, true\)/)
    assert.match(styles, /\.dark \.doctrack-date-picker-popover/)
    assert.match(styles, /\.doctrack-date-picker-popover\.is-above/)
    assert.match(styles, /prefers-reduced-motion: reduce/)
})

test('Document Date remains required, Due Date is optional and clearable, and Dashboard uses month mode', async () => {
    const documents = await read('resources/js/pages/Documents.vue')
    const dashboard = await read('resources/js/pages/Dashboard.vue')

    assert.match(documents, /<DocTrackDatePicker\s+v-model="form\.document_date"\s+required\s+placement="prefer-above"/)
    assert.match(documents, /<DocTrackDatePicker\s+v-model="form\.due_date"\s+clearable\s+placement="prefer-above"/)
    assert.match(documents, /class="doctrack-date-picker-boundary w-full max-w-3xl max-h-\[90vh\]/)
    assert.match(documents, /document_date: formatDateValue\(new Date\(\)\)/)
    assert.match(dashboard, /<DocTrackDatePicker v-model="selectedMonth" mode="month"/)
})
