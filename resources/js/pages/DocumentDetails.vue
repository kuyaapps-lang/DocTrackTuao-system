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

const route = useRoute()
const router = useRouter()

const document = ref(null)
const loading = ref(true)
const error = ref('')

const fetchDocument = async () => {
    loading.value = true
    error.value = ''

    try {
        const token = localStorage.getItem('auth_token')

        const response = await fetch(
            `/api/documents/${route.params.id}`,
            {
                headers: {
                    Accept: 'application/json',
                    Authorization: `Bearer ${token}`,
                },
            }
        )

        if (!response.ok) {
            throw new Error('Unable to load document.')
        }

        document.value = await response.json()

    } catch (err) {
        error.value =
            err.message || 'Unable to load document.'
    } finally {
        loading.value = false
    }
}

const goBack = () => {
    router.push('/documents')
}

const formatDate = (date) => {
    if (!date) {
        return 'N/A'
    }

    return new Date(date).toLocaleString()
}

onMounted(() => {
    fetchDocument()
})
</script>

<template>
    <div class="min-h-screen bg-gray-100">

        <!-- Header -->
        <div class="bg-white border-b px-6 py-4">

            <div
                class="max-w-5xl mx-auto
                flex items-center justify-between"
            >

                <div>
                    <h1 class="text-2xl font-bold text-gray-800">
                        Document Details
                    </h1>

                    <p class="text-sm text-gray-500 mt-1">
                        View document information
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
        <div class="max-w-5xl mx-auto p-6">

            <!-- Loading -->
            <Card v-if="loading">

                <CardContent class="py-12">

                    <div
                        class="text-center text-gray-500"
                    >
                        Loading document...
                    </div>

                </CardContent>

            </Card>

            <!-- Error -->
            <Card v-else-if="error">

                <CardContent class="py-12">

                    <div
                        class="text-center text-red-500"
                    >
                        {{ error }}
                    </div>

                    <div class="flex justify-center mt-4">

                        <Button
                            variant="outline"
                            @click="goBack"
                        >
                            Back to Documents
                        </Button>

                    </div>

                </CardContent>

            </Card>

            <!-- Document -->
            <div
                v-else-if="document"
                class="space-y-6"
            >

                <!-- Basic Information -->
                <Card>

                    <CardHeader>

                        <CardTitle>
                            Document Information
                        </CardTitle>

                    </CardHeader>

                    <CardContent>

                        <div
                            class="grid grid-cols-1
                            md:grid-cols-2 gap-6"
                        >

                            <!-- Tracking Number -->
                            <div>

                                <p
                                    class="text-sm
                                    text-gray-500"
                                >
                                    Tracking No.
                                </p>

                                <p
                                    class="mt-1
                                    font-semibold
                                    text-gray-900"
                                >
                                    {{ document.tracking_no }}
                                </p>

                            </div>

                            <!-- Status -->
                            <div>

                                <p
                                    class="text-sm
                                    text-gray-500"
                                >
                                    Status
                                </p>

                                <p
                                    class="mt-1
                                    font-semibold
                                    text-gray-900"
                                >
                                    {{
                                        document.status?.status_name
                                        || 'N/A'
                                    }}
                                </p>

                            </div>

                            <!-- Title -->
                            <div
                                class="md:col-span-2"
                            >

                                <p
                                    class="text-sm
                                    text-gray-500"
                                >
                                    Title
                                </p>

                                <p
                                    class="mt-1
                                    font-semibold
                                    text-gray-900"
                                >
                                    {{ document.title }}
                                </p>

                            </div>

                            <!-- Description -->
                            <div
                                class="md:col-span-2"
                            >

                                <p
                                    class="text-sm
                                    text-gray-500"
                                >
                                    Description
                                </p>

                                <p
                                    class="mt-1
                                    text-gray-800
                                    whitespace-pre-line"
                                >
                                    {{
                                        document.description
                                        || 'No description provided.'
                                    }}
                                </p>

                            </div>

                        </div>

                    </CardContent>

                </Card>

                <!-- Office Information -->
                <Card>

                    <CardHeader>

                        <CardTitle>
                            Office Information
                        </CardTitle>

                    </CardHeader>

                    <CardContent>

                        <div
                            class="grid grid-cols-1
                            md:grid-cols-2 gap-6"
                        >

                            <div>

                                <p
                                    class="text-sm
                                    text-gray-500"
                                >
                                    Origin Office
                                </p>

                                <p
                                    class="mt-1
                                    font-semibold
                                    text-gray-900"
                                >
                                    {{
                                        document.origin_office?.office_name
                                        || 'N/A'
                                    }}
                                </p>

                            </div>

                            <div>

                                <p
                                    class="text-sm
                                    text-gray-500"
                                >
                                    Current Office
                                </p>

                                <p
                                    class="mt-1
                                    font-semibold
                                    text-gray-900"
                                >
                                    {{
                                        document.current_office?.office_name
                                        || 'N/A'
                                    }}
                                </p>

                            </div>

                        </div>

                    </CardContent>

                </Card>

                <!-- Dates -->
                <Card>

                    <CardHeader>

                        <CardTitle>
                            Document Dates
                        </CardTitle>

                    </CardHeader>

                    <CardContent>

                        <div
                            class="grid grid-cols-1
                            md:grid-cols-3 gap-6"
                        >

                            <div>

                                <p
                                    class="text-sm
                                    text-gray-500"
                                >
                                    Document Date
                                </p>

                                <p
                                    class="mt-1
                                    font-semibold
                                    text-gray-900"
                                >
                                    {{
                                        formatDate(
                                            document.document_date
                                        )
                                    }}
                                </p>

                            </div>

                            <div>

                                <p
                                    class="text-sm
                                    text-gray-500"
                                >
                                    Due Date
                                </p>

                                <p
                                    class="mt-1
                                    font-semibold
                                    text-gray-900"
                                >
                                    {{
                                        formatDate(
                                            document.due_date
                                        )
                                    }}
                                </p>

                            </div>

                            <div>

                                <p
                                    class="text-sm
                                    text-gray-500"
                                >
                                    Created
                                </p>

                                <p
                                    class="mt-1
                                    font-semibold
                                    text-gray-900"
                                >
                                    {{
                                        formatDate(
                                            document.created_at
                                        )
                                    }}
                                </p>

                            </div>

                        </div>

                    </CardContent>

                </Card>

                <!-- Actions -->
                <div class="flex justify-end">

                    <Button
                        variant="outline"
                        @click="goBack"
                    >
                        Back to Documents
                    </Button>

                </div>

            </div>

        </div>

    </div>
</template>