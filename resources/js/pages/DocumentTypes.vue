<script setup>
import { computed, onMounted, ref } from 'vue'

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
import { can } from '@/lib/auth'

const documentTypes = ref([])

const loading = ref(true)
const error = ref('')

const showForm = ref(false)
const saving = ref(false)
const deletingId = ref(null)

const showDeleteModal = ref(false)
const documentTypeToDelete = ref(null)

const formError = ref('')
const successMessage = ref('')

const editingId = ref(null)

const form = ref({
    type_name: '',
    description: '',
})

const getToken = () => {
    return localStorage.getItem('auth_token')
}

const canManageMasterData = computed(() => {
    return can('master_data.manage')
})

const ensureCanManageMasterData = () => {
    if (canManageMasterData.value) {
        return true
    }

    formError.value =
        'You do not have permission to manage document types.'

    return false
}

const fetchDocumentTypes = async () => {
    loading.value = true
    error.value = ''

    try {
        const response = await fetch('/api/document-types', {
            headers: {
                Accept: 'application/json',
                Authorization: `Bearer ${getToken()}`,
            },
        })

        if (!response.ok) {
            throw new Error('Unable to load document types.')
        }

        documentTypes.value = await response.json()
    } catch (err) {
        error.value =
            err.message || 'Unable to load document types.'
    } finally {
        loading.value = false
    }
}

const resetForm = () => {
    editingId.value = null

    form.value = {
        type_name: '',
        description: '',
    }

    formError.value = ''
}

const openAddForm = () => {
    if (!ensureCanManageMasterData()) {
        return
    }

    resetForm()
    successMessage.value = ''
    showForm.value = true
}

const openEditForm = (documentType) => {
    if (!ensureCanManageMasterData()) {
        return
    }

    editingId.value = documentType.id

    form.value = {
        type_name: documentType.type_name,
        description: documentType.description || '',
    }

    formError.value = ''
    successMessage.value = ''
    showForm.value = true
}

const closeForm = () => {
    if (saving.value) {
        return
    }

    showForm.value = false
    resetForm()
}

const saveDocumentType = async () => {
    formError.value = ''
    successMessage.value = ''

    if (!ensureCanManageMasterData()) {
        return
    }

    if (!form.value.type_name.trim()) {
        formError.value = 'Document type name is required.'
        return
    }

    saving.value = true

    try {
        const isEditing = editingId.value !== null

        const url = isEditing
            ? `/api/document-types/${editingId.value}`
            : '/api/document-types'

        const method = isEditing ? 'PUT' : 'POST'

        const response = await fetch(url, {
            method,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                Authorization: `Bearer ${getToken()}`,
            },
            body: JSON.stringify({
                type_name: form.value.type_name.trim(),
                description:
                    form.value.description.trim() || null,
            }),
        })

        const data = await response.json()

        if (!response.ok) {
            if (data.errors) {
                const firstError =
                    Object.values(data.errors)[0]

                throw new Error(
                    Array.isArray(firstError)
                        ? firstError[0]
                        : firstError
                )
            }

            throw new Error(
                data.message ||
                'Unable to save document type.'
            )
        }

        successMessage.value = isEditing
            ? 'Document type updated successfully.'
            : 'Document type created successfully.'

        showForm.value = false
        resetForm()

        await fetchDocumentTypes()
    } catch (err) {
        formError.value =
            err.message || 'Unable to save document type.'
    } finally {
        saving.value = false
    }
}

const openDeleteModal = (documentType) => {
    if (!ensureCanManageMasterData()) {
        return
    }

    documentTypeToDelete.value = documentType
    showDeleteModal.value = true
    error.value = ''
    successMessage.value = ''
}

const closeDeleteModal = () => {
    if (deletingId.value !== null) {
        return
    }

    showDeleteModal.value = false
    documentTypeToDelete.value = null
}

const confirmDeleteDocumentType = async () => {
    if (!ensureCanManageMasterData()) {
        return
    }

    if (!documentTypeToDelete.value) {
        return
    }

    const documentType = documentTypeToDelete.value

    deletingId.value = documentType.id
    error.value = ''
    successMessage.value = ''

    try {
        const response = await fetch(
            `/api/document-types/${documentType.id}`,
            {
                method: 'DELETE',
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
                'Unable to delete document type.'
            )
        }

        successMessage.value =
            'Document type deleted successfully.'

        showDeleteModal.value = false
        documentTypeToDelete.value = null

        await fetchDocumentTypes()
    } catch (err) {
        error.value =
            err.message ||
            'Unable to delete document type.'

        showDeleteModal.value = false
        documentTypeToDelete.value = null
    } finally {
        deletingId.value = null
    }
}

onMounted(() => {
    fetchDocumentTypes()
})
</script>

<template>
    <div class="min-h-screen bg-gray-100">

        <!-- Header -->
        <div class="bg-white border-b px-6 py-4">

            <div class="flex items-center justify-between">

                <div>
                    <h1 class="text-2xl font-bold text-gray-800">
                        Document Type Management
                    </h1>

                    <p class="text-sm text-gray-500 mt-1">
                        Manage document classifications used by the system
                    </p>
                </div>

                <Button
                    v-if="canManageMasterData"
                    @click="openAddForm"
                >
                    + Add Document Type
                </Button>

            </div>

        </div>

        <!-- Main Content -->
        <div class="p-6">

            <!-- Success Message -->
            <div
                v-if="successMessage"
                class="mb-4 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm font-bold text-green-700"
            >
                {{ successMessage }}
            </div>

            <!-- Add / Edit Form -->
            <Card
                v-if="showForm && canManageMasterData"
                class="mb-6"
            >

                <CardHeader>
                    <CardTitle>
                        {{
                            editingId
                                ? 'Edit Document Type'
                                : 'Add Document Type'
                        }}
                    </CardTitle>
                </CardHeader>

                <CardContent>

                    <form
                        @submit.prevent="saveDocumentType"
                        class="space-y-5"
                    >

                        <!-- Type Name -->
                        <div>

                            <label
                                class="block mb-2 text-sm font-semibold text-gray-700"
                            >
                                Document Type
                            </label>

                            <Input
                                v-model="form.type_name"
                                type="text"
                                placeholder="e.g. OBR"
                                maxlength="100"
                                :disabled="saving"
                            />

                        </div>

                        <!-- Description -->
                        <div>

                            <label
                                class="block mb-2 text-sm font-semibold text-gray-700"
                            >
                                Description
                            </label>

                            <textarea
                                v-model="form.description"
                                rows="4"
                                placeholder="Enter document type description"
                                :disabled="saving"
                                class="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                            ></textarea>

                        </div>

                        <!-- Error -->
                        <div
                            v-if="formError"
                            class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-600"
                        >
                            {{ formError }}
                        </div>

                        <!-- Buttons -->
                        <div class="flex justify-end gap-2">

                            <Button
                                type="button"
                                variant="outline"
                                :disabled="saving"
                                @click="closeForm"
                            >
                                Cancel
                            </Button>

                            <Button
                                type="submit"
                                :disabled="saving"
                            >
                                {{
                                    saving
                                        ? 'Saving...'
                                        : editingId
                                            ? 'Update'
                                            : 'Save'
                                }}
                            </Button>

                        </div>

                    </form>

                </CardContent>

            </Card>

            <!-- Error Message -->
            <div
                v-if="error"
                class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700"
            >
                {{ error }}
            </div>

            <!-- Document Type List -->
            <Card>

                <CardHeader>
                    <CardTitle>
                        Document Types
                    </CardTitle>
                </CardHeader>

                <CardContent>

                    <!-- Loading -->
                    <div
                        v-if="loading"
                        class="py-8 text-center text-gray-500"
                    >
                        Loading document types...
                    </div>

                    <!-- Empty -->
                    <div
                        v-else-if="documentTypes.length === 0"
                        class="py-8 text-center text-gray-500"
                    >
                        No document types found.
                    </div>

                    <!-- Table -->
                    <Table v-else>

                        <TableHeader>

                            <TableRow>

                                <TableHead>
                                    Document Type
                                </TableHead>

                                <TableHead>
                                    Description
                                </TableHead>

                                <TableHead
                                    v-if="canManageMasterData"
                                >
                                    Actions
                                </TableHead>

                            </TableRow>

                        </TableHeader>

                        <TableBody>

                            <TableRow
                                v-for="documentType in documentTypes"
                                :key="documentType.id"
                            >

                                <TableCell class="font-medium">
                                    {{ documentType.type_name }}
                                </TableCell>

                                <TableCell>
                                    {{
                                        documentType.description
                                        || 'N/A'
                                    }}
                                </TableCell>

                                <TableCell
                                    v-if="canManageMasterData"
                                >

                                    <div class="flex gap-2">

                                        <Button
                                            variant="outline"
                                            size="sm"
                                            @click="openEditForm(documentType)"
                                        >
                                            Edit
                                        </Button>

                                        <Button
                                            variant="destructive"
                                            size="sm"
                                            :disabled="
                                                deletingId === documentType.id
                                            "
                                            @click="
                                                openDeleteModal(documentType)
                                            "
                                        >
                                            Delete
                                        </Button>

                                    </div>

                                </TableCell>

                            </TableRow>

                        </TableBody>

                    </Table>

                </CardContent>

            </Card>

        </div>

        <!-- Delete Confirmation Modal -->
        <div
            v-if="showDeleteModal && canManageMasterData"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4"
        >

            <div
                class="w-full max-w-md rounded-xl bg-white p-6 shadow-2xl"
                role="dialog"
                aria-modal="true"
                aria-labelledby="delete-modal-title"
            >

                <h2
                    id="delete-modal-title"
                    class="text-xl font-bold text-gray-900"
                >
                    Delete Document Type
                </h2>

                <p class="mt-3 text-sm text-gray-600">
                    Are you sure you want to delete
                    <span class="font-semibold text-gray-900">
                        "{{ documentTypeToDelete?.type_name }}"
                    </span>?
                </p>

                <p class="mt-2 text-sm text-gray-500">
                    This action cannot be undone.
                </p>

                <div class="mt-6 flex justify-end gap-3">

                    <!-- Cancel Button -->
                    <button
                        type="button"
                        class="rounded-md bg-black px-5 py-2 text-sm font-semibold text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="deletingId !== null"
                        @click="closeDeleteModal"
                    >
                        Cancel
                    </button>

                    <!-- Yes Button -->
                    <button
                        type="button"
                        class="rounded-md bg-red-600 px-5 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="deletingId !== null"
                        @click="confirmDeleteDocumentType"
                    >
                        {{
                            deletingId !== null
                                ? 'Deleting...'
                                : 'Yes'
                        }}
                    </button>

                </div>

            </div>

        </div>

    </div>
</template>