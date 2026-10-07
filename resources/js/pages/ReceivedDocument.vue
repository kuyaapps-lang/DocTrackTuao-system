<script setup>
import { computed, onMounted, ref } from 'vue'

import BulkStatusScanner from '@/components/BulkStatusScanner.vue'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'

const saving = ref(false)
const error = ref('')
const results = ref([])
const scanner = ref(null)
const actions = ref([])
const actionId = ref('')
const note = ref('')
const loadingActions = ref(true)

const token = () => localStorage.getItem('auth_token') || ''
const selectedAction = computed(() => actions.value.find(action => String(action.id) === actionId.value))

const responseMessage = async response => {
    const data = await response.json().catch(() => ({}))
    const validation = data?.errors && Object.values(data.errors)[0]
    return Array.isArray(validation) ? validation[0] : data?.message || 'Unable to receive the document.'
}

const loadActions = async () => {
    loadingActions.value = true
    try {
        const response = await fetch('/api/processing-actions', {
            headers: { Accept: 'application/json', Authorization: `Bearer ${token()}` },
        })
        const data = await response.json().catch(() => ({}))
        if (!response.ok || !Array.isArray(data.data)) throw new Error(data.message || 'Unable to load current actions.')
        actions.value = data.data
    } catch (exception) {
        error.value = exception.message || 'Unable to load current actions.'
    } finally {
        loadingActions.value = false
    }
}

const processScan = async qrToken => {
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
        if (!listResponse.ok || !document) throw new Error('The scanned QR code does not identify a document you can receive.')

        const response = await fetch(`/api/documents/${document.id}/receive`, {
            method: 'POST',
            headers: { Accept: 'application/json', Authorization: `Bearer ${token()}` },
        })
        if (!response.ok) throw new Error(await responseMessage(response))

        const result = {
            id: `${document.id}-${Date.now()}`,
            title: document.title || 'Untitled document',
            qr: qrToken,
            actionName: 'For Action',
        }

        if (actionId.value) {
            const actionResponse = await fetch(`/api/documents/${document.id}/processing`, {
                method: 'PUT',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${token()}` },
                body: JSON.stringify({ current_action_id: Number(actionId.value), processing_note: note.value.trim() || null }),
            })

            if (!actionResponse.ok) {
                results.value.unshift(result)
                throw new Error(`Document was received, but its Current Action was not saved: ${await responseMessage(actionResponse)}`)
            }

            result.actionName = selectedAction.value?.action_name || 'the selected action'
        }

        results.value.unshift(result)
    } catch (exception) {
        error.value = exception.message || 'Unable to receive the document.'
    } finally {
        saving.value = false
        scanner.value?.focus()
    }
}

onMounted(async () => {
    await loadActions()
    scanner.value?.focus()
})
</script>

<template>
    <section class="min-h-screen bg-slate-100 p-4 sm:p-6" aria-labelledby="received-document-heading">
        <div class="mx-auto max-w-3xl space-y-5">
            <Card>
                <CardHeader>
                    <CardTitle id="received-document-heading">Received Document</CardTitle>
                    <p class="text-sm text-slate-600">Optionally choose a Current Action, then scan each incoming document QR code and press Enter to receive it.</p>
                </CardHeader>
                <CardContent class="space-y-5">
                    <div>
                        <label for="received-current-action" class="mb-2 block text-sm font-semibold text-slate-700">Current Action <span class="font-normal text-slate-500">(optional)</span></label>
                        <select id="received-current-action" v-model="actionId" :disabled="loadingActions || saving" class="h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500" @change="scanner?.focus()">
                            <option value="">Keep the received action (For Action)</option>
                            <option v-for="action in actions" :key="action.id" :value="String(action.id)">{{ action.action_name }}</option>
                        </select>
                    </div>

                    <div>
                        <label for="received-processing-note" class="mb-2 block text-sm font-semibold text-slate-700">Processing Note <span class="font-normal text-slate-500">(optional; required for Other)</span></label>
                        <textarea id="received-processing-note" v-model="note" :disabled="saving" rows="3" maxlength="2000" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500" placeholder="Applied to each received document when a Current Action is selected" />
                    </div>

                    <BulkStatusScanner ref="scanner" :disabled="saving || loadingActions" @scan="processScan" />
                    <p v-if="error" class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert">{{ error }}</p>
                </CardContent>
            </Card>

            <Card v-if="results.length">
                <CardHeader><CardTitle>Received Documents</CardTitle></CardHeader>
                <CardContent class="space-y-2">
                    <div v-for="result in results" :key="result.id" class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">
                        <span class="font-semibold">{{ result.title }}</span> — {{ result.qr }} received as {{ result.actionName }}.
                    </div>
                </CardContent>
            </Card>
        </div>
    </section>
</template>
