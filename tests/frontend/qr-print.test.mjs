import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import {
    QR_PRINT_MESSAGES,
    QR_PRINT_READY_TIMEOUT_MS,
    QR_PRINT_STYLES,
    QR_PRINT_TITLE,
    printQrLabels,
    qrPrintFailureMessage,
} from '../../resources/js/lib/qrPrint.js'

const imageSource = 'data:image/png;base64,QUJD'
const shortIdentifier = 'ABCDE-2345678'
const longIdentifier = '123e4567-e89b-42d3-a456-426614174000'

const deferred = () => {
    let resolve
    let reject
    const promise = new Promise((yes, no) => {
        resolve = yes
        reject = no
    })
    return { promise, resolve, reject }
}

class FakeElement {
    constructor(tagName, documentRef) {
        this.tagName = tagName.toUpperCase()
        this.ownerDocument = documentRef
        this.children = []
        this.listeners = new Map()
        this.attributes = new Map()
        this.className = ''
        this.textContent = ''
        this.alt = ''
        this.complete = false
        this.naturalWidth = 0
        this._src = ''
    }

    appendChild(child) {
        this.children.push(child)
        return child
    }

    replaceChildren(...children) {
        this.children = [...children]
    }

    setAttribute(name, value) {
        this.attributes.set(name, String(value))
    }

    addEventListener(type, listener) {
        const listeners = this.listeners.get(type) || new Set()
        listeners.add(listener)
        this.listeners.set(type, listeners)
    }

    removeEventListener(type, listener) {
        this.listeners.get(type)?.delete(listener)
    }

    dispatch(type) {
        for (const listener of [...(this.listeners.get(type) || [])]) {
            listener({ type, target: this })
        }
    }

    set src(value) {
        this._src = value
        this.ownerDocument.onImageSource?.(this, value)
    }

    get src() {
        return this._src
    }
}

class FakeDocument {
    constructor({ readyState = 'complete', onImageSource } = {}) {
        this.readyState = readyState
        this.onImageSource = onImageSource
        this.title = ''
        this.head = new FakeElement('head', this)
        this.body = new FakeElement('body', this)
        this.created = []
    }

    createElement(tagName) {
        const element = new FakeElement(tagName, this)
        this.created.push(element)
        return element
    }
}

class FakePopup {
    constructor(documentRef) {
        this.document = documentRef
        this.closed = false
        this.listeners = new Map()
        this.focusCalls = 0
        this.printCalls = 0
    }

    addEventListener(type, listener) {
        const listeners = this.listeners.get(type) || new Set()
        listeners.add(listener)
        this.listeners.set(type, listeners)
    }

    removeEventListener(type, listener) {
        this.listeners.get(type)?.delete(listener)
    }

    dispatch(type) {
        for (const listener of [...(this.listeners.get(type) || [])]) {
            listener({ type, target: this })
        }
    }

    focus() {
        this.focusCalls += 1
    }

    print() {
        this.printCalls += 1
    }
}

const fakeTimers = () => {
    const active = new Map()
    let nextId = 1
    return {
        active,
        setInterval(callback) {
            const id = nextId++
            active.set(id, { callback, type: 'interval' })
            return id
        },
        clearInterval(id) {
            active.delete(id)
        },
        setTimeout(callback, delay) {
            const id = nextId++
            active.set(id, { callback, delay, type: 'timeout' })
            return id
        },
        clearTimeout(id) {
            active.delete(id)
        },
        tick() {
            for (const entry of [...active.values()]) {
                if (entry.type === 'interval') entry.callback()
            }
        },
        timeout() {
            for (const [id, entry] of [...active.entries()]) {
                if (entry.type !== 'timeout') continue
                active.delete(id)
                entry.callback()
            }
        },
    }
}

const environment = ({ readyState = 'complete', imageMode = 'load' } = {}) => {
    const documentRef = new FakeDocument({
        readyState,
        onImageSource(image) {
            if (imageMode === 'load') {
                image.complete = true
                image.naturalWidth = 128
                image.dispatch('load')
            } else if (imageMode === 'cached') {
                image.complete = true
                image.naturalWidth = 128
            } else if (imageMode === 'error') {
                image.complete = true
                image.naturalWidth = 0
                image.dispatch('error')
            }
        },
    })
    const popup = new FakePopup(documentRef)
    popup.closeCalls = 0
    popup.close = function () {
        this.closeCalls += 1
        this.closed = true
    }
    const timers = fakeTimers()
    const windowRef = {
        openCalls: [],
        open(...args) {
            this.openCalls.push(args)
            return popup
        },
    }
    return { documentRef, popup, timers, windowRef }
}

const byClass = (root, className) => {
    const matches = []
    const visit = element => {
        if (element.className?.split(' ').includes(className)) matches.push(element)
        for (const child of element.children || []) visit(child)
    }
    visit(root)
    return matches
}

test('builds bounded one-inch original and record-copy pairs safely', async () => {
    const env = environment()
    const items = [
        { identifier: shortIdentifier, imageSource },
        { identifier: longIdentifier, imageSource },
    ]
    const before = structuredClone(items)

    await printQrLabels({
        windowRef: env.windowRef,
        items,
        timerApi: env.timers,
    })

    assert.deepEqual(items, before)
    assert.equal(env.documentRef.title, QR_PRINT_TITLE)
    assert.equal(env.documentRef.title.includes(shortIdentifier), false)
    assert.equal(env.windowRef.openCalls.length, 1)
    assert.equal(byClass(env.documentRef.body, 'qr-pair').length, 2)
    assert.equal(byClass(env.documentRef.body, 'label').length, 4)
    assert.deepEqual(
        byClass(env.documentRef.body, 'copy-name').map(node => node.textContent),
        ['ORIGINAL', 'RECORD COPY', 'ORIGINAL', 'RECORD COPY']
    )
    assert.deepEqual(
        byClass(env.documentRef.body, 'identifier').map(
            node => node.children.map(line => line.textContent).join('')
        ),
        [shortIdentifier, shortIdentifier, longIdentifier, longIdentifier]
    )
    assert.deepEqual(
        byClass(env.documentRef.body, 'identifier-long').map(
            node => node.children.map(line => line.textContent)
        ),
        [
            [longIdentifier.slice(0, 18), longIdentifier.slice(18)],
            [longIdentifier.slice(0, 18), longIdentifier.slice(18)],
        ]
    )
    assert.equal(env.documentRef.created.some(node => node.tagName === 'SCRIPT'), false)
    assert.equal(env.documentRef.created.some(node => node.tagName === 'IMG' && node.attributes.size > 0), false)
    assert.equal(env.popup.focusCalls, 1)
    assert.equal(env.popup.printCalls, 1)
    assert.equal(env.timers.active.size, 0)
})

test('defines exact physical dimensions, quiet sizing, bounded preview, and pair breaks', () => {
    assert.match(QR_PRINT_STYLES, /@page\s*{\s*margin:\s*0\.30in;/)
    assert.match(QR_PRINT_STYLES, /\.label\s*{[^}]*width:\s*1in;[^}]*height:\s*1in;/s)
    assert.match(QR_PRINT_STYLES, /\.label img\s*{[^}]*width:\s*0\.72in;[^}]*height:\s*0\.72in;/s)
    assert.match(QR_PRINT_STYLES, /\.qr-pair\s*{[^}]*break-inside:\s*avoid;[^}]*page-break-inside:\s*avoid;/s)
    assert.match(QR_PRINT_STYLES, /\.screen-note\s*{[^}]*max-width:\s*42rem;/s)
    assert.match(QR_PRINT_STYLES, /\.sheet\s*{[^}]*max-width:\s*8in;/s)
    assert.doesNotMatch(QR_PRINT_STYLES, /280px|420px/)
    const identifierStyles = QR_PRINT_STYLES.match(/\.identifier\s*{[^}]*}/s)?.[0] || ''
    assert.match(identifierStyles, /overflow-wrap:\s*anywhere;/)
    assert.match(identifierStyles, /word-break:\s*break-all;/)
    assert.doesNotMatch(identifierStyles, /overflow:\s*hidden|text-overflow|nowrap/)
})

test('opens synchronously and reports a blocked popup with a fixed message', () => {
    const windowRef = { open: () => null }
    assert.throws(
        () => printQrLabels({
            windowRef,
            items: [{ identifier: shortIdentifier, imageSource }],
        }),
        new RegExp(QR_PRINT_MESSAGES.blocked.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
    )
})

test('maps unexpected browser failures to a fixed safe message', () => {
    assert.equal(
        qrPrintFailureMessage(new Error('private browser detail')),
        QR_PRINT_MESSAGES.print
    )
    assert.equal(
        qrPrintFailureMessage(new Error(QR_PRINT_MESSAGES.blocked)),
        QR_PRINT_MESSAGES.blocked
    )
})

test('rejects unsupported identifiers before constructing a popup', () => {
    for (const identifier of [
        '<script>alert(1)</script>',
        'ABCDE_2345678',
        'ABCDE-234567O',
        `${shortIdentifier}X`,
        null,
    ]) {
        const env = environment()
        const items = [{ identifier, imageSource }]
        const before = structuredClone(items)

        assert.throws(
            () => printQrLabels({
                windowRef: env.windowRef,
                items,
                timerApi: env.timers,
            }),
            { message: QR_PRINT_MESSAGES.identifier }
        )
        assert.equal(env.windowRef.openCalls.length, 0)
        assert.deepEqual(items, before)
    }
})

test('rejects hostile and unsupported image sources without assigning them', async () => {
    for (const source of [
        'javascript:alert(1)',
        'https://hostile.invalid/qr.png',
        'data:image/svg+xml,<svg onload=alert(1)>',
        'data:text/html,<script>alert(1)</script>',
        'data:image/png;base64,%%%INVALID%%%',
        'data:image/png;base64,QUJD\n',
    ]) {
        const env = environment({ imageMode: 'manual' })
        await assert.rejects(
            printQrLabels({
                windowRef: env.windowRef,
                items: [{ identifier: shortIdentifier, imageSource: source }],
                timerApi: env.timers,
            }),
            { message: QR_PRINT_MESSAGES.image }
        )
        assert.equal(
            env.documentRef.created
                .filter(node => node.tagName === 'IMG')
                .some(image => image.src !== ''),
            false
        )
        assert.equal(env.timers.active.size, 0)
    }
})

test('handles cached images and duplicate events without duplicate printing', async () => {
    const env = environment({ imageMode: 'cached' })
    await printQrLabels({
        windowRef: env.windowRef,
        items: [{ identifier: shortIdentifier, imageSource }],
        timerApi: env.timers,
    })
    for (const image of env.documentRef.created.filter(node => node.tagName === 'IMG')) {
        image.dispatch('load')
        image.dispatch('error')
    }
    env.popup.dispatch('load')
    assert.equal(env.popup.focusCalls, 1)
    assert.equal(env.popup.printCalls, 1)
    assert.equal(env.popup.listeners.get('load')?.size || 0, 0)
    assert.equal(env.timers.active.size, 0)
})

test('waits for popup readiness and every batch image while resolving each item once', async () => {
    const env = environment({ readyState: 'loading', imageMode: 'manual' })
    const first = deferred()
    const second = deferred()
    let sourceCalls = 0
    const job = printQrLabels({
        windowRef: env.windowRef,
        items: [
            { identifier: shortIdentifier, source: first },
            { identifier: longIdentifier, source: second },
        ],
        getImageSource: item => {
            sourceCalls += 1
            return item.source.promise
        },
        timerApi: env.timers,
    })

    await Promise.resolve()
    assert.equal(sourceCalls, 2)
    first.resolve(imageSource)
    second.resolve(imageSource)
    await Promise.resolve()
    await Promise.resolve()

    const images = env.documentRef.created.filter(node => node.tagName === 'IMG')
    assert.equal(images.length, 4)
    images.slice(0, 3).forEach(image => image.dispatch('load'))
    env.popup.dispatch('load')
    assert.equal(env.popup.printCalls, 0)
    images[3].dispatch('load')
    await job
    images[3].dispatch('load')
    env.popup.dispatch('load')
    assert.equal(env.popup.focusCalls, 1)
    assert.equal(env.popup.printCalls, 1)
})

test('reports image failure safely and cleans listeners and timers', async () => {
    const env = environment({ imageMode: 'error' })
    await assert.rejects(
        printQrLabels({
            windowRef: env.windowRef,
            items: [{ identifier: shortIdentifier, imageSource }],
            timerApi: env.timers,
        }),
        error => error.message === QR_PRINT_MESSAGES.image &&
            !error.message.includes(shortIdentifier)
    )
    assert.equal(env.popup.printCalls, 0)
    assert.equal(env.timers.active.size, 0)
})

test('reports premature popup closure without retrying or printing', async () => {
    const env = environment({ imageMode: 'manual' })
    const source = deferred()
    const job = printQrLabels({
        windowRef: env.windowRef,
        items: [{ identifier: shortIdentifier, source }],
        getImageSource: item => item.source.promise,
        timerApi: env.timers,
    })
    env.popup.closed = true
    env.timers.tick()
    await assert.rejects(job, { message: QR_PRINT_MESSAGES.closed })
    source.resolve(imageSource)
    await Promise.resolve()
    assert.equal(env.windowRef.openCalls.length, 1)
    assert.equal(env.popup.focusCalls, 0)
    assert.equal(env.popup.printCalls, 0)
    assert.equal(env.timers.active.size, 0)
})

test('times out when document readiness never completes and detaches ownership', async () => {
    const env = environment({ readyState: 'loading', imageMode: 'load' })
    const job = printQrLabels({
        windowRef: env.windowRef,
        items: [{ identifier: shortIdentifier, imageSource }],
        timerApi: env.timers,
    })
    const rejection = assert.rejects(job, { message: QR_PRINT_MESSAGES.timeout })

    const timeout = [...env.timers.active.values()]
        .find(entry => entry.type === 'timeout')
    assert.equal(timeout.delay, QR_PRINT_READY_TIMEOUT_MS)
    env.timers.timeout()
    await rejection

    env.popup.dispatch('load')
    env.timers.tick()
    assert.equal(env.popup.closeCalls, 1)
    assert.equal(env.popup.focusCalls, 0)
    assert.equal(env.popup.printCalls, 0)
    assert.equal(env.popup.listeners.get('load')?.size || 0, 0)
    assert.equal(env.timers.active.size, 0)
    for (const image of env.documentRef.created.filter(node => node.tagName === 'IMG')) {
        assert.equal(image.listeners.get('load')?.size || 0, 0)
        assert.equal(image.listeners.get('error')?.size || 0, 0)
    }
})

test('times out when a single image promise never settles', async () => {
    const env = environment({ imageMode: 'manual' })
    const pending = deferred()
    const job = printQrLabels({
        windowRef: env.windowRef,
        items: [{ identifier: shortIdentifier, pending }],
        getImageSource: item => item.pending.promise,
        timerApi: env.timers,
    })
    const rejection = assert.rejects(job, { message: QR_PRINT_MESSAGES.timeout })
    env.timers.timeout()
    await rejection

    pending.resolve(imageSource)
    await Promise.resolve()
    await Promise.resolve()
    assert.equal(env.popup.closeCalls, 1)
    assert.equal(env.popup.focusCalls, 0)
    assert.equal(env.popup.printCalls, 0)
    assert.equal(env.timers.active.size, 0)
})

test('times out when one image in a batch never settles and ignores later events', async () => {
    const env = environment({ imageMode: 'manual' })
    const ready = deferred()
    const pending = deferred()
    const job = printQrLabels({
        windowRef: env.windowRef,
        items: [
            { identifier: shortIdentifier, source: ready },
            { identifier: longIdentifier, source: pending },
        ],
        getImageSource: item => item.source.promise,
        timerApi: env.timers,
    })
    const rejection = assert.rejects(job, { message: QR_PRINT_MESSAGES.timeout })
    ready.resolve(imageSource)
    await Promise.resolve()
    await Promise.resolve()
    const images = env.documentRef.created.filter(node => node.tagName === 'IMG')
    images.slice(0, 2).forEach(image => image.dispatch('load'))

    env.timers.timeout()
    await rejection
    pending.resolve(imageSource)
    await Promise.resolve()
    await Promise.resolve()
    images.forEach(image => {
        image.dispatch('load')
        image.dispatch('error')
    })
    env.popup.dispatch('load')
    assert.equal(env.popup.focusCalls, 0)
    assert.equal(env.popup.printCalls, 0)
    assert.equal(env.timers.active.size, 0)
})

test('success immediately before timeout clears both timers', async () => {
    const env = environment({ readyState: 'loading', imageMode: 'manual' })
    const job = printQrLabels({
        windowRef: env.windowRef,
        items: [{ identifier: shortIdentifier, imageSource }],
        timerApi: env.timers,
    })
    await Promise.resolve()
    await Promise.resolve()
    const images = env.documentRef.created.filter(node => node.tagName === 'IMG')
    images.forEach(image => image.dispatch('load'))
    env.popup.dispatch('load')
    await job

    env.timers.timeout()
    assert.equal(env.popup.closeCalls, 0)
    assert.equal(env.popup.focusCalls, 1)
    assert.equal(env.popup.printCalls, 1)
    assert.equal(env.timers.active.size, 0)
})

test('timeout racing popup closure has one terminal winner', async () => {
    const env = environment({ imageMode: 'manual' })
    const pending = deferred()
    const job = printQrLabels({
        windowRef: env.windowRef,
        items: [{ identifier: shortIdentifier, pending }],
        getImageSource: item => item.pending.promise,
        timerApi: env.timers,
    })
    const rejection = assert.rejects(job, { message: QR_PRINT_MESSAGES.timeout })

    env.timers.timeout()
    env.timers.tick()
    await rejection
    assert.equal(env.popup.closeCalls, 1)
    assert.equal(env.popup.focusCalls, 0)
    assert.equal(env.popup.printCalls, 0)
    assert.equal(env.timers.active.size, 0)
})

test('reports print invocation failure with a fixed message and no retry', async () => {
    const env = environment()
    env.popup.print = function () {
        this.printCalls += 1
        throw new Error('driver detail')
    }
    await assert.rejects(
        printQrLabels({
            windowRef: env.windowRef,
            items: [{ identifier: shortIdentifier, imageSource }],
            timerApi: env.timers,
        }),
        { message: QR_PRINT_MESSAGES.print }
    )
    env.popup.dispatch('load')
    assert.equal(env.popup.focusCalls, 1)
    assert.equal(env.popup.printCalls, 1)
    assert.equal(env.timers.active.size, 0)
})

test('linked and batch print paths use only the shared helper contract', async () => {
    const documentDetails = await readFile(
        new URL('../../resources/js/pages/DocumentDetails.vue', import.meta.url),
        'utf8'
    )
    const qrCodes = await readFile(
        new URL('../../resources/js/pages/QrCodes.vue', import.meta.url),
        'utf8'
    )
    const linkedPrint = documentDetails.match(
        /const printQRCode = async \(\) => \{[\s\S]*?\n\}/
    )?.[0] || ''
    const batchPrint = qrCodes.match(
        /const printLastBatch = async \(\) => \{[\s\S]*?\n\}/
    )?.[0] || ''

    for (const source of [linkedPrint, batchPrint]) {
        assert.match(source, /printQrLabels\s*\(/)
        assert.doesNotMatch(source, /document\.write|<script|innerHTML/)
    }
    assert.doesNotMatch(linkedPrint, /tracking_no|document\.value\.title/)
    assert.doesNotMatch(batchPrint, /fetch\s*\(|\/api\/qr-codes/)
    assert.match(batchPrint, /getImageSource:\s*item\s*=>\s*createQrImage\(item\.qr\)/s)
})
