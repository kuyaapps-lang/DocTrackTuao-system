<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'

import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { useAuth } from '@/lib/auth'
import {
    changePasswordRequest,
    validatePasswordChangeForm,
} from '@/lib/password-reset'

const router = useRouter()
const {
    clearCurrentUser,
    getToken,
} = useAuth()

const currentPassword = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const error = ref('')
const saving = ref(false)

const clearLocalAuthentication = async () => {
    localStorage.removeItem('auth_token')
    localStorage.removeItem('auth_user')
    clearCurrentUser()

    await router.replace({
        path: '/login',
        query: {
            password_changed: '1',
        },
    })
}

const submitPasswordChange = async () => {
    error.value = ''

    const validationError = validatePasswordChangeForm({
        currentPassword: currentPassword.value,
        password: password.value,
        passwordConfirmation: passwordConfirmation.value,
    })

    if (validationError) {
        error.value = validationError
        return
    }

    saving.value = true

    try {
        await changePasswordRequest({
            token: getToken(),
            currentPassword: currentPassword.value,
            password: password.value,
            passwordConfirmation: passwordConfirmation.value,
        })

        currentPassword.value = ''
        password.value = ''
        passwordConfirmation.value = ''

        await clearLocalAuthentication()
    } catch (err) {
        error.value =
            err.message ||
            'Unable to change password.'
    } finally {
        saving.value = false
    }
}
</script>

<template>
    <div class="min-h-screen bg-gray-100 p-6">
        <div class="mx-auto max-w-xl">
            <Card class="bg-white">
                <CardHeader>
                    <CardTitle>
                        Change Password
                    </CardTitle>

                    <p class="mt-1 text-sm text-gray-500">
                        Enter your temporary password and choose a new password before continuing.
                    </p>
                </CardHeader>

                <CardContent>
                    <form
                        class="space-y-5"
                        @submit.prevent="submitPasswordChange"
                    >
                        <div>
                            <label class="mb-2 block text-sm font-semibold text-gray-700">
                                Current Temporary Password *
                            </label>

                            <Input
                                v-model="currentPassword"
                                :disabled="saving"
                                type="password"
                                placeholder="Enter temporary password"
                            />
                        </div>

                        <div>
                            <label class="mb-2 block text-sm font-semibold text-gray-700">
                                New Password *
                            </label>

                            <Input
                                v-model="password"
                                :disabled="saving"
                                type="password"
                                placeholder="Minimum 8 characters"
                            />
                        </div>

                        <div>
                            <label class="mb-2 block text-sm font-semibold text-gray-700">
                                Confirm New Password *
                            </label>

                            <Input
                                v-model="passwordConfirmation"
                                :disabled="saving"
                                type="password"
                                placeholder="Repeat new password"
                            />
                        </div>

                        <div
                            v-if="error"
                            class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-600"
                        >
                            {{ error }}
                        </div>

                        <Button
                            type="submit"
                            class="w-full bg-blue-600 text-white hover:bg-blue-700"
                            :disabled="saving"
                        >
                            {{ saving ? 'Saving...' : 'Change Password' }}
                        </Button>
                    </form>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
