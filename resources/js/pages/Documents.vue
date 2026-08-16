<script setup>
import { onMounted, ref } from 'vue'

import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card'

import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table'

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'

const documents = ref([])
const loading = ref(true)
const error = ref('')

const showCreateForm = ref(false)
const creating = ref(false)
const createError = ref('')
const createSuccess = ref('')

const title = ref('')
const description = ref('')

const fetchDocuments = async () => {
    loading.value = true
    error.value = ''

    try {
        const token = localStorage.getItem('auth_token')

        const response = await fetch('/api/documents', {
            headers: {
                Accept: 'application/json',
                Authorization: `Bearer ${token}`,
            },
        })

        if (!response.ok) {
            throw new Error('Unable to load documents.')
        }

        documents.value = await response.json()
    } catch (err) {
        error.value = err.message || 'Unable to load documents.'
    } finally {
        loading.value = false
    }
}

const openCreateForm = () => {
    title.value = ''
    description.value = ''
    createError.value = ''
    createSuccess.value = ''
    showCreateForm.value = true
}

const closeCreateForm = () => {
    if (creating.value) {
        return
    }

    showCreateForm.value = false
}

const createDocument = async () => {
    createError.value = ''
    createSuccess.value = ''

    if (!title.value.trim()) {
        createError.value = 'Document title is required.'
        return
    }

    creating.value = true

    try {
        const token = localStorage.getItem('auth_token')

        const response = await fetch('/api/documents', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                Authorization: `Bearer ${token}`,
            },
            body: JSON.stringify({
                title: title.value,
                description: description.value || null,
            }),
        })

        const data = await response.json()

        if (!response.ok) {
            throw new Error(
                data.message || 'Unable to create document.'
            )
        }

        createSuccess.value = 'Document created successfully.'

        title.value = ''
        description.value = ''

        await fetchDocuments()

        setTimeout(() => {
            showCreateForm.value = false
            createSuccess.value = ''
        }, 1000)

    } catch (err) {
        createError.value =
            err.message || 'Unable to create document.'
    } finally {
        creating.value = false
    }
}

const formatDate = (date) => {
    if (!date) {
        return 'N/A'
    }

    return new Date(date).toLocaleString()
}

onMounted(() => {
    fetchDocuments()
})
</script>

<template>
    <div class="min-h-screen bg-gray-100">

        <!-- Header -->
        <div
            class="bg-white border-b px-6 py-4
            flex items-center justify-between"
        >

            <div>
                <h1 class="text-2xl font-bold text-gray-800">
                    Documents
                </h1>

                <p class="text-sm text-gray-500 mt-1">
                    Document Management
                </p>
            </div>

            <Button
                @click="openCreateForm"
                class="bg-blue-600 hover:bg-blue-700"
            >
                + New Document
            </Button>

        </div>

        <!-- Main Content -->
        <div class="p-6">

            <Card>

                <CardHeader>
                    <CardTitle>
                        Document List
                    </CardTitle>
                </CardHeader>

                <CardContent>

                    <!-- Loading -->
                    <div
                        v-if="loading"
                        class="py-8 text-center text-gray-500"
                    >
                        Loading documents...
                    </div>

                    <!-- Error -->
                    <div
                        v-else-if="error"
                        class="py-8 text-center text-red-500"
                    >
                        {{ error }}
                    </div>

                    <!-- No documents -->
                    <div
                        v-else-if="documents.length === 0"
                        class="py-8 text-center text-gray-500"
                    >
                        No documents found.
                    </div>

                    <!-- Documents -->
                    <Table v-else>

                        <TableHeader>
                            <TableRow>

                                <TableHead>
                                    Tracking No.
                                </TableHead>

                                <TableHead>
                                    Title
                                </TableHead>

                                <TableHead>
                                    Status
                                </TableHead>

                                <TableHead>
                                    Date
                                </TableHead>

                            </TableRow>
                        </TableHeader>

                        <TableBody>

                            <TableRow
                                v-for="document in documents"
                                :key="document.id"
                            >

                                <TableCell class="font-medium">
                                    {{ document.tracking_no }}
                                </TableCell>

                                <TableCell>
                                    {{ document.title }}
                                </TableCell>

                                <TableCell>
                                    {{ document.status?.status_name || 'N/A' }}
                                </TableCell>

                                <TableCell>
                                    {{ formatDate(document.created_at) }}
                                </TableCell>

                            </TableRow>

                        </TableBody>

                    </Table>

                </CardContent>

            </Card>

        </div>

        <!-- Create Document Modal -->
        <div
            v-if="showCreateForm"
            class="fixed inset-0 z-50
            flex items-center justify-center
            bg-black/50 px-4"
        >

            <Card class="w-full max-w-lg">

                <CardHeader>

                    <CardTitle>
                        Create New Document
                    </CardTitle>

                </CardHeader>

                <CardContent>

                    <form
                        @submit.prevent="createDocument"
                        class="space-y-5"
                    >

                        <!-- Title -->
                        <div>

                            <label
                                class="block mb-2
                                text-sm font-semibold
                                text-gray-700"
                            >
                                Document Title
                            </label>

                            <Input
                                v-model="title"
                                type="text"
                                placeholder="Enter document title"
                                class="h-11"
                                :disabled="creating"
                            />

                        </div>

                        <!-- Description -->
                        <div>

                            <label
                                class="block mb-2
                                text-sm font-semibold
                                text-gray-700"
                            >
                                Description
                            </label>

                            <textarea
                                v-model="description"
                                rows="5"
                                placeholder="Enter document description"
                                :disabled="creating"
                                class="w-full rounded-md
                                border border-gray-300
                                px-3 py-2 text-sm
                                focus:outline-none
                                focus:ring-2
                                focus:ring-blue-500"
                            ></textarea>

                        </div>

                        <!-- Error -->
                        <div
                            v-if="createError"
                            class="rounded-md
                            bg-red-50
                            border border-red-200
                            p-3 text-sm text-red-600"
                        >
                            {{ createError }}
                        </div>

                        <!-- Success -->
                        <div
                            v-if="createSuccess"
                            class="rounded-md
                            bg-green-50
                            border border-green-200
                            p-3 text-sm text-green-600"
                        >
                            {{ createSuccess }}
                        </div>

                        <!-- Buttons -->
                        <div
                            class="flex justify-end gap-3 pt-2"
                        >

                            <Button
                                type="button"
                                variant="outline"
                                @click="closeCreateForm"
                                :disabled="creating"
                            >
                                Cancel
                            </Button>

                            <Button
                                type="submit"
                                class="bg-blue-600
                                hover:bg-blue-700"
                                :disabled="creating"
                            >
                                {{
                                    creating
                                        ? 'Creating...'
                                        : 'Create Document'
                                }}
                            </Button>

                        </div>

                    </form>

                </CardContent>

            </Card>

        </div>

    </div>
</template>