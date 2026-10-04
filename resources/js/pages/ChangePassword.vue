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
import {
    Eye,
    EyeOff,
    KeyRound,
} from 'lucide-vue-next'
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
const showCurrentPassword = ref(false)
const showPassword = ref(false)
const showPasswordConfirmation = ref(false)
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
    <div class="min-h-screen bg-slate-100 p-6">
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
                                Current Temporary Password <span class="text-red-600">*</span>
                            </label>

                            <div class="relative">
                                <Input
                                    v-model="currentPassword"
                                    :disabled="saving"
                                    :type="showCurrentPassword ? 'text' : 'password'"
                                    placeholder="Enter temporary password"
                                    class="pr-11"
                                />

                                <button
                                    type="button"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-800"
                                    :aria-label="showCurrentPassword ? 'Hide temporary password' : 'Show temporary password'"
                                    :disabled="saving"
                                    @click="showCurrentPassword = !showCurrentPassword"
                                >
                                    <EyeOff
                                        v-if="showCurrentPassword"
                                        class="h-4 w-4"
                                    />
                                    <Eye
                                        v-else
                                        class="h-4 w-4"
                                    />
                                </button>
                            </div>
                        </div>

                        <div>
                            <label class="mb-2 block text-sm font-semibold text-gray-700">
                                New Password <span class="text-red-600">*</span>
                            </label>

                            <div class="relative">
                                <Input
                                    v-model="password"
                                    :disabled="saving"
                                    :type="showPassword ? 'text' : 'password'"
                                    placeholder="Minimum 8 characters"
                                    class="pr-11"
                                />

                                <button
                                    type="button"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-800"
                                    :aria-label="showPassword ? 'Hide new password' : 'Show new password'"
                                    :disabled="saving"
                                    @click="showPassword = !showPassword"
                                >
                                    <EyeOff
                                        v-if="showPassword"
                                        class="h-4 w-4"
                                    />
                                    <Eye
                                        v-else
                                        class="h-4 w-4"
                                    />
                                </button>
                            </div>
                        </div>

                        <div>
                            <label class="mb-2 block text-sm font-semibold text-gray-700">
                                Confirm New Password <span class="text-red-600">*</span>
                            </label>

                            <div class="relative">
                                <Input
                                    v-model="passwordConfirmation"
                                    :disabled="saving"
                                    :type="showPasswordConfirmation ? 'text' : 'password'"
                                    placeholder="Repeat new password"
                                    class="pr-11"
                                />

                                <button
                                    type="button"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-800"
                                    :aria-label="showPasswordConfirmation ? 'Hide confirmed password' : 'Show confirmed password'"
                                    :disabled="saving"
                                    @click="showPasswordConfirmation = !showPasswordConfirmation"
                                >
                                    <EyeOff
                                        v-if="showPasswordConfirmation"
                                        class="h-4 w-4"
                                    />
                                    <Eye
                                        v-else
                                        class="h-4 w-4"
                                    />
                                </button>
                            </div>
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
                            <KeyRound class="mr-2 h-4 w-4" />
                            {{ saving ? 'Saving...' : 'Change Password' }}
                        </Button>
                    </form>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
