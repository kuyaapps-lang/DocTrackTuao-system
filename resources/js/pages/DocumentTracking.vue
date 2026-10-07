<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
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
import CameraQrScanner from '@/components/CameraQrScanner.vue'

const route = useRoute()
const router = useRouter()
const isInquiry = computed(() => route.path === '/document-inquiry')

const trackingNumber = ref('')
const document = ref(null)
const searchResults = ref([])
const linkedQrCode = ref('')
const inquiryMeta = ref({ total: 0 })
const inquiryLoadingMore = ref(false)
const inquirySentinel = ref(null)

const loading = ref(false)
const searchLoading = ref(false)
const error = ref('')
let inquiryObserver = null

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

    await router.replace({
        path: '/document-inquiry',
        query: { tracking: trackingNo },
    })

    await fetchTracking(trackingNo)
}

const closeInquiryDocument = async () => {
    document.value = null
    linkedQrCode.value = ''
    error.value = ''

    await router.replace({
        path: '/document-inquiry',
        query: {},
    })
}

const searchInquiryDocuments = async () => {
    const value = trackingNumber.value.trim()

    searchLoading.value = true
    error.value = ''
    document.value = null
    searchResults.value = []
    linkedQrCode.value = ''

    try {
        const response = await fetch(
            `/api/documents?per_page=50&sort=received_desc${value ? `&search=${encodeURIComponent(value)}` : ''}`,
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
        inquiryMeta.value = data.meta || { total: data.data.length }

        if (data.data.length === 0) {
            error.value = value
                ? 'No accessible document matches that QR code, subject, or description.'
                : 'No accessible documents are available yet.'
        }
    } catch (err) {
        error.value = err.message || 'Unable to search documents.'
    } finally {
        searchLoading.value = false
    }
}

const canLoadMoreInquiry = computed(() => searchResults.value.length < inquiryMeta.value.total)

const loadMoreInquiryDocuments = async () => {
    if (!isInquiry.value || searchLoading.value || inquiryLoadingMore.value || !canLoadMoreInquiry.value) return

    inquiryLoadingMore.value = true
    try {
        const value = trackingNumber.value.trim()
        const page = Math.floor(searchResults.value.length / 10) + 1
        const response = await fetch(
            `/api/documents?per_page=10&page=${page}&sort=received_desc${value ? `&search=${encodeURIComponent(value)}` : ''}`,
            { headers: { Accept: 'application/json', Authorization: `Bearer ${localStorage.getItem('auth_token') || ''}` } }
        )
        const data = await response.json().catch(() => ({}))
        if (!response.ok || !Array.isArray(data.data)) throw new Error(data.message || 'Unable to load more documents.')

        const seen = new Set(searchResults.value.map(result => result.id))
        searchResults.value.push(...data.data.filter(result => !seen.has(result.id)))
        inquiryMeta.value = data.meta || inquiryMeta.value
    } catch (err) {
        error.value = err.message || 'Unable to load more documents.'
    } finally {
        inquiryLoadingMore.value = false
    }
}

const observeInquiryScroll = () => {
    if (!inquirySentinel.value || typeof IntersectionObserver === 'undefined') return

    inquiryObserver = new IntersectionObserver(entries => {
        if (entries.some(entry => entry.isIntersecting)) loadMoreInquiryDocuments()
    }, { rootMargin: '240px' })
    inquiryObserver.observe(inquirySentinel.value)
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

const scanInquiryCamera = async token => {
    trackingNumber.value = token
    await searchInquiryDocuments()
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

onMounted(async () => {
    if (isInquiry.value) {
        const trackingNo = String(route.query.tracking || '').trim()

        await searchInquiryDocuments()
        observeInquiryScroll()

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

onBeforeUnmount(() => inquiryObserver?.disconnect())
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

                        <CameraQrScanner
                            v-if="isInquiry"
                            :disabled="loading || searchLoading"
                            @scan="scanInquiryCamera"
                        />

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

            <Card v-if="isInquiry" class="mt-6">
                <CardHeader>
                    <CardTitle>Accessible Documents</CardTitle>
                    <p class="text-sm text-gray-500">Documents created by, received by, or publicly visible to your office. Select one to inquire about its status.</p>
                </CardHeader>
                <CardContent>
                    <p v-if="searchLoading" class="py-6 text-center text-sm text-gray-500">Loading documents...</p>
                    <p v-else-if="searchResults.length === 0" class="py-6 text-center text-sm text-gray-500">No accessible documents match the current search.</p>
                    <div v-else class="overflow-x-auto rounded-lg border border-slate-200">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-blue-900 text-left text-xs font-semibold uppercase tracking-wide text-white">
                                <tr>
                                    <th scope="col" class="px-4 py-3 text-white">Code</th>
                                    <th scope="col" class="px-4 py-3 text-white">Title/Description</th>
                                    <th scope="col" class="px-4 py-3 text-white">Current Office</th>
                                    <th scope="col" class="px-4 py-3 text-white">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 bg-white">
                                <tr
                                    v-for="result in searchResults"
                                    :key="result.id"
                                    tabindex="0"
                                    class="cursor-pointer transition-colors hover:bg-blue-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-500"
                                    @click="selectInquiryDocument(result)"
                                    @keydown.enter="selectInquiryDocument(result)"
                                    @keydown.space.prevent="selectInquiryDocument(result)"
                                >
                                    <td class="whitespace-nowrap px-4 py-3 font-mono font-medium text-blue-700">{{ result.qr_code || result.tracking_no || 'N/A' }}</td>
                                    <td class="px-4 py-3 text-slate-900">{{ result.title || result.description || 'Untitled document' }}</td>
                                    <td class="px-4 py-3 text-slate-600">{{ result.current_office?.office_name || 'N/A' }}</td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex whitespace-nowrap rounded-full px-3 py-1 text-xs font-semibold" :class="statusClass(result.status?.status_name)">
                                            {{ result.status?.status_name || 'N/A' }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div ref="inquirySentinel" class="h-1" aria-hidden="true" />
                    <p v-if="inquiryLoadingMore" class="py-3 text-center text-sm text-slate-500">Loading 10 more documents...</p>
                    <p v-else-if="searchResults.length > 0 && !canLoadMoreInquiry" class="py-3 text-center text-sm text-slate-500">All accessible documents are loaded.</p>
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
                v-else-if="error && !isInquiry"
                class="mt-6 rounded-lg border border-red-200 bg-red-50 p-5 text-center text-red-700"
            >
                {{ error }}
            </div>

            <!-- Document -->
            <template v-else-if="document && !isInquiry">

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

            <div
                v-if="isInquiry && document"
                class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4"
                @click.self="closeInquiryDocument"
            >
                <section
                    class="max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="inquiry-document-title"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="font-mono text-sm font-semibold text-blue-700">{{ linkedQrCode || document.tracking_no }}</p>
                            <h2 id="inquiry-document-title" class="mt-2 text-xl font-bold text-slate-900">{{ document.title }}</h2>
                        </div>
                        <span class="inline-flex shrink-0 rounded-full px-3 py-1 text-sm font-semibold" :class="statusClass(document.status)">
                            {{ document.status || 'N/A' }}
                        </span>
                    </div>

                    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div><p class="text-xs font-semibold uppercase text-slate-500">Current Office</p><p class="mt-1 font-medium text-slate-900">{{ document.current_office || 'N/A' }}</p></div>
                        <div><p class="text-xs font-semibold uppercase text-slate-500">Origin Office</p><p class="mt-1 font-medium text-slate-900">{{ document.origin_office || 'N/A' }}</p></div>
                        <div><p class="text-xs font-semibold uppercase text-slate-500">Document Type</p><p class="mt-1 font-medium text-slate-900">{{ document.document_type || 'N/A' }}</p></div>
                        <div><p class="text-xs font-semibold uppercase text-slate-500">Priority</p><p class="mt-1 font-medium text-slate-900">{{ document.priority || 'N/A' }}</p></div>
                    </div>

                    <div v-if="document.details && !document.is_protected" class="mt-6 border-t border-slate-200 pt-5">
                        <p class="text-xs font-semibold uppercase text-slate-500">Document Details</p>
                        <p class="mt-2 whitespace-pre-line text-slate-800">{{ document.details }}</p>
                    </div>

                    <div class="mt-6 border-t border-slate-200 pt-5">
                        <p class="text-sm font-semibold text-slate-900">Tracking History</p>
                        <div v-if="!document.movement_history?.length" class="mt-3 text-sm text-slate-500">No routing movement has been recorded yet.</div>
                        <div v-else class="mt-3 space-y-3">
                            <div v-for="movement in document.movement_history" :key="movement.id" class="rounded-lg border border-slate-200 p-3">
                                <p class="font-medium text-slate-900">{{ movement.from_office || 'N/A' }} → {{ movement.to_office || 'N/A' }}</p>
                                <p class="mt-1 text-sm text-slate-600">Forwarded: {{ formatDate(movement.forwarded_at) }}</p>
                                <p class="text-sm text-slate-600">{{ movement.received_at ? `Received: ${formatDate(movement.received_at)}` : 'Awaiting receipt' }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end">
                        <Button type="button" @click="closeInquiryDocument">Close</Button>
                    </div>
                </section>
            </div>

        </div>

    </div>
</template>
