<script setup>
import { onMounted, ref } from 'vue'
import QRCode from 'qrcode'

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

const qrCodes = ref([])
const loading = ref(true)
const generating = ref(false)

const quantity = ref(10)
const lastGeneratedBatch = ref([])

const error = ref('')
const successMessage = ref('')

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

/*
|--------------------------------------------------------------------------
| Fetch Existing QR Records
|--------------------------------------------------------------------------
|
| The page intentionally does not render individual QR cards.
| These records remain available in the database for later admin/report use.
|
*/

const fetchQrCodes = async () => {
    loading.value = true
    error.value = ''

    try {
        const response = await fetch(
            '/api/qr-codes',
            {
                headers: {
                    Accept:
                        'application/json',

                    Authorization:
                        `Bearer ${getToken()}`,
                },
            }
        )

        const data =
            await response.json()

        if (!response.ok) {
            throw new Error(
                data.message ||
                'Unable to load QR records.'
            )
        }

        qrCodes.value =
            Array.isArray(data)
                ? data
                : []

    } catch (err) {
        error.value =
            err.message ||
            'Unable to load QR records.'
    } finally {
        loading.value = false
    }
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

        await fetchQrCodes()

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
| Build 1x1 Inch Label Pair
|--------------------------------------------------------------------------
|
| ORIGINAL is on top.
| RECORD COPY is immediately below it.
| Both use exactly the same QR token.
|
*/

const buildLabelPair = (
    qr,
    qrImage
) => {
    return `
        <div class="qr-pair">

            <div class="label">

                <div class="copy-name">
                    ORIGINAL
                </div>

                <img
                    src="${qrImage}"
                    alt="QR Code"
                >

                <div class="token">
                    ${qr.qr_token}
                </div>

            </div>

            <div class="label record-copy">

                <div class="copy-name">
                    RECORD COPY
                </div>

                <img
                    src="${qrImage}"
                    alt="QR Code"
                >

                <div class="token">
                    ${qr.qr_token}
                </div>

            </div>

        </div>
    `
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
        const printablePairs = []

        for (
            const qr of
            lastGeneratedBatch.value
        ) {
            const image =
                await createQrImage(qr)

            printablePairs.push(
                buildLabelPair(
                    qr,
                    image
                )
            )
        }

        const printWindow =
            window.open(
                '',
                '_blank',
                'width=1000,height=900'
            )

        if (!printWindow) {
            throw new Error(
                'Unable to open print window. Please allow pop-ups.'
            )
        }

        printWindow.document.write(`
            <!DOCTYPE html>

            <html>

            <head>

                <title>
                    QR Code Batch
                </title>

                <style>
                    @page {
                        margin: 0.30in;
                    }

                    * {
                        box-sizing:
                            border-box;
                    }

                    body {
                        margin: 0;
                        padding: 0;
                        font-family:
                            Arial,
                            sans-serif;
                        color: #111827;
                        background: white;
                    }

                    .screen-note {
                        margin-bottom:
                            0.15in;
                        text-align: center;
                        font-size: 9pt;
                        color: #4b5563;
                    }

                    .sheet {
                        display: grid;

                        grid-template-columns:
                            repeat(
                                4,
                                1in
                            );

                        column-gap:
                            0.12in;

                        row-gap:
                            0.12in;

                        justify-content:
                            center;

                        align-items:
                            start;
                    }

                    .qr-pair {
                        width: 1in;

                        break-inside:
                            avoid;

                        page-break-inside:
                            avoid;
                    }

                    .label {
                        width: 1in;
                        height: 1in;

                        border:
                            1px dashed
                            #9ca3af;

                        display: flex;
                        flex-direction:
                            column;

                        align-items:
                            center;

                        justify-content:
                            center;

                        overflow:
                            hidden;

                        padding:
                            0.015in;

                        background:
                            white;
                    }

                    .record-copy {
                        border-top: 0;
                    }

                    .copy-name {
                        height:
                            0.09in;

                        line-height:
                            0.09in;

                        font-size:
                            5.2pt;

                        font-weight:
                            700;

                        letter-spacing:
                            0.15px;
                    }

                    .label img {
                        width:
                            0.72in;

                        height:
                            0.72in;

                        display:
                            block;
                    }

                    .token {
                        width:
                            0.95in;

                        height:
                            0.11in;

                        line-height:
                            0.11in;

                        overflow:
                            hidden;

                        text-align:
                            center;

                        white-space:
                            nowrap;

                        font-size:
                            5.3pt;

                        font-weight:
                            700;

                        letter-spacing:
                            0.1px;
                    }

                    @media print {
                        .screen-note {
                            display: none;
                        }

                        body {
                            margin: 0;
                        }
                    }
                </style>

            </head>

            <body>

                <div class="screen-note">
                    ORIGINAL is printed above its matching RECORD COPY.
                </div>

                <div class="sheet">
                    ${
                        printablePairs
                            .join('')
                    }
                </div>

                <script>
                    window.onload =
                        function () {
                            window.print();
                        };
                <\/script>

            </body>

            </html>
        `)

        printWindow.document.close()

    } catch (err) {
        error.value =
            err.message ||
            'Unable to prepare the QR batch for printing.'
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
    fetchQrCodes()
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
                        v-if="loading"
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
                                    qrCodes.length
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
                                    qrCodes.filter(
                                        qr =>
                                            qr.status ===
                                            'unused'
                                    ).length
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
                                    qrCodes.filter(
                                        qr =>
                                            qr.status ===
                                            'registered'
                                    ).length
                                }}
                            </p>
                        </div>

                    </div>

                    <p
                        v-if="
                            qrCodes.length > 0
                        "
                        class="mt-4 text-xs text-gray-500"
                    >
                        Latest issuance activity:
                        {{
                            formatDateTime(
                                qrCodes[0]
                                    ?.generated_at
                            )
                        }}
                    </p>

                </CardContent>

            </Card>

        </div>

    </div>
</template>
