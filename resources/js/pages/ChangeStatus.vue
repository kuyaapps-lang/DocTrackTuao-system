<script setup>
import { computed, onMounted, ref } from 'vue'

import BulkStatusScanner from '@/components/BulkStatusScanner.vue'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'

const actions = ref([])
const actionId = ref('')
const note = ref('')
const loadingActions = ref(true)
const saving = ref(false)
const error = ref('')
const results = ref([])
const scanner = ref(null)

const token = () => localStorage.getItem('auth_token') || ''
const selectedAction = computed(() => actions.value.find(action => String(action.id) === actionId.value))

const responseMessage = async response => {
    const data = await response.json().catch(() => ({}))
    const validation = data?.errors && Object.values(data.errors)[0]
    return Array.isArray(validation) ? validation[0] : data?.message || 'Unable to update the document status.'
}

const loadActions = async () => {
    loadingActions.value = true
    error.value = ''
    try {
        const response = await fetch('/api/processing-actions', {
            headers: { Accept: 'application/json', Authorization: `Bearer ${token()}` },
        })
        const data = await response.json().catch(() => ({}))
        if (!response.ok || !Array.isArray(data.data)) throw new Error(data.message || 'Unable to load processing actions.')
        actions.value = data.data
    } catch (exception) {
        error.value = exception.message || 'Unable to load processing actions.'
    } finally {
        loadingActions.value = false
    }
}

const processScan = async qrToken => {
    if (!actionId.value) {
        error.value = 'Choose a Current Action before scanning documents.'
        return
    }

    saving.value = true
    error.value = ''
    try {
        const listResponse = await fetch(`/api/documents?search=${encodeURIComponent(qrToken)}&per_page=25`, {
            headers: { Accept: 'application/json', Authorization: `Bearer ${token()}` },
        })
        const list = await listResponse.json().catch(() => ({}))
        const document = Array.isArray(list.data)
            ? list.data.find(item => item.qr_code === qrToken)
            : null
        if (!listResponse.ok || !document) throw new Error('The scanned QR code does not identify a document you can process.')

        const response = await fetch(`/api/documents/${document.id}/processing`, {
            method: 'PUT',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${token()}` },
            body: JSON.stringify({ current_action_id: Number(actionId.value), processing_note: note.value.trim() || null }),
        })
        if (!response.ok) throw new Error(await responseMessage(response))

        results.value.unshift({
            id: `${document.id}-${Date.now()}`,
            title: document.title || 'Untitled document',
            qr: qrToken,
            actionName: selectedAction.value?.action_name || 'the selected action',
        })
    } catch (exception) {
        error.value = exception.message || 'Unable to update the document status.'
    } finally {
        saving.value = false
        scanner.value?.focus()
    }
}

onMounted(loadActions)
</script>

<template>
    <section class="min-h-screen bg-slate-100 p-4 sm:p-6" aria-labelledby="change-status-heading">
        <div class="mx-auto max-w-3xl space-y-5">
            <Card>
                <CardHeader>
                    <CardTitle id="change-status-heading">Change Status</CardTitle>
                    <p class="text-sm text-slate-600">Choose one Current Action, then scan each QR code and press Enter to save it in bulk.</p>
                </CardHeader>
                <CardContent class="space-y-5">
                    <div>
                        <label for="bulk-current-action" class="mb-2 block text-sm font-semibold text-slate-700">Current Action</label>
                        <select id="bulk-current-action" v-model="actionId" :disabled="loadingActions || saving" class="h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500" @change="scanner?.focus()">
                            <option value="">Choose an action</option>
                            <option v-for="action in actions" :key="action.id" :value="String(action.id)">{{ action.action_name }}</option>
                        </select>
                    </div>

                    <div>
                        <label for="bulk-processing-note" class="mb-2 block text-sm font-semibold text-slate-700">Processing Note <span class="font-normal text-slate-500">(optional; required for Other)</span></label>
                        <textarea id="bulk-processing-note" v-model="note" :disabled="saving" rows="3" maxlength="2000" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500" placeholder="Applied to each scanned document" />
                    </div>

                    <BulkStatusScanner ref="scanner" :disabled="loadingActions || saving || !actionId" @scan="processScan" />
                    <p v-if="error" class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert">{{ error }}</p>
                </CardContent>
            </Card>

            <Card v-if="results.length">
                <CardHeader><CardTitle>Saved Documents</CardTitle></CardHeader>
                <CardContent class="space-y-2">
                    <div v-for="result in results" :key="result.id" class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">
                        <span class="font-semibold">{{ result.title }}</span> — {{ result.qr }} saved as {{ result.actionName }}.
                    </div>
                </CardContent>
            </Card>
        </div>
    </section>
</template>
