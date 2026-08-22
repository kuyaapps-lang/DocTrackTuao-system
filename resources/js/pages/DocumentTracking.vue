<script setup>
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

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

const trackingNumber = ref('')
const document = ref(null)

const loading = ref(false)
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

/*
|--------------------------------------------------------------------------
| Manual Search
|--------------------------------------------------------------------------
*/

const searchDocument = async () => {
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
    <div class="min-h-screen bg-gray-100">

        <!-- Header -->
        <div class="border-b bg-white px-6 py-5">

            <div class="mx-auto max-w-5xl">

                <h1
                    class="text-2xl font-bold text-gray-900"
                >
                    Document Status / Inquiry
                </h1>

                <p
                    class="mt-1 text-sm text-gray-500"
                >
                    Track the current status and movement
                    of an LGU Tuao document.
                </p>

            </div>

        </div>

        <!-- Content -->
        <div class="mx-auto max-w-5xl p-6">

            <!-- Search -->
            <Card>

                <CardContent class="pt-6">

                    <form
                        class="flex flex-col gap-3 sm:flex-row"
                        @submit.prevent="searchDocument"
                    >

                        <Input
                            v-model="trackingNumber"
                            type="text"
                            placeholder="Enter tracking number"
                            class="h-11 flex-1"
                            :disabled="loading"
                        />

                        <Button
                            type="submit"
                            class="h-11 bg-blue-600 px-6 hover:bg-blue-700"
                            :disabled="loading"
                        >
                            {{
                                loading
                                    ? 'Searching...'
                                    : 'Track Document'
                            }}
                        </Button>

                    </form>

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
                                    {{ document.tracking_no }}
                                </p>

                                <CardTitle class="mt-2 text-2xl">
                                    {{ document.title }}
                                </CardTitle>

                                <p
                                    v-if="document.is_protected"
                                    class="mt-2 text-sm text-orange-600"
                                >
                                    Some document information is protected.
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
                            Movement History
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
                            This document has not been routed yet.
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
                    This page displays document tracking information only.
                    Internal processing information is not publicly shown.
                </div>

            </template>

        </div>

    </div>
</template>