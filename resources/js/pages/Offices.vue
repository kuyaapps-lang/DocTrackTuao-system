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

const offices = ref([])
const loading = ref(true)
const error = ref('')
const showOfficeForm = ref(false)
const editingOffice = ref(null)
const saving = ref(false)
const formError = ref('')
const officeToDelete = ref(null)
const deleting = ref(false)
const deleteError = ref('')
const officeForm = ref({
    office_name: '',
    office_code: '',
    description: '',
})

const canManageMasterData = computed(() => {
    return can('master_data.manage')
})

const fetchOffices = async () => {
    loading.value = true
    error.value = ''

    try {
        const token = localStorage.getItem('auth_token')

        const response = await fetch('/api/offices', {
            headers: {
                Accept: 'application/json',
                Authorization: `Bearer ${token}`,
            },
        })

        if (!response.ok) {
            throw new Error('Unable to load offices.')
        }

        offices.value = await response.json()
    } catch (err) {
        error.value = err.message || 'Unable to load offices.'
    } finally {
        loading.value = false
    }
}

const openEditOffice = office => {
    if (!canManageMasterData.value) {
        return
    }

    editingOffice.value = office
    showOfficeForm.value = true
    officeForm.value = {
        office_name: office.office_name || '',
        office_code: office.office_code || '',
        description: office.description || '',
    }
    formError.value = ''
}

const openAddOffice = () => {
    if (!canManageMasterData.value) {
        return
    }

    editingOffice.value = null
    officeForm.value = {
        office_name: '',
        office_code: '',
        description: '',
    }
    formError.value = ''
    showOfficeForm.value = true
}

const closeEditOffice = () => {
    if (saving.value) {
        return
    }

    showOfficeForm.value = false
    editingOffice.value = null
    formError.value = ''
}

const saveOffice = async () => {
    if (!canManageMasterData.value) {
        return
    }

    const officeName = officeForm.value.office_name.trim()
    const officeCode = officeForm.value.office_code.trim()

    if (!officeName || !officeCode) {
        formError.value = 'Office name and office code are required.'
        return
    }

    saving.value = true
    formError.value = ''

    try {
        const isEditing = editingOffice.value !== null
        const response = await fetch(isEditing ? `/api/offices/${editingOffice.value.id}` : '/api/offices', {
            method: isEditing ? 'PUT' : 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
            },
            body: JSON.stringify({
                office_name: officeName,
                office_code: officeCode,
                description: officeForm.value.description.trim() || null,
            }),
        })
        const data = await response.json().catch(() => null)

        if (!response.ok) {
            const firstError = data?.errors
                ? Object.values(data.errors).flat()[0]
                : null
            formError.value = firstError || 'Unable to update the office.'
            return
        }

        await fetchOffices()
        showOfficeForm.value = false
        editingOffice.value = null
        formError.value = ''
    } catch {
        formError.value = 'Unable to update the office.'
    } finally {
        saving.value = false
    }
}

const openDeleteOffice = office => {
    if (!canManageMasterData.value) {
        return
    }

    officeToDelete.value = office
    deleteError.value = ''
}

const closeDeleteOffice = () => {
    if (deleting.value) {
        return
    }

    officeToDelete.value = null
    deleteError.value = ''
}

const deleteOffice = async () => {
    if (!officeToDelete.value || !canManageMasterData.value) {
        return
    }

    deleting.value = true
    deleteError.value = ''

    try {
        const response = await fetch(`/api/offices/${officeToDelete.value.id}`, {
            method: 'DELETE',
            headers: {
                Accept: 'application/json',
                Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
            },
        })
        const data = await response.json().catch(() => null)

        if (!response.ok) {
            deleteError.value = data?.message || 'Unable to delete the office.'
            return
        }

        await fetchOffices()
        officeToDelete.value = null
    } catch {
        deleteError.value = 'Unable to delete the office.'
    } finally {
        deleting.value = false
    }
}

onMounted(() => {
    fetchOffices()
})
</script>

<template>
    <div class="min-h-screen bg-slate-100">

        <!-- Header -->
        <div class="border-b border-white/80 bg-white px-6 py-4 shadow-[0_4px_14px_rgb(92_113_138/0.07)]">
            <div class="flex items-center justify-between">

                <div>
                    <h1 class="text-2xl font-bold text-gray-800">
                        Office Management
                    </h1>

                    <p class="text-sm text-gray-500 mt-1">
                        Manage offices and their departments
                    </p>
                </div>

                <Button
                    v-if="canManageMasterData"
                    class="bg-blue-900 text-[11.5pt] text-white hover:bg-blue-950 hover:text-white"
                    @click="openAddOffice"
                >
                    Add Office
                </Button>

            </div>
        </div>

        <!-- Main Content -->
        <div class="p-6">

            <Card class="relative overflow-hidden !bg-white before:pointer-events-none before:absolute before:inset-x-0 before:top-0 before:h-6 before:bg-blue-900">

                <CardHeader class="bg-blue-900 text-white">
                    <CardTitle>
                        Offices
                    </CardTitle>
                </CardHeader>

                <CardContent class="bg-white">

                    <!-- Loading -->
                    <div
                        v-if="loading"
                        class="py-8 text-center text-gray-500"
                    >
                        Loading offices...
                    </div>

                    <!-- Error -->
                    <div
                        v-else-if="error"
                        class="py-8 text-center text-red-500"
                    >
                        {{ error }}
                    </div>

                    <!-- No offices -->
                    <div
                        v-else-if="offices.length === 0"
                        class="py-8 text-center text-gray-500"
                    >
                        No offices found.
                    </div>

                    <!-- Office Table -->
                    <Table v-else class="text-[11.5pt] [&_td]:py-[7px] [&_th]:text-[12.5pt]">

                        <TableHeader class="bg-blue-900 text-white">
                            <TableRow>

                                <TableHead class="text-white font-semibold">
                                    Office Code
                                </TableHead>

                                <TableHead class="text-white font-semibold">
                                    Office Name
                                </TableHead>

                                <TableHead class="text-white font-semibold">
                                    Department
                                </TableHead>

                                <TableHead class="text-white font-semibold">
                                    Description
                                </TableHead>

                                <TableHead
                                    v-if="canManageMasterData"
                                    class="text-white font-semibold"
                                >
                                    Actions
                                </TableHead>

                            </TableRow>
                        </TableHeader>

                        <TableBody>

                            <TableRow
                                v-for="office in offices"
                                :key="office.id"
                            >

                                <TableCell class="font-medium">
                                    {{ office.office_code }}
                                </TableCell>

                                <TableCell>
                                    {{ office.office_name }}
                                </TableCell>

                                <TableCell>
                                    {{ office.department?.department_name || 'N/A' }}
                                </TableCell>

                                <TableCell>
                                    {{ office.description || 'N/A' }}
                                </TableCell>

                                <TableCell
                                    v-if="canManageMasterData"
                                >
                                    <div class="flex gap-2">

                                        <Button
                                            variant="outline"
                                            size="sm"
                                            class="bg-blue-900 text-[11.5pt] text-white hover:bg-blue-950 hover:text-white"
                                            @click="openEditOffice(office)"
                                        >
                                            Edit
                                        </Button>

                                        <Button
                                            variant="destructive"
                                            size="sm"
                                            @click="openDeleteOffice(office)"
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

        <div v-if="showOfficeForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4 py-6">
            <Card class="w-full max-w-xl bg-white">
                <CardHeader>
                    <CardTitle>{{ editingOffice ? 'Edit Office' : 'Add Office' }}</CardTitle>
                </CardHeader>
                <CardContent>
                    <form class="space-y-4" @submit.prevent="saveOffice">
                        <label class="block text-sm font-semibold text-gray-700">
                            Office name
                            <Input v-model="officeForm.office_name" class="mt-1" maxlength="150" :disabled="saving" />
                        </label>
                        <label class="block text-sm font-semibold text-gray-700">
                            Office code
                            <Input v-model="officeForm.office_code" class="mt-1" maxlength="20" :disabled="saving" />
                        </label>
                        <label class="block text-sm font-semibold text-gray-700">
                            Description
                            <textarea v-model="officeForm.description" class="mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 disabled:bg-gray-100" rows="4" maxlength="65535" :disabled="saving"></textarea>
                        </label>
                        <p v-if="formError" class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert">{{ formError }}</p>
                        <div class="flex justify-end gap-3">
                            <Button type="button" variant="outline" class="bg-black text-white hover:bg-black/90 hover:text-white" :disabled="saving" @click="closeEditOffice">Cancel</Button>
                            <Button type="submit" class="bg-blue-900 text-white hover:bg-blue-950 hover:text-white" :disabled="saving">{{ saving ? 'Saving...' : editingOffice ? 'Save Changes' : 'Add Office' }}</Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </div>

        <div v-if="officeToDelete" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4 py-6">
            <Card class="w-full max-w-md bg-white">
                <CardHeader>
                    <CardTitle>Delete Office</CardTitle>
                </CardHeader>
                <CardContent>
                    <p class="text-sm text-gray-700">Delete <span class="font-semibold">{{ officeToDelete.office_name }}</span>? This cannot be undone.</p>
                    <p v-if="deleteError" class="mt-4 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert">{{ deleteError }}</p>
                    <div class="mt-6 flex justify-end gap-3">
                        <Button type="button" variant="outline" class="bg-black text-white hover:bg-black/90 hover:text-white" :disabled="deleting" @click="closeDeleteOffice">Cancel</Button>
                        <Button type="button" variant="destructive" :disabled="deleting" @click="deleteOffice">{{ deleting ? 'Deleting...' : 'Delete Office' }}</Button>
                    </div>
                </CardContent>
            </Card>
        </div>

    </div>
</template>
