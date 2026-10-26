<script setup>
import { onMounted, ref } from 'vue'
import { Pencil, Plus, Save, Trash2, X } from 'lucide-vue-next'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'

const statuses = ref([])
const loading = ref(true)
const error = ref('')
const formError = ref('')
const success = ref('')
const showForm = ref(false)
const saving = ref(false)
const editingId = ref(null)
const deletingId = ref(null)
const name = ref('')
const color = ref('')
const colors = ['gray', 'yellow', 'blue', 'indigo', 'orange', 'green', 'emerald', 'slate', 'red']
const headers = () => ({ Accept: 'application/json', Authorization: `Bearer ${localStorage.getItem('auth_token')}` })

async function loadStatuses() {
    loading.value = true
    error.value = ''
    try {
        const response = await fetch('/api/document-statuses', { headers: headers() })
        if (!response.ok) throw new Error('Unable to load document statuses.')
        statuses.value = await response.json()
    } catch (err) {
        error.value = err.message || 'Unable to load document statuses.'
    } finally {
        loading.value = false
    }
}

function openForm(status = null) {
    editingId.value = status?.id ?? null
    name.value = status?.status_name ?? ''
    color.value = status?.color ?? ''
    formError.value = ''
    success.value = ''
    showForm.value = true
}

async function saveStatus() {
    formError.value = ''
    if (!name.value.trim()) {
        formError.value = 'Document status name is required.'
        return
    }
    saving.value = true
    try {
        const response = await fetch(editingId.value === null ? '/api/document-statuses' : `/api/document-statuses/${editingId.value}`, {
            method: editingId.value === null ? 'POST' : 'PUT',
            headers: { ...headers(), 'Content-Type': 'application/json' },
            body: JSON.stringify({ status_name: name.value.trim(), color: color.value || null }),
        })
        const data = await response.json()
        if (!response.ok) throw new Error(Object.values(data.errors || {})[0]?.[0] || data.message || 'Unable to save document status.')
        showForm.value = false
        success.value = editingId.value === null ? 'Document status created successfully.' : 'Document status updated successfully.'
        await loadStatuses()
    } catch (err) {
        formError.value = err.message || 'Unable to save document status.'
    } finally {
        saving.value = false
    }
}

async function deleteStatus(status) {
    if (!window.confirm(`Delete document status "${status.status_name}"?`)) return
    deletingId.value = status.id
    error.value = ''
    success.value = ''
    try {
        const response = await fetch(`/api/document-statuses/${status.id}`, { method: 'DELETE', headers: headers() })
        const data = await response.json()
        if (!response.ok) throw new Error(data.message || 'Unable to delete document status.')
        success.value = 'Document status deleted successfully.'
        if (editingId.value === status.id) showForm.value = false
        await loadStatuses()
    } catch (err) {
        error.value = err.message || 'Unable to delete document status.'
    } finally {
        deletingId.value = null
    }
}

onMounted(loadStatuses)
</script>

<template>
    <div class="min-h-screen bg-slate-100">
        <div class="border-b border-white/80 bg-white px-6 py-4 shadow-[0_4px_14px_rgb(92_113_138/0.07)]">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><h1 class="text-2xl font-bold text-gray-800">Document Status Management</h1>
                    <p class="mt-1 text-sm text-gray-500">Manage document statuses</p></div>
                <Button class="bg-blue-900 text-[11.5pt] text-white hover:bg-blue-950 hover:text-white" @click="openForm()">
                    <Plus class="mr-2 h-4 w-4" /> Add Document Status
                </Button>
            </div>
        </div>
        <div class="p-6">
            <div v-if="success" role="status" class="mb-4 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm font-bold text-green-700">{{ success }}</div>
            <Card v-if="showForm" class="relative mb-6 overflow-hidden !bg-white before:pointer-events-none before:absolute before:inset-x-0 before:top-0 before:h-6 before:bg-blue-900">
                <CardHeader class="bg-blue-900 text-white"><CardTitle>{{ editingId === null ? 'Add Document Status' : 'Edit Document Status' }}</CardTitle></CardHeader>
                <CardContent class="bg-white">
                    <form class="space-y-5" @submit.prevent="saveStatus">
                        <div><label for="status-name" class="mb-2 block text-sm font-semibold text-gray-700">Status Name</label>
                            <Input id="status-name" v-model="name" maxlength="50" :disabled="saving" placeholder="Enter status name" required /></div>
                        <div><label for="status-color" class="mb-2 block text-sm font-semibold text-gray-700">Color (optional)</label>
                            <select id="status-color" v-model="color" :disabled="saving" class="h-10 w-full rounded-md border border-gray-300 bg-white px-3 text-sm">
                                <option value="">No color</option><option v-for="item in colors" :key="item" :value="item">{{ item }}</option>
                            </select></div>
                        <p v-if="formError" role="alert" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-600">{{ formError }}</p>
                        <div class="flex justify-end gap-2">
                            <Button type="button" variant="outline" class="bg-black text-white hover:bg-black/90 hover:text-white" :disabled="saving" @click="showForm = false"><X class="mr-2 h-4 w-4" />Cancel</Button>
                            <Button type="submit" :disabled="saving"><Save class="mr-2 h-4 w-4" />{{ saving ? 'Saving...' : 'Save' }}</Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
            <p v-if="error" role="alert" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ error }}</p>
            <Card class="relative overflow-hidden !bg-white before:pointer-events-none before:absolute before:inset-x-0 before:top-0 before:h-6 before:bg-blue-900">
                <CardHeader class="bg-blue-900 text-white"><CardTitle>Document Statuses</CardTitle></CardHeader>
                <CardContent class="bg-white">
                    <p v-if="loading" role="status" class="py-8 text-center text-gray-500">Loading document statuses...</p>
                    <p v-else-if="!error && !statuses.length" class="py-8 text-center text-gray-500">No document statuses found.</p>
                    <Table v-else-if="!error" class="text-[11.5pt] [&_td]:py-[7px] [&_th]:text-[12.5pt]">
                        <TableHeader class="bg-blue-900 text-white"><TableRow><TableHead class="font-semibold text-white">Status</TableHead><TableHead class="font-semibold text-white">Color</TableHead><TableHead class="font-semibold text-white">Actions</TableHead></TableRow></TableHeader>
                        <TableBody><TableRow v-for="status in statuses" :key="status.id"><TableCell class="font-medium">{{ status.status_name }}</TableCell><TableCell>{{ status.color || 'N/A' }}</TableCell><TableCell><div class="flex gap-2">
                            <Button type="button" variant="outline" :disabled="saving || deletingId !== null" @click="openForm(status)"><Pencil class="mr-2 h-4 w-4" />Edit</Button>
                            <Button type="button" variant="outline" :disabled="saving || deletingId !== null" @click="deleteStatus(status)"><Trash2 class="mr-2 h-4 w-4" />{{ deletingId === status.id ? 'Deleting...' : 'Delete' }}</Button>
                        </div></TableCell></TableRow></TableBody>
                    </Table>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
