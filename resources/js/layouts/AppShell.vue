<script setup>
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue'
import {
    RouterView,
    useRoute,
    useRouter,
} from 'vue-router'

import AppSidebar from '@/components/AppSidebar.vue'
import { Button } from '@/components/ui/button'
import { useAuth } from '@/lib/auth'
import { CircleUserRound, Menu } from 'lucide-vue-next'

const route = useRoute()
const router = useRouter()

const {
    currentUser,
    getToken,
    clearCurrentUser,
} = useAuth()

const logoutPending = ref(false)
const logoutError = ref('')
const desktopSidebarCollapsed = ref(false)
const mobileNavigationOpen = ref(false)
const accountMenuOpen = ref(false)
const menuTrigger = ref(null)
const accountMenu = ref(null)
const sidebar = ref(null)

let desktopMediaQuery = null
let previousBodyOverflow = ''

const pageTitle = computed(() => {
    return route.meta?.title || 'DocTrack Tuao'
})

const userName = computed(() => {
    return currentUser.value?.name || ''
})

const roleLabel = computed(() => {
    return currentUser.value?.role?.name || ''
})

const officeLabel = computed(() => {
    return currentUser.value?.office?.office_name ||
        currentUser.value?.office?.name ||
        ''
})

const openMobileNavigation = async () => {
    mobileNavigationOpen.value = true

    await nextTick()
    sidebar.value?.focusMobileClose()
}

const closeMobileNavigation = async (restoreFocus = true) => {
    if (!mobileNavigationOpen.value) {
        return
    }

    mobileNavigationOpen.value = false

    if (restoreFocus) {
        await nextTick()
        menuTrigger.value?.$el?.focus()
    }
}

const handleDocumentKeydown = (event) => {
    if (event.key === 'Escape' && accountMenuOpen.value) {
        accountMenuOpen.value = false
    }

    if (!mobileNavigationOpen.value) {
        return
    }

    if (event.key === 'Escape') {
        event.preventDefault()
        closeMobileNavigation()
        return
    }

    if (event.key !== 'Tab') {
        return
    }

    const drawer = document.getElementById('mobile-navigation-drawer')
    const focusableElements = drawer?.querySelectorAll(
        'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])'
    )

    if (!focusableElements?.length) {
        event.preventDefault()
        return
    }

    const firstElement = focusableElements[0]
    const lastElement = focusableElements[focusableElements.length - 1]

    if (event.shiftKey && document.activeElement === firstElement) {
        event.preventDefault()
        lastElement.focus()
    } else if (
        !event.shiftKey &&
        document.activeElement === lastElement
    ) {
        event.preventDefault()
        firstElement.focus()
    }
}

const handleAccountOutsideClick = (event) => {
    if (!accountMenuOpen.value) {
        return
    }

    if (accountMenu.value?.contains(event.target)) {
        return
    }

    accountMenuOpen.value = false
}

const handleDesktopBreakpoint = (event) => {
    if (event.matches) {
        closeMobileNavigation(false)
    }
}

watch(mobileNavigationOpen, (isOpen) => {
    if (isOpen) {
        previousBodyOverflow = document.body.style.overflow
        document.body.style.overflow = 'hidden'
        return
    }

    document.body.style.overflow = previousBodyOverflow
})

watch(() => route.fullPath, () => {
    closeMobileNavigation()
})

onMounted(() => {
    document.addEventListener('keydown', handleDocumentKeydown)
    document.addEventListener('pointerdown', handleAccountOutsideClick)

    desktopMediaQuery = window.matchMedia('(min-width: 768px)')
    desktopMediaQuery.addEventListener(
        'change',
        handleDesktopBreakpoint
    )
})

onBeforeUnmount(() => {
    document.removeEventListener('keydown', handleDocumentKeydown)
    document.removeEventListener('pointerdown', handleAccountOutsideClick)
    desktopMediaQuery?.removeEventListener(
        'change',
        handleDesktopBreakpoint
    )

    if (mobileNavigationOpen.value) {
        document.body.style.overflow = previousBodyOverflow
    }
})

const clearLocalAuthentication = async () => {
    accountMenuOpen.value = false
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

const toggleAccountMenu = () => {
    accountMenuOpen.value = !accountMenuOpen.value
}
</script>

<template>
    <div class="flex min-h-screen bg-gray-100">
        <AppSidebar
            ref="sidebar"
            :desktop-collapsed="desktopSidebarCollapsed"
            :mobile-open="mobileNavigationOpen"
            @toggle-desktop="desktopSidebarCollapsed = !desktopSidebarCollapsed"
            @close-mobile="closeMobileNavigation()"
            @navigate="closeMobileNavigation()"
        />

        <div class="min-w-0 flex-1">
            <header
                class="flex min-h-20 items-center justify-between gap-4 border-b bg-white px-6 py-4 shadow-sm"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <Button
                        ref="menuTrigger"
                        type="button"
                        variant="outline"
                        size="icon"
                        class="shrink-0 md:hidden"
                        aria-label="Open main navigation"
                        aria-controls="mobile-navigation-drawer"
                        :aria-expanded="mobileNavigationOpen"
                        @click="openMobileNavigation"
                    >
                        <Menu aria-hidden="true" />
                    </Button>

                    <div class="min-w-0">
                        <h1 class="text-xl font-bold text-gray-900">
                            {{ pageTitle }}
                        </h1>
                    </div>
                </div>

                <div ref="accountMenu" class="relative shrink-0">
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        class="h-11 w-11 rounded-full border-blue-200 bg-blue-50 text-blue-800 hover:bg-blue-100"
                        aria-label="Open account menu"
                        aria-haspopup="menu"
                        :aria-expanded="accountMenuOpen"
                        @click="toggleAccountMenu"
                    >
                        <CircleUserRound aria-hidden="true" class="h-6 w-6" />
                    </Button>

                    <div
                        v-if="accountMenuOpen"
                        class="absolute right-0 z-40 mt-2 w-64 rounded-lg border border-blue-100 bg-white p-4 text-sm shadow-lg"
                        role="menu"
                        aria-label="Account menu"
                    >
                        <div class="space-y-1 border-b border-blue-100 pb-3">
                            <p class="truncate text-sm font-semibold text-blue-950">
                                {{ userName || 'Signed-in user' }}
                            </p>
                            <p
                                v-if="roleLabel"
                                class="text-xs font-medium uppercase tracking-wide text-blue-700"
                            >
                                {{ roleLabel }}
                            </p>
                            <p
                                v-if="officeLabel"
                                class="truncate text-xs text-gray-600"
                            >
                                {{ officeLabel }}
                            </p>
                        </div>

                        <Button
                            type="button"
                            :disabled="logoutPending"
                            variant="outline"
                            size="sm"
                            class="mt-3 w-full border-blue-200 bg-white text-blue-800 hover:bg-blue-100"
                            role="menuitem"
                            @click="logout"
                        >
                            {{ logoutPending ? 'Logging out...' : 'Logout' }}
                        </Button>

                        <p
                            v-if="logoutError"
                            class="mt-2 text-sm text-red-600"
                            role="alert"
                        >
                            {{ logoutError }}
                        </p>
                    </div>
                </div>
            </header>

            <main class="min-w-0">
                <RouterView />
            </main>
        </div>
    </div>
</template>
