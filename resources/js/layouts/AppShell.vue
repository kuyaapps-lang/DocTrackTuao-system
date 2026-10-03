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
import NotificationBell from '@/components/NotificationBell.vue'
import ThemeToggle from '@/components/ThemeToggle.vue'
import { Button } from '@/components/ui/button'
import { useAuth } from '@/lib/auth'
import {
    Menu,
} from 'lucide-vue-next'

const route = useRoute()
const router = useRouter()

const {
    getToken,
    clearCurrentUser,
} = useAuth()

const logoutPending = ref(false)
const logoutError = ref('')
const desktopSidebarCollapsed = ref(false)
const mobileNavigationOpen = ref(false)
const menuTrigger = ref(null)
const sidebar = ref(null)

let desktopMediaQuery = null
let previousBodyOverflow = ''

const pageTitle = computed(() => {
    return route.meta?.title || 'DocTrack Tuao'
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

    desktopMediaQuery = window.matchMedia('(min-width: 768px)')
    desktopMediaQuery.addEventListener(
        'change',
        handleDesktopBreakpoint
    )
})

onBeforeUnmount(() => {
    document.removeEventListener('keydown', handleDocumentKeydown)
    desktopMediaQuery?.removeEventListener(
        'change',
        handleDesktopBreakpoint
    )

    if (mobileNavigationOpen.value) {
        document.body.style.overflow = previousBodyOverflow
    }
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
    <div class="doctrack-shell flex min-h-screen bg-slate-100 bg-[#eaf1ff] text-slate-800 transition-colors duration-200">
        <AppSidebar
            ref="sidebar"
            :desktop-collapsed="desktopSidebarCollapsed"
            :mobile-open="mobileNavigationOpen"
            :logout-pending="logoutPending"
            :logout-error="logoutError"
            @toggle-desktop="desktopSidebarCollapsed = !desktopSidebarCollapsed"
            @close-mobile="closeMobileNavigation()"
            @navigate="closeMobileNavigation()"
            @logout="logout"
        />

        <div class="min-w-0 flex-1 bg-slate-100 bg-white/35 pt-20 transition-[margin,background-color] duration-200" :class="desktopSidebarCollapsed ? 'md:ml-20' : 'md:ml-64'">
            <header
                class="fixed inset-x-0 top-0 z-20 flex min-h-20 items-center justify-between gap-4 border-b border-white/80 bg-white/80 px-6 py-4 shadow-[0_10px_30px_rgb(67_86_119/0.10),inset_0_1px_0_rgb(255_255_255/0.9)] backdrop-blur-xl transition-[left,colors] duration-200"
                :class="desktopSidebarCollapsed ? 'md:left-20' : 'md:left-64'"
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
                        <h1 class="text-xl font-bold tracking-[-0.01em] text-slate-900">
                            {{ pageTitle }}
                        </h1>
                    </div>
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    <ThemeToggle />
                    <NotificationBell />
                </div>
            </header>

            <main class="min-w-0">
                <RouterView />
            </main>
        </div>
    </div>
</template>
