<script setup>
import { nextTick, ref } from 'vue'
import { Input } from '@/components/ui/input'
import CameraQrScanner from '@/components/CameraQrScanner.vue'
import { normalizeRegistrationQrInput } from '@/lib/qr-registration'

defineProps({
    disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['scan'])
const value = ref('')
const input = ref(null)

const submit = () => {
    const token = normalizeRegistrationQrInput(value.value)
    if (!token) return

    value.value = ''
    emit('scan', token)
}

const focus = async () => {
    await nextTick()
    input.value?.$el?.focus()
}

defineExpose({ focus })
</script>

<template>
    <div>
        <label for="bulk-status-scanner" class="mb-2 block text-sm font-semibold text-slate-700">Scan QR Code</label>
        <div class="relative">
            <Input
                id="bulk-status-scanner"
                ref="input"
                v-model="value"
                :disabled="disabled"
                placeholder="Scan a document QR code, then press Enter"
                class="h-12 pr-14"
                autocomplete="off"
                @keydown.enter.prevent="submit"
            />
            <div class="absolute right-1 top-1/2 -translate-y-1/2">
                <CameraQrScanner :disabled="disabled" @scan="token => emit('scan', token)" />
            </div>
        </div>
        <p class="mt-2 text-xs text-slate-500">The selected Current Action is saved immediately after a valid scan.</p>
    </div>
</template>
