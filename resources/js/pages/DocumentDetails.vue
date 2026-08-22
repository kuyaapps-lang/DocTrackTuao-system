<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import QRCode from 'qrcode'

import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card'

import { Button } from '@/components/ui/button'

const route = useRoute()
const router = useRouter()

/*
|--------------------------------------------------------------------------
| Main Data
|--------------------------------------------------------------------------
*/

const document = ref(null)
const routingOptions = ref(null)
const history = ref([])

const loading = ref(true)
const actionLoading = ref(false)

const error = ref('')
const successMessage = ref('')

/*
|--------------------------------------------------------------------------
| QR Code
|--------------------------------------------------------------------------
*/

const qrDataUrl = ref('')
const qrError = ref('')

/*
|--------------------------------------------------------------------------
| Forward Modal
|--------------------------------------------------------------------------
*/

const showForwardModal = ref(false)

const forwardForm = ref({
    to_office_id: '',
    remarks: '',
})

const forwardError = ref('')

/*
|--------------------------------------------------------------------------
| Receive Modal
|--------------------------------------------------------------------------
*/

const showReceiveModal = ref(false)
const receiveError = ref('')

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
| Fetch Document
|--------------------------------------------------------------------------
*/

const fetchDocument = async () => {
    try {
        const response = await fetch(
            `/api/documents/${route.params.id}`,
            {
                headers: {
                    Accept: 'application/json',
                    Authorization: `Bearer ${getToken()}`,
                },
            }
        )

        const data = await response.json()

        if (!response.ok) {
            throw new Error(
                data.message ||
                'Unable to load document.'
            )
        }

        document.value = data

        await generateQRCode()

    } catch (err) {
        throw new Error(
            err.message ||
            'Unable to load document.'
        )
    }
}

/*
|--------------------------------------------------------------------------
| Generate QR Code
|--------------------------------------------------------------------------
*/

const generateQRCode = async () => {
    qrError.value = ''
    qrDataUrl.value = ''

    if (!document.value) {
        return
    }

    try {
        /*
        |--------------------------------------------------------------------------
        | Current QR destination
        |--------------------------------------------------------------------------
        |
        | For now QR opens the authenticated Document Details page.
        |
        | Later during Document Status / Inquiry sprint,
        | this can be changed to:
        |
        | /track/{tracking_no}
        |
        */

        const documentUrl =
    `${window.location.origin}/track/${encodeURIComponent(
        document.value.tracking_no
    )}`
    
        qrDataUrl.value =
            await QRCode.toDataURL(
                documentUrl,
                {
                    width: 300,
                    margin: 2,
                    errorCorrectionLevel: 'H',
                }
            )

    } catch (err) {
        qrError.value =
            'Unable to generate QR code.'
    }
}

/*
|--------------------------------------------------------------------------
| Print QR
|--------------------------------------------------------------------------
*/

const printQRCode = () => {
    if (
        !qrDataUrl.value ||
        !document.value
    ) {
        return
    }

    const printWindow =
        window.open(
            '',
            '_blank',
            'width=600,height=700'
        )

    if (!printWindow) {
        qrError.value =
            'Unable to open print window. Please allow pop-ups.'
        return
    }

    const trackingNo =
        document.value.tracking_no || ''

    const title =
        document.value.title || ''

    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Document QR - ${trackingNo}</title>

            <style>
                body {
                    font-family: Arial, sans-serif;
                    text-align: center;
                    padding: 40px;
                }

                .card {
                    max-width: 420px;
                    margin: auto;
                    border: 1px solid #d1d5db;
                    border-radius: 12px;
                    padding: 30px;
                }

                img {
                    width: 280px;
                    height: 280px;
                }

                h2 {
                    margin-bottom: 5px;
                }

                .tracking {
                    font-size: 18px;
                    font-weight: bold;
                    margin-top: 15px;
                }

                .title {
                    margin-top: 8px;
                    color: #4b5563;
                }

                .instruction {
                    margin-top: 18px;
                    font-size: 13px;
                    color: #6b7280;
                }
            </style>
        </head>

        <body>

            <div class="card">

                <h2>
                    LGU Tuao Document Tracking
                </h2>

                <img
                    src="${qrDataUrl.value}"
                    alt="Document QR Code"
                >

                <div class="tracking">
                    ${trackingNo}
                </div>

                <div class="title">
                    ${title}
                </div>

                <div class="instruction">
                    Scan this QR code to view the document record.
                </div>

            </div>

            <script>
                window.onload = function () {
                    window.print();
                };
            <\/script>

        </body>
        </html>
    `)

    printWindow.document.close()
}

/*
|--------------------------------------------------------------------------
| Fetch Routing Options
|--------------------------------------------------------------------------
*/

const fetchRoutingOptions = async () => {
    try {
        const response = await fetch(
            `/api/documents/${route.params.id}/routing-options`,
            {
                headers: {
                    Accept: 'application/json',
                    Authorization: `Bearer ${getToken()}`,
                },
            }
        )

        const data = await response.json()

        if (!response.ok) {
            throw new Error(
                data.message ||
                'Unable to load routing information.'
            )
        }

        routingOptions.value = data

    } catch (err) {
        throw new Error(
            err.message ||
            'Unable to load routing information.'
        )
    }
}

/*
|--------------------------------------------------------------------------
| Fetch Movement History
|--------------------------------------------------------------------------
*/

const fetchHistory = async () => {
    try {
        const response = await fetch(
            `/api/documents/${route.params.id}/history`,
            {
                headers: {
                    Accept: 'application/json',
                    Authorization: `Bearer ${getToken()}`,
                },
            }
        )

        const data = await response.json()

        if (!response.ok) {
            throw new Error(
                data.message ||
                'Unable to load document history.'
            )
        }

        history.value =
            Array.isArray(data)
                ? data
                : []

    } catch (err) {
        throw new Error(
            err.message ||
            'Unable to load document history.'
        )
    }
}

/*
|--------------------------------------------------------------------------
| Load Page
|--------------------------------------------------------------------------
*/

const loadPage = async () => {
    loading.value = true
    error.value = ''

    try {
        await Promise.all([
            fetchDocument(),
            fetchRoutingOptions(),
            fetchHistory(),
        ])

    } catch (err) {
        error.value =
            err.message ||
            'Unable to load document.'

    } finally {
        loading.value = false
    }
}

/*
|--------------------------------------------------------------------------
| Pending Route
|--------------------------------------------------------------------------
*/

const pendingRoute = computed(() => {
    if (!history.value.length) {
        return null
    }

    return (
        [...history.value]
            .reverse()
            .find(
                item =>
                    !item.received_at
            ) || null
    )
})

/*
|--------------------------------------------------------------------------
| Permissions
|--------------------------------------------------------------------------
*/

const canReceive = computed(() => {
    if (
        !pendingRoute.value ||
        !routingOptions.value?.user
    ) {
        return false
    }

    return (
        Number(
            pendingRoute.value.to_office_id
        ) ===
        Number(
            routingOptions.value.user.office_id
        )
    )
})

const canForward = computed(() => {
    if (
        !routingOptions.value?.can_act
    ) {
        return false
    }

    /*
    |--------------------------------------------------------------------------
    | Cannot forward again until current route is received.
    |--------------------------------------------------------------------------
    */

    return pendingRoute.value === null
})

/*
|--------------------------------------------------------------------------
| Forward Modal
|--------------------------------------------------------------------------
*/

const openForwardModal = () => {
    forwardForm.value = {
        to_office_id: '',
        remarks: '',
    }

    forwardError.value = ''
    successMessage.value = ''

    showForwardModal.value = true
}

const closeForwardModal = () => {
    if (actionLoading.value) {
        return
    }

    showForwardModal.value = false
    forwardError.value = ''
}

/*
|--------------------------------------------------------------------------
| Forward Document
|--------------------------------------------------------------------------
*/

const forwardDocument = async () => {
    forwardError.value = ''
    successMessage.value = ''

    if (
        !forwardForm.value.to_office_id
    ) {
        forwardError.value =
            'Please select a destination office.'

        return
    }

    actionLoading.value = true

    try {
        const response = await fetch(
            `/api/documents/${route.params.id}/forward`,
            {
                method: 'POST',

                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    Authorization: `Bearer ${getToken()}`,
                },

                body: JSON.stringify({
                    to_office_id:
                        Number(
                            forwardForm.value
                                .to_office_id
                        ),

                    remarks:
                        forwardForm.value
                            .remarks
                            .trim() ||
                        null,
                }),
            }
        )

        const data = await response.json()

        if (!response.ok) {
            if (data.errors) {
                const firstError =
                    Object.values(
                        data.errors
                    )[0]

                throw new Error(
                    Array.isArray(firstError)
                        ? firstError[0]
                        : firstError
                )
            }

            throw new Error(
                data.message ||
                'Unable to forward document.'
            )
        }

        showForwardModal.value = false

        successMessage.value =
            'Document forwarded successfully.'

        await loadPage()

    } catch (err) {
        forwardError.value =
            err.message ||
            'Unable to forward document.'

    } finally {
        actionLoading.value = false
    }
}

/*
|--------------------------------------------------------------------------
| Receive Modal
|--------------------------------------------------------------------------
*/

const openReceiveModal = () => {
    receiveError.value = ''
    successMessage.value = ''

    showReceiveModal.value = true
}

const closeReceiveModal = () => {
    if (actionLoading.value) {
        return
    }

    showReceiveModal.value = false
    receiveError.value = ''
}

/*
|--------------------------------------------------------------------------
| Receive Document
|--------------------------------------------------------------------------
*/

const receiveDocument = async () => {
    receiveError.value = ''
    successMessage.value = ''

    actionLoading.value = true

    try {
        const response = await fetch(
            `/api/documents/${route.params.id}/receive`,
            {
                method: 'POST',

                headers: {
                    Accept: 'application/json',
                    Authorization: `Bearer ${getToken()}`,
                },
            }
        )

        const data = await response.json()

        if (!response.ok) {
            throw new Error(
                data.message ||
                'Unable to receive document.'
            )
        }

        showReceiveModal.value = false

        successMessage.value =
            'Document received successfully.'

        await loadPage()

    } catch (err) {
        receiveError.value =
            err.message ||
            'Unable to receive document.'

    } finally {
        actionLoading.value = false
    }
}

/*
|--------------------------------------------------------------------------
| Navigation
|--------------------------------------------------------------------------
*/

const goBack = () => {
    router.push('/documents')
}

/*
|--------------------------------------------------------------------------
| Formatting
|--------------------------------------------------------------------------
*/

const formatDate = (date) => {
    if (!date) {
        return 'N/A'
    }

    return new Date(
        date
    ).toLocaleString()
}

const formatSimpleDate = (date) => {
    if (!date) {
        return 'N/A'
    }

    return new Date(
        `${date}T00:00:00`
    ).toLocaleDateString()
}

/*
|--------------------------------------------------------------------------
| Initialize
|--------------------------------------------------------------------------
*/

onMounted(() => {
    loadPage()
})
</script>

<template>
    <div class="min-h-screen bg-gray-100">

        <!-- Header -->
        <div class="bg-white border-b px-6 py-4">

            <div
                class="max-w-6xl mx-auto flex items-center justify-between"
            >

                <div>

                    <h1
                        class="text-2xl font-bold text-gray-800"
                    >
                        Document Details
                    </h1>

                    <p
                        class="text-sm text-gray-500 mt-1"
                    >
                        Document information, QR code,
                        routing and movement history
                    </p>

                </div>

                <Button
                    variant="outline"
                    @click="goBack"
                >
                    ← Back to Documents
                </Button>

            </div>

        </div>

        <!-- Main Content -->
        <div class="max-w-6xl mx-auto p-6">

            <!-- Loading -->
            <div
                v-if="loading"
                class="py-12 text-center text-gray-500"
            >
                Loading document...
            </div>

            <!-- Error -->
            <div
                v-else-if="error"
                class="rounded-md border border-red-200 bg-red-50 p-4 text-red-700"
            >
                {{ error }}
            </div>

            <template v-else-if="document">

                <!-- Success -->
                <div
                    v-if="successMessage"
                    class="mb-5 rounded-md border border-green-200 bg-green-50 p-4 text-sm font-semibold text-green-700"
                >
                    {{ successMessage }}
                </div>

                <!-- Document Information -->
                <Card>

                    <CardHeader>

                        <div
                            class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between"
                        >

                            <div>

                                <CardTitle
                                    class="text-2xl"
                                >
                                    {{ document.title }}
                                </CardTitle>

                                <p
                                    class="mt-1 text-sm font-medium text-gray-500"
                                >
                                    {{ document.tracking_no }}
                                </p>

                            </div>

                            <!-- Actions -->
                            <div
                                class="flex flex-wrap gap-2"
                            >

                                <Button
                                    v-if="canReceive"
                                    class="bg-green-600 text-white hover:bg-green-700"
                                    @click="openReceiveModal"
                                >
                                    Receive Document
                                </Button>

                                <Button
                                    v-if="canForward"
                                    class="bg-blue-600 text-white hover:bg-blue-700"
                                    @click="openForwardModal"
                                >
                                    Forward Document
                                </Button>

                            </div>

                        </div>

                    </CardHeader>

                    <CardContent>

                        <div
                            class="grid grid-cols-1 gap-8 lg:grid-cols-4"
                        >

                            <!-- Document Metadata -->
                            <div
                                class="lg:col-span-3"
                            >

                                <div
                                    class="grid grid-cols-1 gap-5 md:grid-cols-2 lg:grid-cols-3"
                                >

                                    <div>
                                        <p
                                            class="text-xs font-semibold uppercase text-gray-500"
                                        >
                                            Document Type
                                        </p>

                                        <p
                                            class="mt-1 font-medium text-gray-900"
                                        >
                                            {{
                                                document.type
                                                    ?.type_name
                                                || 'N/A'
                                            }}
                                        </p>
                                    </div>

                                    <div>
                                        <p
                                            class="text-xs font-semibold uppercase text-gray-500"
                                        >
                                            Status
                                        </p>

                                        <p
                                            class="mt-1 font-semibold text-gray-900"
                                        >
                                            {{
                                                document.status
                                                    ?.status_name
                                                || 'N/A'
                                            }}
                                        </p>
                                    </div>

                                    <div>
                                        <p
                                            class="text-xs font-semibold uppercase text-gray-500"
                                        >
                                            Priority
                                        </p>

                                        <p
                                            class="mt-1 font-medium text-gray-900"
                                        >
                                            {{
                                                document.priority
                                                    ?.priority_name
                                                || 'N/A'
                                            }}
                                        </p>
                                    </div>

                                    <div>
                                        <p
                                            class="text-xs font-semibold uppercase text-gray-500"
                                        >
                                            Confidentiality
                                        </p>

                                        <p
                                            class="mt-1 font-medium text-gray-900"
                                        >
                                            {{
                                                document.confidentiality
                                                    ?.level_name
                                                || 'N/A'
                                            }}
                                        </p>
                                    </div>

                                    <div>
                                        <p
                                            class="text-xs font-semibold uppercase text-gray-500"
                                        >
                                            Origin Office
                                        </p>

                                        <p
                                            class="mt-1 font-medium text-gray-900"
                                        >
                                            {{
                                                document.origin_office
                                                    ?.office_name
                                                || 'N/A'
                                            }}
                                        </p>
                                    </div>

                                    <div>
                                        <p
                                            class="text-xs font-semibold uppercase text-gray-500"
                                        >
                                            Current Office
                                        </p>

                                        <p
                                            class="mt-1 font-semibold text-blue-700"
                                        >
                                            {{
                                                document.current_office
                                                    ?.office_name
                                                || 'N/A'
                                            }}
                                        </p>
                                    </div>

                                    <div>
                                        <p
                                            class="text-xs font-semibold uppercase text-gray-500"
                                        >
                                            Document Date
                                        </p>

                                        <p
                                            class="mt-1 text-gray-900"
                                        >
                                            {{
                                                formatSimpleDate(
                                                    document.document_date
                                                )
                                            }}
                                        </p>
                                    </div>

                                    <div>
                                        <p
                                            class="text-xs font-semibold uppercase text-gray-500"
                                        >
                                            Due Date
                                        </p>

                                        <p
                                            class="mt-1 text-gray-900"
                                        >
                                            {{
                                                formatSimpleDate(
                                                    document.due_date
                                                )
                                            }}
                                        </p>
                                    </div>

                                    <div>
                                        <p
                                            class="text-xs font-semibold uppercase text-gray-500"
                                        >
                                            Registered By
                                        </p>

                                        <p
                                            class="mt-1 text-gray-900"
                                        >
                                            {{
                                                document.creator
                                                    ?.name
                                                || 'N/A'
                                            }}
                                        </p>
                                    </div>

                                </div>

                                <!-- Description -->
                                <div
                                    class="mt-7 border-t pt-5"
                                >

                                    <p
                                        class="text-xs font-semibold uppercase text-gray-500"
                                    >
                                        Document Details
                                    </p>

                                    <p
                                        class="mt-2 whitespace-pre-line text-gray-800"
                                    >
                                        {{
                                            document.description
                                            || 'No description provided.'
                                        }}
                                    </p>

                                </div>

                            </div>

                            <!-- QR Card -->
                            <div
                                class="rounded-xl border bg-gray-50 p-5 text-center"
                            >

                                <h3
                                    class="font-bold text-gray-900"
                                >
                                    Document QR
                                </h3>

                                <p
                                    class="mt-1 text-xs text-gray-500"
                                >
                                    Scan to open this document
                                </p>

                                <div
                                    v-if="qrDataUrl"
                                    class="mt-4"
                                >

                                    <img
                                        :src="qrDataUrl"
                                        alt="Document QR Code"
                                        class="mx-auto h-48 w-48 rounded-md bg-white p-2"
                                    >

                                    <p
                                        class="mt-3 break-all text-xs font-semibold text-gray-700"
                                    >
                                        {{ document.tracking_no }}
                                    </p>

                                    <Button
                                        class="mt-4 w-full bg-gray-900 text-white hover:bg-black"
                                        @click="printQRCode"
                                    >
                                        Print QR
                                    </Button>

                                </div>

                                <div
                                    v-else-if="qrError"
                                    class="mt-4 text-sm text-red-600"
                                >
                                    {{ qrError }}
                                </div>

                                <div
                                    v-else
                                    class="mt-4 text-sm text-gray-500"
                                >
                                    Generating QR...
                                </div>

                            </div>

                        </div>

                    </CardContent>

                </Card>

                <!-- Movement History -->
                <Card class="mt-6">

                    <CardHeader>

                        <CardTitle>
                            Movement History
                        </CardTitle>

                    </CardHeader>

                    <CardContent>

                        <div
                            v-if="history.length === 0"
                            class="py-8 text-center text-gray-500"
                        >
                            This document has not been routed yet.
                        </div>

                        <div
                            v-else
                            class="space-y-5"
                        >

                            <div
                                v-for="item in history"
                                :key="item.id"
                                class="rounded-lg border bg-white p-5"
                            >

                                <!-- Route -->
                                <div
                                    class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between"
                                >

                                    <div>

                                        <p
                                            class="font-semibold text-gray-900"
                                        >
                                            {{
                                                item.from_office
                                                    ?.office_name
                                                || 'Unknown Office'
                                            }}

                                            <span
                                                class="mx-2 text-gray-400"
                                            >
                                                →
                                            </span>

                                            {{
                                                item.to_office
                                                    ?.office_name
                                                || 'Unknown Office'
                                            }}
                                        </p>

                                        <p
                                            v-if="item.remarks"
                                            class="mt-1 text-sm text-gray-600"
                                        >
                                            {{ item.remarks }}
                                        </p>

                                    </div>

                                    <span
                                        class="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-700"
                                    >
                                        {{
                                            item.status
                                                ?.status_name
                                            || 'N/A'
                                        }}
                                    </span>

                                </div>

                                <div
                                    class="mt-4 grid grid-cols-1 gap-4 border-t pt-4 md:grid-cols-2"
                                >

                                    <!-- Forwarded -->
                                    <div>

                                        <p
                                            class="text-xs font-semibold uppercase text-gray-500"
                                        >
                                            Forwarded
                                        </p>

                                        <p
                                            class="mt-1 text-sm text-gray-800"
                                        >
                                            By:
                                            {{
                                                item.forwarded_by
                                                    ?.name
                                                || 'N/A'
                                            }}
                                        </p>

                                        <p
                                            class="text-sm text-gray-500"
                                        >
                                            {{
                                                formatDate(
                                                    item.forwarded_at
                                                )
                                            }}
                                        </p>

                                    </div>

                                    <!-- Received -->
                                    <div>

                                        <p
                                            class="text-xs font-semibold uppercase text-gray-500"
                                        >
                                            Received
                                        </p>

                                        <template
                                            v-if="item.received_at"
                                        >

                                            <p
                                                class="mt-1 text-sm text-gray-800"
                                            >
                                                By:
                                                {{
                                                    item.received_by
                                                        ?.name
                                                    || 'N/A'
                                                }}
                                            </p>

                                            <p
                                                class="text-sm text-gray-500"
                                            >
                                                {{
                                                    formatDate(
                                                        item.received_at
                                                    )
                                                }}
                                            </p>

                                        </template>

                                        <p
                                            v-else
                                            class="mt-1 text-sm font-medium text-yellow-600"
                                        >
                                            Awaiting receipt
                                        </p>

                                    </div>

                                </div>

                            </div>

                        </div>

                    </CardContent>

                </Card>

            </template>

        </div>

        <!-- Forward Modal -->
        <div
            v-if="showForwardModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4"
        >

            <div
                class="w-full max-w-lg rounded-xl bg-white p-6 shadow-2xl"
            >

                <h2
                    class="text-xl font-bold text-gray-900"
                >
                    Forward Document
                </h2>

                <p
                    class="mt-1 text-sm text-gray-500"
                >
                    Select the office that will receive this document.
                </p>

                <!-- Destination -->
                <div class="mt-5">

                    <label
                        class="mb-2 block text-sm font-semibold text-gray-700"
                    >
                        Destination Office *
                    </label>

                    <select
                        v-model="forwardForm.to_office_id"
                        class="h-11 w-full rounded-md border border-gray-300 bg-white px-3 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                    >

                        <option value="">
                            Select Destination Office
                        </option>

                        <option
                            v-for="office in routingOptions?.offices || []"
                            :key="office.id"
                            :value="office.id"
                        >
                            {{ office.office_name }}
                            ({{ office.office_code }})
                        </option>

                    </select>

                </div>

                <!-- Remarks -->
                <div class="mt-4">

                    <label
                        class="mb-2 block text-sm font-semibold text-gray-700"
                    >
                        Remarks
                    </label>

                    <textarea
                        v-model="forwardForm.remarks"
                        rows="4"
                        placeholder="Optional routing remarks"
                        class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                    ></textarea>

                </div>

                <!-- Error -->
                <div
                    v-if="forwardError"
                    class="mt-4 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-600"
                >
                    {{ forwardError }}
                </div>

                <!-- Buttons -->
                <div
                    class="mt-6 flex justify-end gap-3"
                >

                    <button
                        type="button"
                        class="rounded-md bg-black px-5 py-2 text-sm font-semibold text-white hover:bg-gray-800 disabled:opacity-50"
                        :disabled="actionLoading"
                        @click="closeForwardModal"
                    >
                        Cancel
                    </button>

                    <button
                        type="button"
                        class="rounded-md bg-blue-600 px-5 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50"
                        :disabled="actionLoading"
                        @click="forwardDocument"
                    >
                        {{
                            actionLoading
                                ? 'Forwarding...'
                                : 'Forward'
                        }}
                    </button>

                </div>

            </div>

        </div>

        <!-- Receive Modal -->
        <div
            v-if="showReceiveModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4"
        >

            <div
                class="w-full max-w-md rounded-xl bg-white p-6 shadow-2xl"
            >

                <h2
                    class="text-xl font-bold text-gray-900"
                >
                    Receive Document
                </h2>

                <p
                    class="mt-3 text-sm text-gray-600"
                >
                    Confirm that your office has physically received this document.
                </p>

                <div
                    v-if="pendingRoute"
                    class="mt-4 rounded-lg bg-gray-50 p-4 text-sm"
                >

                    <p>

                        <span class="font-semibold">
                            From:
                        </span>

                        {{
                            pendingRoute.from_office
                                ?.office_name
                            || 'N/A'
                        }}

                    </p>

                    <p class="mt-1">

                        <span class="font-semibold">
                            To:
                        </span>

                        {{
                            pendingRoute.to_office
                                ?.office_name
                            || 'N/A'
                        }}

                    </p>

                </div>

                <div
                    v-if="receiveError"
                    class="mt-4 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-600"
                >
                    {{ receiveError }}
                </div>

                <div
                    class="mt-6 flex justify-end gap-3"
                >

                    <button
                        type="button"
                        class="rounded-md bg-black px-5 py-2 text-sm font-semibold text-white hover:bg-gray-800 disabled:opacity-50"
                        :disabled="actionLoading"
                        @click="closeReceiveModal"
                    >
                        Cancel
                    </button>

                    <button
                        type="button"
                        class="rounded-md bg-green-600 px-5 py-2 text-sm font-semibold text-white hover:bg-green-700 disabled:opacity-50"
                        :disabled="actionLoading"
                        @click="receiveDocument"
                    >
                        {{
                            actionLoading
                                ? 'Receiving...'
                                : 'Yes, Receive'
                        }}
                    </button>

                </div>

            </div>

        </div>

    </div>
</template>