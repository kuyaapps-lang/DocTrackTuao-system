<script setup>
import { computed, onMounted, ref } from 'vue'
import { Pencil, Save, X } from 'lucide-vue-next'

import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { can } from '@/lib/auth'

const offices = ref([])
const loading = ref(true)
const error = ref('')
const editingOffice = ref(null)
const signatoryName = ref('')
const signatoryDesignation = ref('')
const saving = ref(false)
const formError = ref('')

const canManageSignatories = computed(() => can('master_data.manage'))

const headers = () => ({
    Accept: 'application/json',
    Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
})

const loadOffices = async () => {
    loading.value = true
    error.value = ''

    try {
        const response = await fetch('/api/offices', { headers: headers() })
        const data = await response.json().catch(() => null)

        if (!response.ok) {
            throw new Error(data?.message || 'Unable to load office signatories.')
        }

        offices.value = Array.isArray(data) ? data : []
    } catch (exception) {
        error.value = exception.message || 'Unable to load office signatories.'
    } finally {
        loading.value = false
    }
}

const openEditor = office => {
    if (!canManageSignatories.value) return

    editingOffice.value = office
    signatoryName.value = office.signatory_name || ''
    signatoryDesignation.value = office.signatory_designation || ''
    formError.value = ''
}

const closeEditor = () => {
    if (saving.value) return

    editingOffice.value = null
    signatoryName.value = ''
    signatoryDesignation.value = ''
    formError.value = ''
}

const saveSignatory = async () => {
    if (!editingOffice.value || !canManageSignatories.value) return

    saving.value = true
    formError.value = ''

    try {
        const response = await fetch(`/api/offices/${editingOffice.value.id}`, {
            method: 'PUT',
            headers: {
                ...headers(),
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                office_name: editingOffice.value.office_name,
                office_code: editingOffice.value.office_code,
                signatory_name: signatoryName.value.trim() || null,
                signatory_designation: signatoryDesignation.value.trim() || null,
            }),
        })
        const data = await response.json().catch(() => null)

        if (!response.ok) {
            const validation = data?.errors
                ? Object.values(data.errors).flat()[0]
                : null
            throw new Error(validation || data?.message || 'Unable to save the report signatory.')
        }

        await loadOffices()
        closeEditor()
    } catch (exception) {
        formError.value = exception.message || 'Unable to save the report signatory.'
    } finally {
        saving.value = false
    }
}

onMounted(loadOffices)
</script>

<template>
    <main class="min-h-screen bg-slate-100 p-4 text-slate-900 dark:bg-slate-950 dark:text-slate-100 sm:p-6">
        <Card class="mx-auto max-w-5xl dark:border-slate-700 dark:bg-slate-900">
            <CardHeader>
                <CardTitle class="text-xl">Report Signatories</CardTitle>
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    Set the office representative shown under <strong>Certified Correct</strong> on accomplishment reports.
                </p>
            </CardHeader>
            <CardContent>
                <p v-if="loading" class="py-10 text-center text-slate-500 dark:text-slate-400">Loading offices...</p>
                <p v-else-if="error" class="rounded-md border border-red-200 bg-red-50 p-3 text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200">{{ error }}</p>
                <p v-else-if="offices.length === 0" class="py-10 text-center text-slate-500 dark:text-slate-400">No offices found.</p>
                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[620px] border-collapse text-left text-sm">
                        <thead class="bg-blue-900 text-white">
                            <tr>
                                <th class="p-3">Office</th>
                                <th class="p-3">Report Signatory</th>
                                <th class="p-3">Designation</th>
                                <th v-if="canManageSignatories" class="p-3 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                            <tr v-for="office in offices" :key="office.id" class="hover:bg-blue-50/70 dark:hover:bg-slate-800/80">
                                <td class="p-3 font-medium">{{ office.office_name }} <span v-if="office.office_code" class="text-slate-500 dark:text-slate-400">({{ office.office_code }})</span></td>
                                <td class="p-3">{{ office.signatory_name || 'Not set' }}</td>
                                <td class="p-3">{{ office.signatory_designation || 'Not set' }}</td>
                                <td v-if="canManageSignatories" class="p-3 text-right">
                                    <Button type="button" size="sm" class="bg-blue-900 text-white hover:bg-blue-800 hover:text-white" @click="openEditor(office)">
                                        <Pencil class="mr-2 size-4" />Edit
                                    </Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>

        <div v-if="editingOffice" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 px-4">
            <Card class="w-full max-w-md dark:border-slate-700 dark:bg-slate-900">
                <CardHeader>
                    <CardTitle>Set Report Signatory</CardTitle>
                    <p class="text-sm text-slate-600 dark:text-slate-300">{{ editingOffice.office_name }}</p>
                </CardHeader>
                <CardContent>
                    <form class="space-y-5" @submit.prevent="saveSignatory">
                        <label class="block text-sm font-semibold text-slate-700 dark:text-slate-200">
                            Signatory name
                            <Input v-model="signatoryName" class="mt-1" maxlength="150" :disabled="saving" placeholder="Full name" autofocus />
                        </label>
                        <label class="block text-sm font-semibold text-slate-700 dark:text-slate-200">
                            Position / designation
                            <Input v-model="signatoryDesignation" class="mt-1" maxlength="150" :disabled="saving" placeholder="e.g., Municipal Budget Officer" />
                        </label>
                        <p v-if="formError" class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200">{{ formError }}</p>
                        <div class="flex justify-end gap-3">
                            <Button type="button" variant="outline" class="bg-black text-white hover:bg-slate-800 hover:text-white dark:bg-slate-700 dark:hover:bg-slate-600" :disabled="saving" @click="closeEditor"><X class="mr-2 size-4" />Cancel</Button>
                            <Button type="submit" class="bg-blue-900 text-white hover:bg-blue-800 hover:text-white" :disabled="saving"><Save class="mr-2 size-4" />{{ saving ? 'Saving...' : 'Save Signatory' }}</Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </div>
    </main>
</template>
