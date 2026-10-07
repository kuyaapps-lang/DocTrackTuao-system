<script setup>
import { computed, onMounted, ref } from 'vue'

import BulkStatusScanner from '@/components/BulkStatusScanner.vue'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'

const offices = ref([])
const officeId = ref('')
const remarks = ref('')
const loadingOffices = ref(true)
const saving = ref(false)
const error = ref('')
const results = ref([])
const scanner = ref(null)

const token = () => localStorage.getItem('auth_token') || ''
const selectedOffice = computed(() => offices.value.find(office => String(office.id) === officeId.value))

const responseMessage = async response => {
    const data = await response.json().catch(() => ({}))
    const validation = data?.errors && Object.values(data.errors)[0]
    return Array.isArray(validation) ? validation[0] : data?.message || 'Unable to release the document.'
}

const loadOffices = async () => {
    loadingOffices.value = true
    error.value = ''
    try {
        const response = await fetch('/api/routing-offices', {
            headers: { Accept: 'application/json', Authorization: `Bearer ${token()}` },
        })
        const data = await response.json().catch(() => ({}))
        if (!response.ok || !Array.isArray(data.data)) throw new Error(data.message || 'Unable to load destination offices.')
        offices.value = data.data
    } catch (exception) {
        error.value = exception.message || 'Unable to load destination offices.'
    } finally {
        loadingOffices.value = false
    }
}

const processScan = async qrToken => {
    if (!officeId.value) {
        error.value = 'Choose a destination office before scanning documents.'
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
        if (!listResponse.ok || !document) throw new Error('The scanned QR code does not identify a document you can release.')

        const response = await fetch(`/api/documents/${document.id}/forward`, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${token()}` },
            body: JSON.stringify({ to_office_id: Number(officeId.value), remarks: remarks.value.trim() || null }),
        })
        if (!response.ok) throw new Error(await responseMessage(response))

        results.value.unshift({
            id: `${document.id}-${Date.now()}`,
            title: document.title || 'Untitled document',
            qr: qrToken,
            officeName: selectedOffice.value?.office_name || 'the selected office',
        })
    } catch (exception) {
        error.value = exception.message || 'Unable to release the document.'
    } finally {
        saving.value = false
        scanner.value?.focus()
    }
}

onMounted(loadOffices)
</script>

<template>
    <section class="min-h-screen bg-slate-100 p-4 sm:p-6" aria-labelledby="release-document-heading">
        <div class="mx-auto max-w-3xl space-y-5">
            <Card>
                <CardHeader>
                    <CardTitle id="release-document-heading">Release Document</CardTitle>
                    <p class="text-sm text-slate-600">Choose a destination office, then scan each QR code and press Enter to release it in bulk.</p>
                </CardHeader>
                <CardContent class="space-y-5">
                    <div>
                        <label for="bulk-destination-office" class="mb-2 block text-sm font-semibold text-slate-700">Destination Office</label>
                        <select id="bulk-destination-office" v-model="officeId" :disabled="loadingOffices || saving" class="h-11 w-full rounded-md border border-slate-300 bg-white px-3 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500" @change="scanner?.focus()">
                            <option value="">Choose an office</option>
                            <option v-for="office in offices" :key="office.id" :value="String(office.id)">{{ office.office_name }}{{ office.office_code ? ` (${office.office_code})` : '' }}</option>
                        </select>
                    </div>

                    <div>
                        <label for="bulk-release-remarks" class="mb-2 block text-sm font-semibold text-slate-700">Remarks <span class="font-normal text-slate-500">(optional)</span></label>
                        <textarea id="bulk-release-remarks" v-model="remarks" :disabled="saving" rows="3" maxlength="2000" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500" placeholder="Applied to each released document" />
                    </div>

                    <BulkStatusScanner ref="scanner" :disabled="loadingOffices || saving || !officeId" @scan="processScan" />
                    <p v-if="error" class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert">{{ error }}</p>
                </CardContent>
            </Card>

            <Card v-if="results.length">
                <CardHeader><CardTitle>Released Documents</CardTitle></CardHeader>
                <CardContent class="space-y-2">
                    <div v-for="result in results" :key="result.id" class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">
                        <span class="font-semibold">{{ result.title }}</span> — {{ result.qr }} released to {{ result.officeName }}.
                    </div>
                </CardContent>
            </Card>
        </div>
    </section>
</template>
