<script setup>
import { nextTick, onBeforeUnmount, ref } from 'vue'
import { ScanLine, X } from 'lucide-vue-next'
import { BrowserQRCodeReader } from '@zxing/browser'

import { normalizeRegistrationQrInput } from '@/lib/qr-registration'

defineProps({
    disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['scan', 'error'])
const open = ref(false)
const video = ref(null)
const message = ref('')
let controls = null
let stream = null

const stop = () => {
    controls?.stop()
    controls = null
    stream?.getTracks().forEach(track => track.stop())
    stream = null
    if (video.value) video.value.srcObject = null
    open.value = false
}

const cameraErrorMessage = error => {
    switch (error?.name) {
        case 'NotAllowedError':
        case 'SecurityError':
            return 'Camera permission was blocked for this site. Allow camera access from the browser address bar. A LAN address also requires HTTPS; localhost is allowed for development.'
        case 'NotFoundError':
            return 'No camera was detected. Connect or enable a webcam, then try again.'
        case 'NotReadableError':
            return 'The camera is being used by another app. Close Camera, Teams, Zoom, or similar apps, then try again.'
        default:
            return 'Unable to access the camera. Check your webcam and browser permission, then try again.'
    }
}

const beginDecoding = async constraints => {
    stream = await navigator.mediaDevices.getUserMedia(constraints)
    video.value.srcObject = stream
    await video.value.play()

    const reader = new BrowserQRCodeReader()
    controls = await reader.decodeFromVideoElement(
        video.value,
        result => {
            const token = normalizeRegistrationQrInput(result?.getText?.() || '')
            if (!token) return
            emit('scan', token)
            stop()
        }
    )
}

const start = async () => {
    message.value = ''
    open.value = true

    if (!window.isSecureContext) {
        message.value = 'Camera access requires localhost or HTTPS. This page is currently using an insecure address; open it through localhost on this PC or configure HTTPS for the LAN address.'
        open.value = false
        emit('error', message.value)
        return
    }

    if (!navigator.mediaDevices?.getUserMedia) {
        message.value = 'This browser cannot access a camera. Use a current browser over localhost or HTTPS, or type the code instead.'
        open.value = false
        emit('error', message.value)
        return
    }

    await nextTick()

    try {
        await beginDecoding({ video: { facingMode: { ideal: 'environment' } }, audio: false })
    } catch (error) {
        if (error?.name === 'OverconstrainedError') {
            try {
                stop()
                open.value = true
                await nextTick()
                await beginDecoding({ video: true, audio: false })
                return
            } catch (fallbackError) {
                error = fallbackError
            }
        }

        message.value = cameraErrorMessage(error)
        stop()
        emit('error', message.value)
    }
}

onBeforeUnmount(stop)
</script>

<template>
    <div class="relative">
        <button type="button" :disabled="disabled" class="inline-flex size-11 items-center justify-center rounded-md border border-blue-200 bg-blue-50 text-blue-800 transition-colors hover:bg-blue-100 disabled:cursor-not-allowed disabled:opacity-50" aria-label="Open camera QR scanner" title="Open camera QR scanner" @click="start">
            <ScanLine class="size-5" aria-hidden="true" />
        </button>
        <Teleport to="body">
            <div v-if="open" class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/70 p-4" role="dialog" aria-modal="true" aria-labelledby="camera-preview-title">
                <section class="rounded-2xl bg-white p-5 shadow-2xl" style="width: min(96vw, 1152px)">
                    <div class="mb-4 flex items-start justify-between gap-3">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="size-2 rounded-full bg-emerald-500" aria-hidden="true" />
                                <h2 id="camera-preview-title" class="font-semibold text-slate-900">Camera preview</h2>
                            </div>
                            <p class="mt-1 text-sm text-slate-600">Hold the QR code inside the camera frame. It will scan automatically.</p>
                        </div>
                        <button type="button" class="rounded-md border border-slate-200 p-2 text-slate-600 hover:bg-slate-100" aria-label="Close camera preview" @click="stop"><X class="size-5" /></button>
                    </div>
                    <video ref="video" class="aspect-video max-h-[70vh] w-full rounded-xl bg-slate-950 object-cover" playsinline muted autoplay />
                    <p class="mt-3 text-center text-xs text-slate-500">The camera turns off automatically after a QR code is scanned.</p>
                </section>
            </div>
        </Teleport>
        <p v-if="message" class="absolute left-0 top-full z-50 mt-2 w-72 rounded-md border border-red-200 bg-red-50 p-2 text-xs text-red-700 shadow-sm" role="alert">{{ message }}</p>
    </div>
</template>
