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
import { Button } from '@/components/ui/button'
import { useAuth } from '@/lib/auth'
import { submitPasswordChangeApprovalRequest } from '@/lib/password-reset'
import { DARK_THEME, useTheme } from '@/lib/theme'
import {
    ChevronDown,
    Menu,
} from 'lucide-vue-next'

const route = useRoute()
const router = useRouter()

const {
    currentUser,
    getToken,
    clearCurrentUser,
} = useAuth()
const { theme, toggleTheme } = useTheme()

const logoutPending = ref(false)
const logoutError = ref('')
const logoutConfirmationOpen = ref(false)
const desktopSidebarCollapsed = ref(false)
const mobileNavigationOpen = ref(false)
const menuTrigger = ref(null)
const sidebar = ref(null)
const confirmLogoutButton = ref(null)
const headerProfile = ref(null)
const headerProfileMenuOpen = ref(false)
const profileDetailsOpen = ref(false)
const passwordChangeRequestOpen = ref(false)
const passwordChangeRequestSaving = ref(false)
const passwordChangeRequestError = ref('')
const passwordChangeRequestForm = ref({ password: '', password_confirmation: '' })

let logoutTrigger = null

let desktopMediaQuery = null
let previousBodyOverflow = ''

const pageTitle = computed(() => {
    if (route.path === '/documents') {
        return route.query.view === 'incoming'
            ? 'Incoming Documents'
            : 'Outgoing Documents'
    }

    return route.meta?.title || 'DocTrack Tuao'
})

const userName = computed(() => currentUser.value?.name || 'Signed-in user')

const officeName = computed(() => {
    return currentUser.value?.office?.office_name ||
        currentUser.value?.office?.name ||
        'No office assigned'
})

const userInitials = computed(() => {
    return userName.value
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map(name => name[0])
        .join('')
        .toUpperCase() || 'U'
})

const closeHeaderProfileMenu = () => {
    headerProfileMenuOpen.value = false
}

const toggleHeaderProfileMenu = () => {
    headerProfileMenuOpen.value = !headerProfileMenuOpen.value
}

const openProfileDetails = () => {
    closeHeaderProfileMenu()
    profileDetailsOpen.value = true
}

const closeProfileDetails = () => {
    profileDetailsOpen.value = false
}

const openPasswordChangeRequest = () => {
    passwordChangeRequestError.value = ''
    passwordChangeRequestForm.value = { password: '', password_confirmation: '' }
    passwordChangeRequestOpen.value = true
}

const closePasswordChangeRequest = () => {
    if (passwordChangeRequestSaving.value) return

    passwordChangeRequestOpen.value = false
    passwordChangeRequestError.value = ''
    passwordChangeRequestForm.value = { password: '', password_confirmation: '' }
}

const submitPasswordChangeRequest = async () => {
    passwordChangeRequestError.value = ''

    if (passwordChangeRequestForm.value.password.length < 8) {
        passwordChangeRequestError.value = 'New password must be at least 8 characters.'
        return
    }

    if (passwordChangeRequestForm.value.password !== passwordChangeRequestForm.value.password_confirmation) {
        passwordChangeRequestError.value = 'Password confirmation does not match.'
        return
    }

    passwordChangeRequestSaving.value = true

    try {
        await submitPasswordChangeApprovalRequest({
            token: getToken(),
            password: passwordChangeRequestForm.value.password,
            passwordConfirmation: passwordChangeRequestForm.value.password_confirmation,
        })
        closeProfileDetails()
        passwordChangeRequestOpen.value = false
        passwordChangeRequestForm.value = { password: '', password_confirmation: '' }
        window.alert('Your password change request was sent to an administrator for approval.')
    } catch (error) {
        passwordChangeRequestError.value = error.message || 'Unable to request a password change.'
    } finally {
        passwordChangeRequestSaving.value = false
    }
}

const closeHeaderProfileOnOutsideClick = (event) => {
    if (!headerProfile.value?.contains(event.target)) {
        closeHeaderProfileMenu()
    }
}

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
    if (passwordChangeRequestOpen.value && event.key === 'Escape') {
        event.preventDefault()
        closePasswordChangeRequest()
        return
    }

    if (logoutConfirmationOpen.value) {
        if (event.key === 'Escape') {
            event.preventDefault()
            closeLogoutConfirmation()
            return
        }

        if (event.key !== 'Tab') {
            return
        }

        const dialog = document.getElementById('logout-confirmation-dialog')
        const focusableElements = dialog?.querySelectorAll(
            'button:not([disabled]), [tabindex]:not([tabindex="-1"])'
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
        } else if (!event.shiftKey && document.activeElement === lastElement) {
            event.preventDefault()
            firstElement.focus()
        }

        return
    }

    if (profileDetailsOpen.value && event.key === 'Escape') {
        event.preventDefault()
        closeProfileDetails()
        return
    }

    if (headerProfileMenuOpen.value && event.key === 'Escape') {
        event.preventDefault()
        closeHeaderProfileMenu()
        return
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
    document.addEventListener('pointerdown', closeHeaderProfileOnOutsideClick)

    desktopMediaQuery = window.matchMedia('(min-width: 768px)')
    desktopMediaQuery.addEventListener(
        'change',
        handleDesktopBreakpoint
    )
})

onBeforeUnmount(() => {
    document.removeEventListener('keydown', handleDocumentKeydown)
    document.removeEventListener('pointerdown', closeHeaderProfileOnOutsideClick)
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

const requestLogout = async () => {
    if (logoutPending.value) {
        return
    }

    logoutTrigger = document.activeElement
    closeHeaderProfileMenu()
    logoutConfirmationOpen.value = true

    await nextTick()
    confirmLogoutButton.value?.$el?.focus()
}

const closeLogoutConfirmation = async () => {
    logoutConfirmationOpen.value = false

    await nextTick()
    logoutTrigger?.focus?.()
    logoutTrigger = null
}

const logout = async () => {
    if (logoutPending.value) {
        return
    }

    logoutConfirmationOpen.value = false
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
            @toggle-desktop="desktopSidebarCollapsed = !desktopSidebarCollapsed"
            @close-mobile="closeMobileNavigation()"
            @navigate="closeMobileNavigation()"
        />

        <div class="min-w-0 flex-1 bg-slate-100 bg-white/35 pt-36 transition-[margin,background-color] duration-200 md:pt-20" :class="desktopSidebarCollapsed ? 'md:ml-20' : 'md:ml-64'">
            <header
                class="fixed inset-x-0 top-0 z-20 flex min-h-36 flex-col items-stretch justify-start gap-2 border-b border-white/80 bg-white/80 px-4 py-3 shadow-[0_10px_30px_rgb(67_86_119/0.10),inset_0_1px_0_rgb(255_255_255/0.9)] backdrop-blur-xl transition-[left,colors] duration-200 md:min-h-20 md:flex-row md:items-center md:justify-between md:gap-4 md:px-6 md:py-4"
                :class="desktopSidebarCollapsed ? 'md:left-20' : 'md:left-64'"
            >
                <div class="flex min-w-0 items-center gap-3 md:flex-1">
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

                <div ref="headerProfile" class="absolute right-4 top-16 flex shrink-0 flex-row items-center gap-2 md:relative md:right-auto md:top-auto md:self-auto md:flex-row-reverse">
                    <button
                        type="button"
                        class="flex max-w-[calc(100vw-5.5rem)] items-center gap-2 rounded-xl px-2 py-1.5 text-right outline-none transition-colors hover:bg-blue-50 focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 dark:hover:bg-slate-800 md:max-w-48 md:text-left"
                        aria-label="Open profile menu"
                        aria-haspopup="menu"
                        :aria-expanded="headerProfileMenuOpen"
                        @click="toggleHeaderProfileMenu"
                    >
                        <span class="min-w-0 text-right md:text-left">
                            <span class="block truncate text-sm font-semibold text-blue-950 dark:text-slate-100">{{ userName }}</span>
                            <span class="hidden truncate text-xs text-slate-600 dark:text-slate-300 sm:block">{{ officeName }}</span>
                        </span>
                        <ChevronDown class="size-4 shrink-0 text-slate-500 dark:text-slate-300" aria-hidden="true" />
                    </button>

                    <NotificationBell @click.capture="closeHeaderProfileMenu">
                        <template #trigger="{ badgeLabel, open, toggle, unreadCount }">
                        <button
                            type="button"
                            class="relative flex size-9 shrink-0 items-center justify-center rounded-full bg-blue-900 text-xs font-bold text-white shadow-sm outline-none transition-colors hover:bg-blue-800 focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2"
                            aria-label="Open notifications"
                            aria-haspopup="menu"
                            :aria-expanded="open"
                            @click="toggle"
                        >
                            {{ userInitials }}
                            <span v-if="unreadCount" class="absolute -right-2 -top-2 flex h-5 min-w-5 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-bold leading-none text-white shadow-sm" :aria-label="`${unreadCount} unread notification${unreadCount === 1 ? '' : 's'}`">{{ badgeLabel }}</span>
                        </button>
                        </template>
                    </NotificationBell>

                    <div
                        v-if="headerProfileMenuOpen"
                        class="absolute right-0 top-full z-40 mt-3 w-72 rounded-2xl border border-white/80 bg-white/95 p-3 text-sm shadow-[0_18px_42px_rgb(67_86_119/0.16),inset_0_1px_0_rgb(255_255_255/0.92)] backdrop-blur-xl dark:border-slate-700 dark:bg-slate-900/95 dark:text-slate-100 dark:shadow-black/30"
                        role="menu"
                        aria-label="Profile menu"
                    >
                        <div class="border-b border-slate-200 px-2 pb-3 dark:border-slate-700">
                            <p class="truncate font-semibold text-blue-950 dark:text-white">{{ userName }}</p>
                            <p class="truncate text-xs text-slate-600 dark:text-slate-300">{{ officeName }}</p>
                        </div>

                        <button
                            type="button"
                            class="mt-2 flex rounded-xl px-3 py-2 font-medium text-slate-700 transition-colors hover:bg-blue-50 hover:text-blue-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:text-white"
                            role="menuitem"
                            @click="openProfileDetails"
                        >
                            View profile
                        </button>

                        <label class="mt-1 flex cursor-pointer items-center justify-between rounded-xl px-3 py-2 text-slate-700 transition-colors hover:bg-blue-50 dark:text-slate-200 dark:hover:bg-slate-800">
                            <span class="font-medium">Dark mode</span>
                            <input
                                type="checkbox"
                                class="peer sr-only"
                                role="switch"
                                :checked="theme === DARK_THEME"
                                aria-label="Toggle dark mode"
                                @change="toggleTheme()"
                            >
                            <span class="relative h-6 w-11 rounded-full bg-slate-300 transition-colors after:absolute after:left-1 after:top-1 after:size-4 after:rounded-full after:bg-white after:shadow-sm after:transition-transform peer-checked:bg-blue-800 peer-checked:after:translate-x-5 dark:bg-slate-600" aria-hidden="true" />
                        </label>

                        <div class="mt-2 border-t border-slate-200 pt-2 dark:border-slate-700">
                            <Button type="button" variant="ghost" size="sm" class="h-9 w-full justify-start bg-blue-900 px-3 py-1.5 text-white hover:bg-blue-800 hover:text-white dark:bg-blue-900 dark:text-white dark:hover:bg-blue-800 dark:hover:text-white" :disabled="logoutPending" role="menuitem" @click="requestLogout">
                                {{ logoutPending ? 'Logging out...' : 'Logout' }}
                            </Button>
                            <p v-if="logoutError" class="mt-2 text-sm text-red-600 dark:text-red-300" role="alert">{{ logoutError }}</p>
                        </div>
                    </div>
                </div>
            </header>

            <main class="min-w-0">
                <RouterView />
            </main>
        </div>

        <div
            v-if="profileDetailsOpen"
            class="fixed inset-0 z-[60] flex items-center justify-center bg-slate-950/50 px-4 backdrop-blur-sm"
            @click.self="closeProfileDetails"
        >
            <section
                class="w-full max-w-md overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900"
                role="dialog"
                aria-modal="true"
                aria-labelledby="profile-popout-title"
            >
                <div class="flex items-center gap-4 border-b border-slate-200 p-5 dark:border-slate-700">
                    <div class="flex size-12 shrink-0 items-center justify-center rounded-full bg-blue-900 text-sm font-bold text-white" aria-hidden="true">
                        {{ userInitials }}
                    </div>
                    <div class="min-w-0">
                        <h2 id="profile-popout-title" class="truncate text-lg font-bold text-blue-950 dark:text-white">{{ userName }}</h2>
                        <p class="text-sm text-slate-600 dark:text-slate-300">Your account details</p>
                    </div>
                </div>

                <dl class="divide-y divide-slate-200 dark:divide-slate-700">
                    <div class="px-5 py-3">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Username</dt>
                        <dd class="mt-1 break-words text-sm font-medium text-slate-800 dark:text-slate-100">{{ currentUser?.username || 'Not available' }}</dd>
                    </div>
                    <div class="px-5 py-3">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Email address</dt>
                        <dd class="mt-1 break-words text-sm font-medium text-slate-800 dark:text-slate-100">{{ currentUser?.email || 'Not available' }}</dd>
                    </div>
                    <div class="px-5 py-3">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Role</dt>
                        <dd class="mt-1 break-words text-sm font-medium text-slate-800 dark:text-slate-100">{{ currentUser?.role?.name || 'Not assigned' }}</dd>
                    </div>
                    <div class="px-5 py-3">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Office</dt>
                        <dd class="mt-1 break-words text-sm font-medium text-slate-800 dark:text-slate-100">{{ officeName }}</dd>
                    </div>
                </dl>

                <div class="flex justify-end gap-3 border-t border-slate-200 p-5 dark:border-slate-700">
                    <Button type="button" variant="outline" @click="closeProfileDetails">Close</Button>
                    <Button type="button" @click="openPasswordChangeRequest">
                        Request password change
                    </Button>
                </div>
            </section>
        </div>

        <div
            v-if="passwordChangeRequestOpen"
            class="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/50 px-4 backdrop-blur-sm"
            @click.self="closePasswordChangeRequest"
        >
            <section class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl dark:border-slate-700 dark:bg-slate-900" role="dialog" aria-modal="true" aria-labelledby="password-change-request-title">
                <h2 id="password-change-request-title" class="text-xl font-bold text-slate-900 dark:text-white">Request Administrator Approval</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Your current password stays active until an administrator approves this request. Your new password is stored only as a secure hash.</p>

                <form class="mt-5 space-y-4" @submit.prevent="submitPasswordChangeRequest">
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-200">
                        New password
                        <input v-model="passwordChangeRequestForm.password" :disabled="passwordChangeRequestSaving" type="password" minlength="8" autocomplete="new-password" class="mt-1.5 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-slate-900 outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 disabled:bg-slate-100 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:disabled:bg-slate-800" required>
                    </label>
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-200">
                        Confirm new password
                        <input v-model="passwordChangeRequestForm.password_confirmation" :disabled="passwordChangeRequestSaving" type="password" minlength="8" autocomplete="new-password" class="mt-1.5 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-slate-900 outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 disabled:bg-slate-100 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:disabled:bg-slate-800" required>
                    </label>
                    <p v-if="passwordChangeRequestError" class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900/70 dark:bg-red-950/50 dark:text-red-200" role="alert">{{ passwordChangeRequestError }}</p>
                    <div class="flex justify-end gap-3 pt-2">
                        <Button type="button" variant="outline" :disabled="passwordChangeRequestSaving" @click="closePasswordChangeRequest">Cancel</Button>
                        <Button type="submit" :disabled="passwordChangeRequestSaving">{{ passwordChangeRequestSaving ? 'Sending...' : 'Request approval' }}</Button>
                    </div>
                </form>
            </section>
        </div>

        <div
            v-if="logoutConfirmationOpen"
            class="fixed inset-0 z-[60] flex items-center justify-center bg-slate-950/50 px-4 backdrop-blur-sm"
            @click.self="closeLogoutConfirmation"
        >
            <section
                id="logout-confirmation-dialog"
                class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl dark:border-slate-700 dark:bg-slate-900"
                role="dialog"
                aria-modal="true"
                aria-labelledby="logout-confirmation-title"
                aria-describedby="logout-confirmation-description"
            >
                <h2 id="logout-confirmation-title" class="text-xl font-bold text-slate-900 dark:text-white">
                    Log out?
                </h2>
                <p id="logout-confirmation-description" class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                    Are you sure you want to log out of your account?
                </p>

                <div class="mt-6 flex justify-end gap-3">
                    <Button type="button" variant="outline" @click="closeLogoutConfirmation">
                        Cancel
                    </Button>
                    <Button ref="confirmLogoutButton" type="button" :disabled="logoutPending" @click="logout">
                        {{ logoutPending ? 'Logging out...' : 'Yes, log out' }}
                    </Button>
                </div>
            </section>
        </div>
    </div>
</template>
