<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
    Search,
} from 'lucide-vue-next'

import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card'

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'

const route = useRoute()
const router = useRouter()
const isInquiry = computed(() => route.path === '/document-inquiry')

const trackingNumber = ref('')
const document = ref(null)
const searchResults = ref([])
const linkedQrCode = ref('')

const loading = ref(false)
const searchLoading = ref(false)
const error = ref('')

/*
|--------------------------------------------------------------------------
| Fetch Tracking Information
|--------------------------------------------------------------------------
*/

const fetchTracking = async (trackingNo) => {
    if (!trackingNo) {
        return
    }

    loading.value = true
    error.value = ''
    document.value = null

    try {
        const response = await fetch(
            `/api/track/${encodeURIComponent(trackingNo)}`,
            {
                headers: {
                    Accept: 'application/json',
                },
            }
        )

        const data = await response.json()

        if (!response.ok) {
            throw new Error(
                data.message ||
                'Document not found.'
            )
        }

        document.value = data

    } catch (err) {
        error.value =
            err.message ||
            'Unable to retrieve document information.'
    } finally {
        loading.value = false
    }
}

const selectInquiryDocument = async (result) => {
    const trackingNo = String(result?.tracking_no || '').trim()

    if (!trackingNo) {
        error.value = 'The selected document cannot be tracked.'
        return
    }

    linkedQrCode.value = result.qr_code || ''
    trackingNumber.value = result.qr_code || trackingNo
    searchResults.value = []

    await router.replace({
        path: '/document-inquiry',
        query: { tracking: trackingNo },
    })

    await fetchTracking(trackingNo)
}

const searchInquiryDocuments = async () => {
    const value = trackingNumber.value.trim()

    if (!value) {
        error.value = 'Enter or scan a QR code, subject, or document description.'
        return
    }

    searchLoading.value = true
    error.value = ''
    document.value = null
    searchResults.value = []
    linkedQrCode.value = ''

    try {
        const response = await fetch(
            `/api/documents?search=${encodeURIComponent(value)}`,
            {
                headers: {
                    Accept: 'application/json',
                    Authorization: `Bearer ${localStorage.getItem('auth_token') || ''}`,
                },
            }
        )
        const data = await response.json().catch(() => ({}))

        if (!response.ok || !Array.isArray(data.data)) {
            throw new Error(data.message || 'Unable to search documents.')
        }

        searchResults.value = data.data

        if (data.data.length === 1 && data.data[0]?.tracking_no) {
            await selectInquiryDocument(data.data[0])
            return
        }

        if (data.data.length === 0) {
            error.value = 'No document matches that QR code, subject, or description.'
        }
    } catch (err) {
        error.value = err.message || 'Unable to search documents.'
    } finally {
        searchLoading.value = false
    }
}

/*
|--------------------------------------------------------------------------
| Manual Search
|--------------------------------------------------------------------------
*/

const searchDocument = async () => {
    if (isInquiry.value) {
        await searchInquiryDocuments()
        return
    }

    const value =
        trackingNumber.value.trim()

    if (!value) {
        error.value =
            'Please enter a tracking number.'

        return
    }

    /*
    |--------------------------------------------------------------------------
    | Change URL without reloading the page
    |--------------------------------------------------------------------------
    */

    await router.push(
        `/track/${encodeURIComponent(value)}`
    )

    await fetchTracking(value)
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
| Status Style
|--------------------------------------------------------------------------
*/

const statusClass = (status) => {
    switch (
        String(status || '')
            .toLowerCase()
    ) {
        case 'received':
            return 'bg-blue-100 text-blue-700'

        case 'forwarded':
            return 'bg-indigo-100 text-indigo-700'

        case 'pending':
            return 'bg-yellow-100 text-yellow-700'

        case 'approved':
            return 'bg-green-100 text-green-700'

        case 'completed':
            return 'bg-emerald-100 text-emerald-700'

        case 'returned':
            return 'bg-orange-100 text-orange-700'

        case 'cancelled':
            return 'bg-red-100 text-red-700'

        case 'archived':
            return 'bg-slate-100 text-slate-700'

        default:
            return 'bg-gray-100 text-gray-700'
    }
}

/*
|--------------------------------------------------------------------------
| Initialize
|--------------------------------------------------------------------------
*/

onMounted(() => {
    if (isInquiry.value) {
        const trackingNo = String(route.query.tracking || '').trim()

        if (trackingNo) {
            trackingNumber.value = trackingNo
            fetchTracking(trackingNo)
        }

        return
    }

    const trackingNo =
        route.params.trackingNo

    if (trackingNo) {
        trackingNumber.value =
            String(trackingNo)

        fetchTracking(
            String(trackingNo)
        )
    }
})
</script>

<template>
    <div class="min-h-screen bg-slate-100">

        <!-- Header -->
        <div class="border-b border-white/80 bg-white px-6 py-5 shadow-[0_4px_14px_rgb(92_113_138/0.07)]">

            <div class="w-full">

                <h1
                    class="text-2xl font-bold text-gray-900"
                >
                    {{ isInquiry ? 'Document Inquiry / Status' : 'Document Tracking' }}
                </h1>

                <p
                    class="mt-1 text-sm text-gray-500"
                >
                    {{
                        isInquiry
                            ? 'Search by QR code, subject, or document description to view its tracking history.'
                            : "Enter a tracking number to check the document's public status and routing movement."
                    }}
                </p>

            </div>

        </div>

        <!-- Content -->
        <div class="w-full p-6">

            <!-- Search -->
            <Card>

                <CardContent class="pt-6">

                    <form
                        class="flex flex-col gap-3 sm:flex-row"
                        @submit.prevent="searchDocument"
                    >

                        <div class="relative flex-1">
                            <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-500" />
                            <Input
                                v-model="trackingNumber"
                                type="text"
                                :placeholder="isInquiry ? 'Scan QR code or enter subject / description' : 'Tracking number'"
                                class="h-11 pl-10"
                                :disabled="loading || searchLoading"
                            />
                        </div>

                        <Button
                            type="submit"
                            class="h-11 bg-blue-600 px-6 hover:bg-blue-700"
                            :disabled="loading || searchLoading"
                        >
                            <Search class="mr-2 h-4 w-4" />
                            {{
                                loading || searchLoading
                                    ? 'Searching...'
                                    : 'Track Document'
                            }}
                        </Button>

                    </form>

                </CardContent>

            </Card>

            <Card v-if="isInquiry && searchResults.length > 0" class="mt-6">
                <CardHeader>
                    <CardTitle>Matching Documents</CardTitle>
                    <p class="text-sm text-gray-500">Choose a document to view its tracking history.</p>
                </CardHeader>
                <CardContent class="space-y-2">
                    <button
                        v-for="result in searchResults"
                        :key="result.id"
                        type="button"
                        class="w-full rounded-lg border border-slate-200 p-4 text-left transition-colors hover:border-blue-400 hover:bg-blue-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
                        @click="selectInquiryDocument(result)"
                    >
                        <p class="font-semibold text-slate-900">{{ result.title || 'Untitled document' }}</p>
                        <p class="mt-1 font-mono text-sm text-blue-700">{{ result.qr_code || result.tracking_no }}</p>
                    </button>
                </CardContent>
            </Card>

            <!-- Loading -->
            <div
                v-if="loading"
                class="py-16 text-center text-gray-500"
            >
                Retrieving document information...
            </div>

            <!-- Error -->
            <div
                v-else-if="error"
                class="mt-6 rounded-lg border border-red-200 bg-red-50 p-5 text-center text-red-700"
            >
                {{ error }}
            </div>

            <!-- Document -->
            <template v-else-if="document">

                <!-- Main Information -->
                <Card class="mt-6">

                    <CardHeader>

                        <div
                            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
                        >

                            <div>

                                <p
                                    class="text-sm font-semibold text-blue-600"
                                >
                                    {{
                                        isInquiry
                                            ? linkedQrCode || document.tracking_no
                                            : document.tracking_no
                                    }}
                                </p>

                                <CardTitle class="mt-2 text-2xl">
                                    {{ document.title }}
                                </CardTitle>

                                <p
                                    v-if="document.is_protected"
                                    class="mt-2 text-sm text-orange-600"
                                >
                                    Limited public details are shown for this protected document.
                                </p>

                            </div>

                            <span
                                class="inline-flex w-fit rounded-full px-4 py-2 text-sm font-semibold"
                                :class="
                                    statusClass(
                                        document.status
                                    )
                                "
                            >
                                {{ document.status || 'N/A' }}
                            </span>

                        </div>

                    </CardHeader>

                    <CardContent>

                        <div
                            class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3"
                        >

                            <div>
                                <p
                                    class="text-xs font-semibold uppercase text-gray-500"
                                >
                                    Document Type
                                </p>

                                <p class="mt-1 font-medium">
                                    {{
                                        document.document_type
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

                                <p class="mt-1 font-medium">
                                    {{
                                        document.priority
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

                                <p class="mt-1 font-medium">
                                    {{
                                        document.confidentiality
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

                                <p class="mt-1 font-medium">
                                    {{
                                        document.origin_office
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

                                <p class="mt-1">
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

                                <p class="mt-1">
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
                                    Registered
                                </p>

                                <p class="mt-1">
                                    {{
                                        formatDate(
                                            document.registered_at
                                        )
                                    }}
                                </p>
                            </div>

                        </div>

                        <!-- Details -->
                        <div
                            v-if="
                                document.details &&
                                !document.is_protected
                            "
                            class="mt-6 border-t pt-5"
                        >

                            <p
                                class="text-xs font-semibold uppercase text-gray-500"
                            >
                                Document Details
                            </p>

                            <p
                                class="mt-2 whitespace-pre-line text-gray-800"
                            >
                                {{ document.details }}
                            </p>

                        </div>

                    </CardContent>

                </Card>

                <!-- Movement History -->
                <Card class="mt-6">

                    <CardHeader>

                        <CardTitle>
                            Tracking History
                        </CardTitle>

                        <p
                            class="text-sm text-gray-500"
                        >
                            Offices through which this document
                            has been routed.
                        </p>

                    </CardHeader>

                    <CardContent>

                        <div
                            v-if="
                                !document.movement_history ||
                                document.movement_history.length === 0
                            "
                            class="py-8 text-center text-gray-500"
                        >
                            No routing movement has been recorded yet.
                        </div>

                        <div
                            v-else
                            class="space-y-4"
                        >

                            <div
                                v-for="
                                    (
                                        movement,
                                        index
                                    ) in
                                    document.movement_history
                                "
                                :key="movement.id"
                                class="rounded-lg border bg-white p-5"
                            >

                                <div
                                    class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                                >

                                    <div>

                                        <p
                                            class="font-semibold text-gray-900"
                                        >
                                            {{
                                                movement.from_office
                                                || 'N/A'
                                            }}

                                            <span
                                                class="mx-2 text-gray-400"
                                            >
                                                →
                                            </span>

                                            {{
                                                movement.to_office
                                                || 'N/A'
                                            }}
                                        </p>

                                        <p
                                            class="mt-1 text-xs text-gray-500"
                                        >
                                            Movement {{ index + 1 }}
                                        </p>

                                    </div>

                                    <span
                                        class="inline-flex w-fit rounded-full px-3 py-1 text-xs font-semibold"
                                        :class="
                                            statusClass(
                                                movement.status
                                            )
                                        "
                                    >
                                        {{
                                            movement.status
                                            || 'N/A'
                                        }}
                                    </span>

                                </div>

                                <div
                                    class="mt-4 grid grid-cols-1 gap-4 border-t pt-4 sm:grid-cols-2"
                                >

                                    <div>

                                        <p
                                            class="text-xs font-semibold uppercase text-gray-500"
                                        >
                                            Forwarded
                                        </p>

                                        <p
                                            class="mt-1 text-sm text-gray-700"
                                        >
                                            {{
                                                formatDate(
                                                    movement.forwarded_at
                                                )
                                            }}
                                        </p>

                                    </div>

                                    <div>

                                        <p
                                            class="text-xs font-semibold uppercase text-gray-500"
                                        >
                                            Received
                                        </p>

                                        <p
                                            v-if="
                                                movement.received_at
                                            "
                                            class="mt-1 text-sm text-gray-700"
                                        >
                                            {{
                                                formatDate(
                                                    movement.received_at
                                                )
                                            }}
                                        </p>

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

                <!-- Privacy Note -->
                <div
                    class="mt-6 text-center text-xs text-gray-500"
                >
                    This public page shows tracking status only.
                    Internal notes and attachments are not displayed.
                </div>

            </template>

        </div>

    </div>
</template>
