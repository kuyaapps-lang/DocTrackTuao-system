export const QR_PRINT_MESSAGES = Object.freeze({
    blocked: 'Unable to open print window. Please allow pop-ups.',
    image: 'Unable to prepare the QR image for printing.',
    identifier: 'Unable to prepare QR labels for printing.',
    closed: 'The print window was closed before printing was ready.',
    timeout: 'The print preview was not ready in time. Please try again.',
    print: 'Unable to start printing. Please try again.',
})

export const QR_PRINT_TITLE = 'QR Label Print Preview'
export const QR_PRINT_READY_TIMEOUT_MS = 15_000

export const qrPrintFailureMessage = error =>
    Object.values(QR_PRINT_MESSAGES).includes(error?.message)
        ? error.message
        : QR_PRINT_MESSAGES.print

export const QR_PRINT_STYLES = `
@page {
    margin: 0.30in;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    padding: 0.20in;
    font-family: Arial, sans-serif;
    color: #111827;
    background: #f3f4f6;
}

.screen-note {
    max-width: 42rem;
    margin: 0 auto 0.15in;
    padding: 0.10in;
    border: 1px solid #d1d5db;
    border-radius: 0.08in;
    background: white;
    text-align: center;
    font-size: 9pt;
    color: #4b5563;
}

.sheet {
    display: grid;
    grid-template-columns: repeat(auto-fit, 1in);
    gap: 0.12in;
    max-width: 8in;
    margin: 0 auto;
    padding: 0.15in;
    justify-content: center;
    align-items: start;
    background: white;
}

.qr-pair {
    width: 1in;
    break-inside: avoid;
    page-break-inside: avoid;
}

.label {
    width: 1in;
    height: 1in;
    border: 1px dashed #9ca3af;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    padding: 0.015in;
    background: white;
}

.record-copy {
    border-top: 0;
}

.copy-name {
    height: 0.09in;
    line-height: 0.09in;
    font-size: 5.2pt;
    font-weight: 700;
    letter-spacing: 0.15px;
    flex: 0 0 auto;
}

.label img {
    width: 0.72in;
    height: 0.72in;
    display: block;
    flex: 0 0 auto;
}

.identifier {
    width: 0.94in;
    text-align: center;
    font-family: Consolas, "Courier New", monospace;
    font-weight: 700;
    overflow-wrap: anywhere;
    word-break: break-all;
    white-space: normal;
    flex: 0 0 auto;
}

.identifier-short {
    height: 0.11in;
    line-height: 0.11in;
    font-size: 5.3pt;
    letter-spacing: 0.05px;
}

.identifier-long {
    height: 0.13in;
    line-height: 0.065in;
    font-size: 4.2pt;
    letter-spacing: 0;
}

.identifier-long span {
    display: block;
    height: 0.065in;
}

@media print {
    body {
        margin: 0;
        padding: 0;
        background: white;
    }

    .screen-note {
        display: none;
    }

    .sheet {
        grid-template-columns: repeat(4, 1in);
        column-gap: 0.12in;
        row-gap: 0.12in;
        max-width: none;
        margin: 0;
        padding: 0;
    }
}
`

const safeImageSource = value =>
    typeof value === 'string' &&
    /^data:image\/png;base64,[a-z0-9+/]+={0,2}$/i.test(value)

const currentIdentifierPattern =
    /^[A-HJ-KM-NP-Z2-9]{5}-[A-HJ-KM-NP-Z2-9]{7}$/
const uuidIdentifierPattern =
    /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i

const identifierSnapshot = item => {
    const identifier = item?.identifier

    if (typeof identifier !== 'string') return null

    if (currentIdentifierPattern.test(identifier)) {
        return {
            identifier,
            identifierClass: 'identifier identifier-short',
            identifierLines: [identifier],
            sourceItem: item,
            sourcePromise: null,
        }
    }

    if (uuidIdentifierPattern.test(identifier)) {
        return {
            identifier,
            identifierClass: 'identifier identifier-long',
            identifierLines: [
                identifier.slice(0, 18),
                identifier.slice(18),
            ],
            sourceItem: item,
            sourcePromise: null,
        }
    }

    return null
}

const appendTextElement = (documentRef, parent, tag, className, text) => {
    const element = documentRef.createElement(tag)
    element.className = className
    element.textContent = text
    parent.appendChild(element)
    return element
}

export const printQrLabels = ({
    windowRef,
    items,
    getImageSource = item => item.imageSource,
    timerApi = globalThis,
}) => {
    const snapshots = Array.isArray(items)
        ? items.map(identifierSnapshot)
        : []

    if (
        snapshots.length === 0 ||
        snapshots.some(snapshot => snapshot === null)
    ) {
        throw new Error(QR_PRINT_MESSAGES.identifier)
    }

    const printWindow = windowRef.open(
        '',
        '_blank',
        'width=800,height=700'
    )

    if (!printWindow) {
        throw new Error(QR_PRINT_MESSAGES.blocked)
    }

    const documentRef = printWindow.document
    documentRef.title = QR_PRINT_TITLE
    documentRef.head.replaceChildren()
    documentRef.body.replaceChildren()

    const style = documentRef.createElement('style')
    style.textContent = QR_PRINT_STYLES
    documentRef.head.appendChild(style)

    const screenNote = appendTextElement(
        documentRef,
        documentRef.body,
        'div',
        'screen-note',
        'ORIGINAL is printed above its matching RECORD COPY.'
    )
    screenNote.setAttribute('role', 'note')

    const sheet = documentRef.createElement('div')
    sheet.className = 'sheet'
    documentRef.body.appendChild(sheet)

    const images = []

    for (const snapshot of snapshots) {
        const pair = documentRef.createElement('div')
        pair.className = 'qr-pair'
        sheet.appendChild(pair)

        for (const [copyName, extraClass] of [
            ['ORIGINAL', ''],
            ['RECORD COPY', ' record-copy'],
        ]) {
            const label = documentRef.createElement('div')
            label.className = `label${extraClass}`
            pair.appendChild(label)

            appendTextElement(
                documentRef,
                label,
                'div',
                'copy-name',
                copyName
            )

            const image = documentRef.createElement('img')
            image.alt = 'QR Code'
            label.appendChild(image)
            images.push({ image, snapshot })

            const identifier = documentRef.createElement('div')
            identifier.className = snapshot.identifierClass

            for (const line of snapshot.identifierLines) {
                appendTextElement(
                    documentRef,
                    identifier,
                    'span',
                    '',
                    line
                )
            }

            label.appendChild(identifier)
        }
    }

    return new Promise((resolve, reject) => {
        const ownership = { popup: printWindow }
        let settled = false
        let printed = false
        let popupReady = documentRef.readyState === 'complete'
        let remainingImages = images.length
        let closeTimer = null
        let readinessTimer = null
        const cleanups = []

        const cleanup = () => {
            for (const remove of cleanups.splice(0)) {
                remove()
            }
            if (closeTimer !== null) {
                timerApi.clearInterval(closeTimer)
                closeTimer = null
            }
            if (readinessTimer !== null) {
                timerApi.clearTimeout(readinessTimer)
                readinessTimer = null
            }
            for (const snapshot of snapshots) {
                snapshot.sourceItem = null
                snapshot.sourcePromise = null
            }
            images.length = 0
            ownership.popup = null
        }

        const fail = (message, closePopup = false) => {
            if (settled) return
            const popup = ownership.popup
            settled = true
            cleanup()
            if (closePopup && popup && !popup.closed) {
                try {
                    popup.close()
                } catch {
                    // The fixed failure remains authoritative.
                }
            }
            reject(new Error(message))
        }

        const printWhenReady = () => {
            if (
                settled ||
                printed ||
                !popupReady ||
                remainingImages !== 0
            ) {
                return
            }

            const popup = ownership.popup

            if (!popup || popup.closed) {
                fail(QR_PRINT_MESSAGES.closed)
                return
            }

            printed = true

            try {
                popup.focus()
                popup.print()
                settled = true
                cleanup()
                resolve()
            } catch {
                fail(QR_PRINT_MESSAGES.print)
            }
        }

        const onPopupLoad = () => {
            popupReady = true
            printWhenReady()
        }
        printWindow.addEventListener('load', onPopupLoad)
        cleanups.push(() =>
            printWindow.removeEventListener('load', onPopupLoad)
        )

        closeTimer = timerApi.setInterval(() => {
            if (ownership.popup?.closed) {
                fail(QR_PRINT_MESSAGES.closed)
            }
        }, 100)

        readinessTimer = timerApi.setTimeout(() => {
            fail(QR_PRINT_MESSAGES.timeout, true)
        }, QR_PRINT_READY_TIMEOUT_MS)

        if (images.length === 0) {
            fail(QR_PRINT_MESSAGES.image)
            return
        }

        for (const { image, snapshot } of images) {
            const imageOwnership = { image }
            let imageSettled = false

            const removeImageListeners = () => {
                imageOwnership.image?.removeEventListener(
                    'load',
                    onImageLoad
                )
                imageOwnership.image?.removeEventListener(
                    'error',
                    onImageError
                )
            }
            const releaseImage = () => {
                removeImageListeners()
                imageOwnership.image = null
            }
            const onImageLoad = () => {
                if (imageSettled || settled) return
                imageSettled = true
                releaseImage()
                remainingImages -= 1
                printWhenReady()
            }
            const onImageError = () => {
                if (imageSettled || settled) return
                imageSettled = true
                releaseImage()
                fail(QR_PRINT_MESSAGES.image)
            }

            image.addEventListener('load', onImageLoad)
            image.addEventListener('error', onImageError)
            cleanups.push(releaseImage)

            snapshot.sourcePromise ??= Promise.resolve()
                .then(() => getImageSource(snapshot.sourceItem))

            snapshot.sourcePromise
                .then(source => {
                    if (settled) return
                    if (!safeImageSource(source)) {
                        onImageError()
                        return
                    }

                    const ownedImage = imageOwnership.image

                    if (!ownedImage) return

                    ownedImage.src = source

                    if (ownedImage.complete) {
                        if (ownedImage.naturalWidth > 0) {
                            onImageLoad()
                        } else {
                            onImageError()
                        }
                    }
                })
                .catch(() => onImageError())
        }

        printWhenReady()
    })
}
