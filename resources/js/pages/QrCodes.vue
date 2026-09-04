<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import QRCode from 'qrcode'
import { clearCurrentUser } from '@/lib/auth'
import {
    printQrLabels,
    qrPrintFailureMessage,
} from '@/lib/qrPrint'
import {
    canBeginVoid,
    canVoidInventoryItem,
    createInventoryManager,
    voidConfirmationText,
} from '@/lib/qrInventory'
import {
    createQrSummaryManager,
    emptyQrSummary,
} from '@/lib/qrSummary'

import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card'

import { Button } from '@/components/ui/button'

/*
|--------------------------------------------------------------------------
| Configuration
|--------------------------------------------------------------------------
|
| Put the logo file here:
| public/images/qr-logo.png
|
| It will be available in the browser as:
| /images/qr-logo.png
|
*/

const qrLogoUrl = '/images/qr-logo.png?v=3'
const qrLogoScale = 0.36
const maxBatchSize = 50

/*
|--------------------------------------------------------------------------
| State
|--------------------------------------------------------------------------
*/

const summary = ref(emptyQrSummary())
const summaryLoading = ref(true)
const summaryError = ref('')
const generating = ref(false)

const quantity = ref(10)
const lastGeneratedBatch = ref([])

const error = ref('')
const successMessage = ref('')

const inventory = ref([])
const inventoryMeta = ref(null)
const inventoryLoading = ref(true)
const inventoryError = ref('')
const inventoryNotice = ref('')
const inventoryNoticeKind = ref('success')
const inventoryStatus = ref('unused')
const selectedQr = ref(null)
const voidingId = ref(null)
const confirmButton = ref(null)
const inventoryHeading = ref(null)
const voidReturnFocus = ref(null)

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

const getToken = () => {
    return localStorage.getItem('auth_token')
}

/*
|--------------------------------------------------------------------------
| Quantity Controls
|--------------------------------------------------------------------------
*/

const normalizeQuantity = () => {
    let value =
        Number.parseInt(
            quantity.value,
            10
        )

    if (Number.isNaN(value)) {
        value = 1
    }

    quantity.value =
        Math.min(
            maxBatchSize,
            Math.max(1, value)
        )
}

const decreaseQuantity = () => {
    normalizeQuantity()

    quantity.value =
        Math.max(
            1,
            quantity.value - 1
        )
}

const increaseQuantity = () => {
    normalizeQuantity()

    quantity.value =
        Math.min(
            maxBatchSize,
            quantity.value + 1
        )
}

/*
|--------------------------------------------------------------------------
| Image Helpers
|--------------------------------------------------------------------------
*/

const loadImage = (src) => {
    return new Promise(
        (resolve, reject) => {
            const image = new Image()

            image.onload = () =>
                resolve(image)

            image.onerror = () =>
                reject(
                    new Error(
                        `Unable to load image: ${src}`
                    )
                )

            image.src = src
        }
    )
}

/*
|--------------------------------------------------------------------------
| Create QR Image With Center Logo
|--------------------------------------------------------------------------
|
| High error correction is used and the logo is kept intentionally small.
|
*/

const createQrImage = async (qr) => {
    const scanUrl =
        `${window.location.origin}/q/${qr.qr_token}`

    /*
    |--------------------------------------------------------------------------
    | Generate Base QR
    |--------------------------------------------------------------------------
    |
    | A larger source canvas gives the logo cleaner edges when printed.
    |
    */

    const size = 800

    const qrDataUrl =
        await QRCode.toDataURL(
            scanUrl,
            {
                width: size,
                margin: 2,
                errorCorrectionLevel: 'H',
            }
        )

    const qrImage =
        await loadImage(qrDataUrl)

    let logoImage = null

    try {
        logoImage =
            await loadImage(qrLogoUrl)
    } catch {
        logoImage = null
    }

    const canvas =
        window.document.createElement(
            'canvas'
        )

    canvas.width = size
    canvas.height = size

    const context =
        canvas.getContext('2d')

    /*
    |--------------------------------------------------------------------------
    | Draw Base QR
    |--------------------------------------------------------------------------
    */

    context.fillStyle = '#ffffff'

    context.fillRect(
        0,
        0,
        size,
        size
    )

    context.drawImage(
        qrImage,
        0,
        0,
        size,
        size
    )

    /*
    |--------------------------------------------------------------------------
    | Draw Large Circular Center Logo
    |--------------------------------------------------------------------------
    |
    | qrLogoScale = 0.36 means the logo occupies 36% of QR width.
    | To adjust later, change only qrLogoScale near the top of this file.
    |
    */

    if (logoImage) {
        const logoSize =
            Math.round(
                size * qrLogoScale
            )

        const centerX =
            size / 2

        const centerY =
            size / 2

        /*
        |--------------------------------------------------------------------------
        | Tight white circular clearance
        |--------------------------------------------------------------------------
        */

        const backgroundSize =
            Math.round(
                logoSize * 1.035
            )

        context.save()

        context.beginPath()

        context.arc(
            centerX,
            centerY,
            backgroundSize / 2,
            0,
            Math.PI * 2
        )

        context.fillStyle = '#ffffff'
        context.fill()

        /*
        |--------------------------------------------------------------------------
        | Clip source image into a circle
        |--------------------------------------------------------------------------
        */

        context.beginPath()

        context.arc(
            centerX,
            centerY,
            logoSize / 2,
            0,
            Math.PI * 2
        )

        context.clip()

        /*
        |--------------------------------------------------------------------------
        | Fill the circular logo area
        |--------------------------------------------------------------------------
        |
        | object-fit: cover equivalent:
        | crop the source to a centered square, then scale that square
        | directly to logoSize x logoSize.
        |
        */

        const sourceWidth =
            logoImage.naturalWidth ||
            logoImage.width

        const sourceHeight =
            logoImage.naturalHeight ||
            logoImage.height

        const sourceSquare =
            Math.min(
                sourceWidth,
                sourceHeight
            )

        const sourceX =
            (sourceWidth - sourceSquare) / 2

        const sourceY =
            (sourceHeight - sourceSquare) / 2

        context.drawImage(
            logoImage,

            sourceX,
            sourceY,
            sourceSquare,
            sourceSquare,

            centerX - (logoSize / 2),
            centerY - (logoSize / 2),
            logoSize,
            logoSize
        )

        context.restore()

        /*
        |--------------------------------------------------------------------------
        | Black Circular Outline Around Logo
        |--------------------------------------------------------------------------
        */

        context.beginPath()

        context.arc(
            centerX,
            centerY,
            logoSize / 2,
            0,
            Math.PI * 2
        )

        context.strokeStyle = '#000000'
        context.lineWidth = 6

        context.stroke()
            }

            return canvas.toDataURL(
                'image/png'
            )
        }

const clearAuthentication = () => {
    localStorage.removeItem('auth_token')
    localStorage.removeItem('auth_user')
    clearCurrentUser()
    window.location.assign('/login')
}

const summaryManager = createQrSummaryManager({
    fetchImpl: (...arguments_) => fetch(...arguments_),
    getToken,
    onUnauthorized: clearAuthentication,
    onState: state => {
        summary.value = state.summary
        summaryLoading.value = state.loading
        summaryError.value = state.error
    },
})

const fetchSummary = () => summaryManager.load()

const inventoryManager = createInventoryManager({
    fetchImpl: (...arguments_) => fetch(...arguments_),
    getToken,
    onUnauthorized: clearAuthentication,
    onVoided: () => {
        void fetchSummary()
    },
    onState: state => {
        inventory.value = state.items
        inventoryMeta.value = state.meta
        inventoryLoading.value = state.loading
        inventoryError.value = state.error
        inventoryNotice.value = state.notice
        inventoryNoticeKind.value = state.noticeKind
        voidingId.value = state.pendingId
    },
})

const fetchInventory = (page = 1) => inventoryManager.load({
    page,
    perPage: 10,
    status: inventoryStatus.value,
})

const restoreVoidFocus = async () => {
    if (inventoryManager.isDisposed()) return
    await nextTick()
    if (inventoryManager.isDisposed()) return
    const target = voidReturnFocus.value?.isConnected
        ? voidReturnFocus.value
        : inventoryHeading.value
    target?.focus()
    voidReturnFocus.value = null
}

const openVoidConfirmation = async (item, event) => {
    if (inventoryManager.isDisposed()) return
    if (!canBeginVoid(voidingId.value, item)) return
    voidReturnFocus.value = event?.currentTarget || null
    selectedQr.value = item
    await nextTick()
    confirmButton.value?.focus()
}

const closeVoidConfirmation = async () => {
    if (inventoryManager.isDisposed()) return
    if (voidingId.value !== null) return
    selectedQr.value = null
    await restoreVoidFocus()
}

const confirmVoid = async () => {
    const item = selectedQr.value
    if (!canBeginVoid(voidingId.value, item)) return
    const result = await inventoryManager.voidItem(item, {
        page: inventoryMeta.value?.current_page || 1,
        perPage: 10,
        status: inventoryStatus.value,
    })
    if (result.kind === 'duplicate' || inventoryManager.isDisposed()) return
    selectedQr.value = null
    await restoreVoidFocus()
}

/*
|--------------------------------------------------------------------------
| Generate QR Batch
|--------------------------------------------------------------------------
*/

const generateQrBatch = async () => {
    normalizeQuantity()

    generating.value = true
    error.value = ''
    successMessage.value = ''
    lastGeneratedBatch.value = []

    try {
        const response = await fetch(
            '/api/qr-codes',
            {
                method: 'POST',

                headers: {
                    Accept:
                        'application/json',

                    'Content-Type':
                        'application/json',

                    Authorization:
                        `Bearer ${getToken()}`,
                },

                body: JSON.stringify({
                    quantity:
                        quantity.value,
                }),
            }
        )

        const data =
            await response.json()

        if (!response.ok) {
            if (data.errors) {
                const firstError =
                    Object.values(
                        data.errors
                    )[0]

                throw new Error(
                    Array.isArray(
                        firstError
                    )
                        ? firstError[0]
                        : firstError
                )
            }

            throw new Error(
                data.message ||
                'Unable to generate QR codes.'
            )
        }

        lastGeneratedBatch.value =
            Array.isArray(
                data.qr_codes
            )
                ? data.qr_codes
                : []

        successMessage.value =
            data.message ||
            `${quantity.value} QR codes generated successfully.`

        await fetchSummary()

    } catch (err) {
        error.value =
            err.message ||
            'Unable to generate QR codes.'
    } finally {
        generating.value = false
    }
}

/*
|--------------------------------------------------------------------------
| Print Last Generated Batch
|--------------------------------------------------------------------------
*/

const printLastBatch = async () => {
    if (
        lastGeneratedBatch.value
            .length === 0
    ) {
        error.value =
            'Generate a QR batch before printing.'

        return
    }

    error.value = ''

    try {
        await printQrLabels({
            windowRef: window,
            items: lastGeneratedBatch.value.map(
                qr => ({
                    identifier: qr.qr_token,
                    qr,
                })
            ),
            getImageSource: item =>
                createQrImage(item.qr),
        })

    } catch (err) {
        error.value =
            qrPrintFailureMessage(err)
    }
}

/*
|--------------------------------------------------------------------------
| Formatting
|--------------------------------------------------------------------------
*/

const formatDateTime = (date) => {
    if (!date) {
        return 'N/A'
    }

    return new Date(
        date
    ).toLocaleString()
}

/*
|--------------------------------------------------------------------------
| Initialize
|--------------------------------------------------------------------------
*/

onMounted(() => {
    fetchSummary()
    fetchInventory()
})

onBeforeUnmount(() => {
    summaryManager.dispose()
    inventoryManager.dispose()
})
</script>

<template>
    <div
        class="min-h-screen bg-gray-100"
    >

        <!-- Header -->
        <div
            class="border-b bg-white px-6 py-4"
        >

            <div
                class="mx-auto max-w-5xl"
            >

                <h1
                    class="text-2xl font-bold text-gray-900"
                >
                    QR Code Request / Issuance
                </h1>

                <p
                    class="mt-1 text-sm text-gray-500"
                >
                    Request QR codes in bulk,
                    print the labels, and attach
                    them to physical documents.
                </p>

            </div>

        </div>

        <!-- Main -->
        <div
            class="mx-auto max-w-5xl p-6"
        >

            <!-- Success -->
            <div
                v-if="successMessage"
                class="mb-5 rounded-md border border-green-200 bg-green-50 p-4 text-sm font-semibold text-green-700"
            >
                {{ successMessage }}
            </div>

            <!-- Error -->
            <div
                v-if="error"
                class="mb-5 rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-600"
            >
                {{ error }}
            </div>

            <!-- Request -->
            <Card>

                <CardHeader>

                    <CardTitle>
                        Request QR Codes
                    </CardTitle>

                    <p
                        class="text-sm text-gray-500"
                    >
                        Specify how many QR labels
                        are required for this batch.
                    </p>

                </CardHeader>

                <CardContent>

                    <div
                        class="flex flex-col gap-5 md:flex-row md:items-end md:justify-between"
                    >

                        <div>

                            <label
                                class="block text-sm font-semibold text-gray-700"
                            >
                                Number of QR Codes
                            </label>

                            <div
                                class="mt-2 flex items-center gap-2"
                            >

                                <button
                                    type="button"
                                    class="h-11 w-11 rounded-md border border-gray-300 bg-white text-xl font-bold text-gray-700 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-50"
                                    :disabled="
                                        generating ||
                                        quantity <= 1
                                    "
                                    @click="
                                        decreaseQuantity
                                    "
                                >
                                    −
                                </button>

                                <input
                                    v-model.number="
                                        quantity
                                    "
                                    type="number"
                                    min="1"
                                    :max="
                                        maxBatchSize
                                    "
                                    class="h-11 w-24 rounded-md border border-gray-300 bg-white px-3 text-center text-lg font-bold text-gray-900 outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                                    :disabled="
                                        generating
                                    "
                                    @blur="
                                        normalizeQuantity
                                    "
                                    @change="
                                        normalizeQuantity
                                    "
                                >

                                <button
                                    type="button"
                                    class="h-11 w-11 rounded-md border border-gray-300 bg-white text-xl font-bold text-gray-700 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-50"
                                    :disabled="
                                        generating ||
                                        quantity >=
                                            maxBatchSize
                                    "
                                    @click="
                                        increaseQuantity
                                    "
                                >
                                    +
                                </button>

                            </div>

                            <p
                                class="mt-2 text-xs text-gray-500"
                            >
                                1–50 unique QR codes
                                per request.
                            </p>

                        </div>

                        <Button
                            class="bg-blue-600 px-6 text-white hover:bg-blue-700"
                            :disabled="
                                generating
                            "
                            @click="
                                generateQrBatch
                            "
                        >
                            {{
                                generating
                                    ? 'Generating...'
                                    : `Generate ${quantity} QR Code${quantity === 1 ? '' : 's'}`
                            }}
                        </Button>

                    </div>

                </CardContent>

            </Card>

            <!-- Last Batch -->
            <Card
                v-if="
                    lastGeneratedBatch.length >
                    0
                "
                class="mt-6 border-blue-200"
            >

                <CardHeader>

                    <div
                        class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
                    >

                        <div>

                            <CardTitle>
                                Last Generated Batch
                            </CardTitle>

                            <p
                                class="mt-1 text-sm text-gray-500"
                            >
                                {{
                                    lastGeneratedBatch.length
                                }}
                                unique QR code{{
                                    lastGeneratedBatch.length ===
                                    1
                                        ? ''
                                        : 's'
                                }}
                                generated and ready
                                for printing.
                            </p>

                        </div>

                        <Button
                            class="bg-gray-900 text-white hover:bg-black"
                            @click="
                                printLastBatch
                            "
                        >
                            Print Last Batch
                        </Button>

                    </div>

                </CardHeader>

                <CardContent>

                    <div
                        class="rounded-lg border border-blue-100 bg-blue-50 p-4 text-sm text-blue-800"
                    >
                        Every unique QR prints as a
                        <strong>
                            1 × 1 inch ORIGINAL
                        </strong>
                        label with its matching
                        <strong>
                            RECORD COPY
                        </strong>
                        directly underneath.
                        Both copies contain the same
                        QR token.
                    </div>

                    <!-- Token Preview -->
                    <div
                        class="mt-5"
                    >

                        <p
                            class="text-xs font-semibold uppercase tracking-wide text-gray-500"
                        >
                            Generated Tokens
                        </p>

                        <div
                            class="mt-2 flex flex-wrap gap-2"
                        >

                            <span
                                v-for="
                                    qr in
                                    lastGeneratedBatch
                                "
                                :key="qr.id"
                                class="rounded-md border bg-white px-3 py-1.5 font-mono text-sm font-semibold text-gray-700"
                            >
                                {{
                                    qr.qr_token
                                }}
                            </span>

                        </div>

                    </div>

                </CardContent>

            </Card>

            <!-- Workflow -->
            <Card class="mt-6">

                <CardHeader>
                    <CardTitle>
                        QR Workflow
                    </CardTitle>
                </CardHeader>

                <CardContent>

                    <div
                        class="grid gap-5 md:grid-cols-3"
                    >

                        <div>

                            <p
                                class="text-xs font-semibold uppercase text-gray-500"
                            >
                                Step 1
                            </p>

                            <p
                                class="mt-1 font-semibold text-gray-900"
                            >
                                Request Batch
                            </p>

                            <p
                                class="mt-1 text-sm text-gray-500"
                            >
                                Request the required
                                number of unique QR
                                labels.
                            </p>

                        </div>

                        <div>

                            <p
                                class="text-xs font-semibold uppercase text-gray-500"
                            >
                                Step 2
                            </p>

                            <p
                                class="mt-1 font-semibold text-gray-900"
                            >
                                Print & Attach
                            </p>

                            <p
                                class="mt-1 text-sm text-gray-500"
                            >
                                Attach ORIGINAL to
                                the hardcopy and
                                retain RECORD COPY
                                for retrieval.
                            </p>

                        </div>

                        <div>

                            <p
                                class="text-xs font-semibold uppercase text-gray-500"
                            >
                                Step 3
                            </p>

                            <p
                                class="mt-1 font-semibold text-gray-900"
                            >
                                Scan & Register
                            </p>

                            <p
                                class="mt-1 text-sm text-gray-500"
                            >
                                Scan either copy to
                                encode the document
                                or later retrieve its
                                record.
                            </p>

                        </div>

                    </div>

                </CardContent>

            </Card>

            <!-- Record Summary -->
            <Card class="mt-6">

                <CardHeader>
                    <CardTitle>
                        QR Record Summary
                    </CardTitle>
                </CardHeader>

                <CardContent>

                    <div
                        v-if="summaryLoading"
                        class="py-5 text-center text-gray-500"
                    >
                        Loading QR records...
                    </div>

                    <div
                        v-else
                        class="grid gap-4 sm:grid-cols-3"
                    >

                        <div
                            class="rounded-lg border bg-gray-50 p-4"
                        >
                            <p
                                class="text-xs font-semibold uppercase text-gray-500"
                            >
                                Total Issued
                            </p>

                            <p
                                class="mt-1 text-2xl font-bold text-gray-900"
                            >
                                {{
                                    summary.total_issued
                                }}
                            </p>
                        </div>

                        <div
                            class="rounded-lg border bg-yellow-50 p-4"
                        >
                            <p
                                class="text-xs font-semibold uppercase text-yellow-700"
                            >
                                Unused
                            </p>

                            <p
                                class="mt-1 text-2xl font-bold text-yellow-800"
                            >
                                {{
                                    summary.counts.unused
                                }}
                            </p>
                        </div>

                        <div
                            class="rounded-lg border bg-green-50 p-4"
                        >
                            <p
                                class="text-xs font-semibold uppercase text-green-700"
                            >
                                Registered
                            </p>

                            <p
                                class="mt-1 text-2xl font-bold text-green-800"
                            >
                                {{
                                    summary.counts.registered
                                }}
                            </p>
                        </div>

                    </div>

                    <p
                        v-if="
                            summary.latest_issued_at
                        "
                        class="mt-4 text-xs text-gray-500"
                    >
                        Latest issuance activity:
                        {{
                            formatDateTime(
                                summary.latest_issued_at
                            )
                        }}
                    </p>

                    <p
                        v-if="summaryError"
                        class="mt-4 text-sm text-red-600"
                    >
                        {{ summaryError }}
                    </p>

                </CardContent>

            </Card>

            <Card class="mt-6">
                <CardHeader>
                    <CardTitle>
                        <span ref="inventoryHeading" tabindex="-1">Persisted QR Inventory</span>
                    </CardTitle>
                    <p class="text-sm text-gray-500">
                        Token-free issuance records for lifecycle administration.
                    </p>
                </CardHeader>

                <CardContent>
                    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                        <label class="text-sm font-medium text-gray-700">
                            Lifecycle status
                            <select
                                v-model="inventoryStatus"
                                :disabled="voidingId !== null"
                                class="mt-1 block rounded-md border bg-white px-3 py-2"
                                @change="fetchInventory(1)"
                            >
                                <option value="">All statuses</option>
                                <option value="unused">Unused</option>
                                <option value="registered">Registered</option>
                                <option value="void">Void</option>
                            </select>
                        </label>

                        <Button
                            variant="outline"
                            :disabled="inventoryLoading || voidingId !== null"
                            @click="fetchInventory(inventoryMeta?.current_page || 1)"
                        >
                            Retry
                        </Button>
                    </div>

                    <p
                        aria-live="polite"
                        class="mb-3 text-sm"
                        :class="inventoryNoticeKind === 'conflict' ? 'text-amber-700' : 'text-green-700'"
                    >
                        {{ inventoryNotice }}
                    </p>
                    <p v-if="inventoryError" role="alert" class="mb-3 text-sm text-red-700">
                        {{ inventoryError }}
                    </p>

                    <div v-if="inventoryLoading" class="py-6 text-center text-gray-500" role="status">
                        Loading persisted QR inventory...
                    </div>

                    <div v-else-if="!inventoryError && inventory.length === 0" class="py-6 text-center text-gray-500">
                        {{ inventoryStatus
                            ? 'No QR records match the selected lifecycle status.'
                            : 'No persisted QR records are available.' }}
                    </div>

                    <div v-else-if="!inventoryError" class="overflow-x-auto">
                        <table class="min-w-full divide-y text-left text-sm">
                            <caption class="sr-only">
                                Persisted QR records with lifecycle status and void eligibility
                            </caption>
                            <thead class="bg-gray-50 text-gray-700">
                                <tr>
                                    <th scope="col" class="px-3 py-2">Record ID</th>
                                    <th scope="col" class="px-3 py-2">Issued</th>
                                    <th scope="col" class="px-3 py-2">Status</th>
                                    <th scope="col" class="px-3 py-2">Link state</th>
                                    <th scope="col" class="px-3 py-2">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-for="item in inventory" :key="item.id">
                                    <td class="whitespace-nowrap px-3 py-2 font-mono">#{{ item.id }}</td>
                                    <td class="whitespace-nowrap px-3 py-2">{{ formatDateTime(item.issued_at) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 capitalize">{{ item.status }}</td>
                                    <td class="whitespace-nowrap px-3 py-2">{{ item.linked ? 'Linked' : 'Unlinked' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2">
                                        <Button
                                            v-if="canVoidInventoryItem(item)"
                                            variant="destructive"
                                            :disabled="voidingId !== null"
                                            :aria-label="`Void QR record ${item.id}`"
                                            @click="openVoidConfirmation(item, $event)"
                                        >
                                            Void
                                        </Button>
                                        <span v-else class="text-gray-500">Not available</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="inventoryMeta" class="mt-4 flex items-center justify-between gap-3 text-sm">
                        <span>Page {{ inventoryMeta.current_page }} of {{ inventoryMeta.last_page }} / {{ inventoryMeta.total }} records</span>
                        <div class="flex gap-2">
                            <Button
                                variant="outline"
                                :disabled="inventoryLoading || voidingId !== null || inventoryMeta.current_page <= 1"
                                @click="fetchInventory(inventoryMeta.current_page - 1)"
                            >Previous</Button>
                            <Button
                                variant="outline"
                                :disabled="inventoryLoading || voidingId !== null || inventoryMeta.current_page >= inventoryMeta.last_page"
                                @click="fetchInventory(inventoryMeta.current_page + 1)"
                            >Next</Button>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <div
                v-if="selectedQr"
                role="dialog"
                aria-modal="true"
                aria-labelledby="void-confirm-title"
                aria-describedby="void-confirm-description"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
                @keydown.esc="closeVoidConfirmation"
            >
                <div class="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
                    <h2 id="void-confirm-title" class="text-lg font-semibold">Confirm QR void</h2>
                    <p id="void-confirm-description" class="mt-3 text-sm text-gray-700">
                        {{ voidConfirmationText(selectedQr) }}
                    </p>
                    <div class="mt-5 flex justify-end gap-2">
                        <Button variant="outline" :disabled="voidingId !== null" @click="closeVoidConfirmation">
                            Cancel
                        </Button>
                        <Button
                            ref="confirmButton"
                            variant="destructive"
                            :disabled="voidingId !== null"
                            @click="confirmVoid"
                        >
                            {{ voidingId !== null ? 'Voiding...' : 'Void record' }}
                        </Button>
                    </div>
                </div>
            </div>

        </div>

    </div>
</template>
