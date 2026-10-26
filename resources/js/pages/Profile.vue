<script setup>
import { computed, ref } from 'vue'
import {
    Building2,
    KeyRound,
    Mail,
    ShieldCheck,
    UserRound,
} from 'lucide-vue-next'

import { Button } from '@/components/ui/button'
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card'
import { useAuth } from '@/lib/auth'
import { submitPasswordChangeApprovalRequest } from '@/lib/password-reset'

const { currentUser, getToken } = useAuth()
const requestOpen = ref(false)
const saving = ref(false)
const error = ref('')
const success = ref('')
const form = ref({ password: '', password_confirmation: '' })

const userName = computed(() => currentUser.value?.name || 'Signed-in user')
const userInitials = computed(() => userName.value
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map(name => name[0])
    .join('')
    .toUpperCase() || 'U')

const profileFields = computed(() => [
    {
        label: 'Username',
        value: currentUser.value?.username || 'Not available',
        icon: UserRound,
    },
    {
        label: 'Email address',
        value: currentUser.value?.email || 'Not available',
        icon: Mail,
    },
    {
        label: 'Role',
        value: currentUser.value?.role?.name || 'Not assigned',
        icon: ShieldCheck,
    },
    {
        label: 'Office',
        value: currentUser.value?.office?.office_name || currentUser.value?.office?.name || 'Not assigned',
        icon: Building2,
    },
])

const openRequest = () => {
    error.value = ''
    form.value = { password: '', password_confirmation: '' }
    requestOpen.value = true
}

const closeRequest = () => {
    if (saving.value) return
    requestOpen.value = false
    error.value = ''
}

const submitRequest = async () => {
    error.value = ''
    if (form.value.password.length < 8) {
        error.value = 'New password must be at least 8 characters.'
        return
    }
    if (form.value.password !== form.value.password_confirmation) {
        error.value = 'Password confirmation does not match.'
        return
    }

    saving.value = true
    try {
        const data = await submitPasswordChangeApprovalRequest({
            token: getToken(),
            password: form.value.password,
            passwordConfirmation: form.value.password_confirmation,
        })
        success.value = data.message
        requestOpen.value = false
        form.value = { password: '', password_confirmation: '' }
    } catch (err) {
        error.value = err.message || 'Unable to request a password change.'
    } finally {
        saving.value = false
    }
}
</script>

<template>
    <section class="min-h-screen bg-slate-100 p-4 sm:p-6" aria-labelledby="profile-heading">
        <div class="mx-auto max-w-3xl">
            <Card class="overflow-hidden border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <CardHeader class="border-b border-slate-200 dark:border-slate-700">
                    <div class="flex items-center gap-4">
                        <div class="flex size-14 shrink-0 items-center justify-center rounded-full bg-blue-900 text-lg font-bold text-white" aria-hidden="true">
                            {{ userInitials }}
                        </div>
                        <div class="min-w-0">
                            <CardTitle id="profile-heading" class="truncate text-xl text-blue-950 dark:text-slate-100">
                                {{ userName }}
                            </CardTitle>
                            <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">Your account details</p>
                        </div>
                    </div>
                </CardHeader>

                <CardContent class="p-0">
                    <p v-if="success" class="mx-5 mt-5 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-100" role="status">{{ success }}</p>
                    <dl class="divide-y divide-slate-200 dark:divide-slate-700">
                        <div v-for="field in profileFields" :key="field.label" class="flex items-center gap-3 px-5 py-4">
                            <component :is="field.icon" class="size-5 shrink-0 text-blue-800 dark:text-blue-300" aria-hidden="true" />
                            <div class="min-w-0">
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ field.label }}</dt>
                                <dd class="truncate text-sm font-medium text-slate-800 dark:text-slate-100">{{ field.value }}</dd>
                            </div>
                        </div>
                    </dl>

                    <div class="border-t border-slate-200 px-5 py-4 dark:border-slate-700">
                        <Button size="sm" class="bg-blue-700 text-white hover:bg-blue-800" @click="openRequest">
                            <KeyRound class="size-4" aria-hidden="true" />
                            Request Password Change
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>

        <div v-if="requestOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 px-4 backdrop-blur-sm" @click.self="closeRequest">
            <section class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl dark:border-slate-700 dark:bg-slate-900" role="dialog" aria-modal="true" aria-labelledby="request-password-title">
                <h2 id="request-password-title" class="text-xl font-bold text-slate-900 dark:text-white">Request Administrator Approval</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Your existing password remains active unless an administrator approves this change.</p>
                <form class="mt-5 space-y-4" @submit.prevent="submitRequest">
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-200">New password<input v-model="form.password" :disabled="saving" type="password" minlength="8" autocomplete="new-password" class="mt-1.5 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-slate-900 outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100" required></label>
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-200">Confirm new password<input v-model="form.password_confirmation" :disabled="saving" type="password" minlength="8" autocomplete="new-password" class="mt-1.5 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-slate-900 outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100" required></label>
                    <p v-if="error" class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/60 dark:text-red-100" role="alert">{{ error }}</p>
                    <div class="flex justify-end gap-3"><Button type="button" variant="outline" :disabled="saving" @click="closeRequest">Cancel</Button><Button type="submit" :disabled="saving">{{ saving ? 'Sending...' : 'Request approval' }}</Button></div>
                </form>
            </section>
        </div>
    </section>
</template>
