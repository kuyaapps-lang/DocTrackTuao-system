<script setup>
import { computed, ref } from 'vue'
import {
    RouterView,
    useRoute,
    useRouter,
} from 'vue-router'

import AppSidebar from '@/components/AppSidebar.vue'
import { Button } from '@/components/ui/button'
import { useAuth } from '@/lib/auth'

const route = useRoute()
const router = useRouter()

const {
    currentUser,
    getToken,
    clearCurrentUser,
} = useAuth()

const logoutPending = ref(false)
const logoutError = ref('')

const pageTitle = computed(() => {
    return route.meta?.title || 'DocTrack Tuao'
})

const userName = computed(() => {
    return currentUser.value?.name || ''
})

const roleLabel = computed(() => {
    return currentUser.value?.role?.name || ''
})

const clearLocalAuthentication = async () => {
    localStorage.removeItem('auth_token')
    localStorage.removeItem('auth_user')
    clearCurrentUser()

    await router.replace('/login')
}

const logout = async () => {
    if (logoutPending.value) {
        return
    }

    logoutError.value = ''

    const token = getToken()

    if (!token) {
        await clearLocalAuthentication()
        return
    }

    logoutPending.value = true

    try {
        const response = await fetch('/api/logout', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                Authorization: `Bearer ${token}`,
            },
        })

        if (response.status === 200 || response.status === 401) {
            await clearLocalAuthentication()
            return
        }

        logoutError.value =
            'Unable to logout right now. Please try again.'
    } catch {
        logoutError.value =
            'Unable to logout right now. Please try again.'
    } finally {
        logoutPending.value = false
    }
}
</script>

<template>
    <div class="flex min-h-screen bg-gray-100">
        <AppSidebar />

        <div class="min-w-0 flex-1">
            <header
                class="flex min-h-20 items-center justify-between gap-4 border-b bg-white px-6 py-4"
            >
                <div>
                    <h1 class="text-xl font-bold text-gray-900">
                        {{ pageTitle }}
                    </h1>

                    <p
                        v-if="userName || roleLabel"
                        class="mt-1 text-sm text-gray-500"
                    >
                        <span v-if="userName">{{ userName }}</span>
                        <span v-if="userName && roleLabel"> · </span>
                        <span v-if="roleLabel">{{ roleLabel }}</span>
                    </p>
                </div>

                <div class="flex flex-col items-end gap-1">
                    <Button
                        type="button"
                        :disabled="logoutPending"
                        @click="logout"
                    >
                        {{ logoutPending ? 'Logging out...' : 'Logout' }}
                    </Button>

                    <p
                        v-if="logoutError"
                        class="text-sm text-red-600"
                        role="alert"
                    >
                        {{ logoutError }}
                    </p>
                </div>
            </header>

            <main class="min-w-0">
                <RouterView />
            </main>
        </div>
    </div>
</template>
