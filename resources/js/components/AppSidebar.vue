<script setup>
import {
    computed,
    ref,
} from 'vue'
import {
    RouterLink,
    useRoute,
} from 'vue-router'
import {
    Building2,
    FileText,
    FileSearch,
    Inbox,
    LayoutDashboard,
    QrCode,
    ScrollText,
    Send,
    Users,
    X,
} from 'lucide-vue-next'

import { Button } from '@/components/ui/button'
import logo from '@/assets/tuao-logo.png'
import { useAuth } from '@/lib/auth'
import {
    resolveActiveNavigationKey,
    visibleNavigation,
} from '@/lib/navigation'

const route = useRoute()
const { currentUser, permissions } = useAuth()
const mobileCloseButton = ref(null)

defineProps({
    desktopCollapsed: {
        type: Boolean,
        default: false,
    },
    mobileOpen: {
        type: Boolean,
        default: false,
    },
})

defineEmits([
    'close-mobile',
    'navigate',
    'toggle-desktop',
])

defineExpose({
    focusMobileClose: () => mobileCloseButton.value?.$el?.focus(),
})

const navigationIcons = {
    dashboard: LayoutDashboard,
    'outgoing-documents': Send,
    'incoming-documents': Inbox,
    'document-inquiry': FileSearch,
    'qr-codes': QrCode,
    offices: Building2,
    'document-types': FileText,
    users: Users,
    audit: ScrollText,
}

const items = computed(() => {
    return visibleNavigation(permissions.value)
})

const activeKey = computed(() => {
    return resolveActiveNavigationKey(route.fullPath) ||
        route.meta?.navKey
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

const iconFor = (key) => navigationIcons[key]

const linkClasses = (key, grouped = false, collapsed = false) => {
    const base = grouped
        ? 'flex rounded-xl px-3 py-2 text-[12pt] font-medium'
        : 'flex rounded-xl px-3 py-2 text-[12pt] font-semibold'

    return [
        base,
        'items-center gap-3 outline-none transition-colors duration-200 focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2',
        collapsed ? 'justify-center' : '',
        activeKey.value === key
            ? 'bg-blue-900 text-white'
            : 'text-slate-600 hover:bg-blue-50 hover:text-blue-900',
    ]
}
</script>

<template>
    <aside
        class="fixed inset-y-0 left-0 z-30 hidden h-screen shrink-0 flex-col overflow-y-auto border-r border-white/80 bg-slate-100 bg-white/70 text-slate-800 shadow-[10px_0_30px_rgb(67_86_119/0.12),inset_-1px_0_0_rgb(255_255_255/0.8)] backdrop-blur-xl transition-[width,background-color,border-color,color] duration-200 md:flex"
        :class="desktopCollapsed ? 'w-20' : 'w-64'"
    >
        <div
            class="flex min-h-24 items-center justify-center border-b border-white/80 pt-[30px]"
            :class="desktopCollapsed ? 'px-3' : 'px-5'"
        >
            <button
                type="button"
                class="flex min-w-0 flex-col items-center rounded-xl text-center outline-none transition-opacity hover:opacity-80 focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2"
                aria-controls="desktop-navigation"
                :aria-expanded="!desktopCollapsed"
                :aria-label="desktopCollapsed ? 'Expand main navigation' : 'Collapse main navigation'"
                :title="desktopCollapsed ? 'Expand main navigation' : 'Collapse main navigation'"
                @click="$emit('toggle-desktop')"
            >
                <img
                    :src="logo"
                    alt="Tuao logo"
                    class="shrink-0 rounded-xl bg-white object-cover p-1 shadow-sm"
                    :class="desktopCollapsed ? 'h-[40px] w-[40px]' : 'h-[80px] w-[80px]'"
                >

                <p
                    v-if="!desktopCollapsed"
                    class="mt-1 pb-5 text-[12.5pt] text-slate-500"
                >
                    Document Management System
                </p>
            </button>
        </div>

        <nav
            id="desktop-navigation"
            aria-label="Main navigation"
            class="flex-1 space-y-2 p-4"
        >
            <template
                v-for="item in items"
                :key="item.key"
            >
                <div
                    v-if="item.children"
                    class="space-y-1"
                >
                    <p
                        class="pt-3 text-xs font-bold uppercase tracking-wide text-slate-400"
                        :class="desktopCollapsed ? 'sr-only' : 'px-3'"
                    >
                        {{ item.label }}
                    </p>

                    <RouterLink
                        v-for="child in item.children"
                        :key="child.key"
                        :to="child.path"
                        :class="linkClasses(child.key, true, desktopCollapsed)"
                        :aria-current="activeKey === child.key ? 'page' : undefined"
                        :aria-label="desktopCollapsed ? child.label : undefined"
                        :title="desktopCollapsed ? child.label : undefined"
                    >
                        <component
                            :is="iconFor(child.key)"
                            class="size-5 shrink-0"
                            aria-hidden="true"
                        />
                        <span :class="desktopCollapsed ? 'sr-only' : ''">
                            {{ child.label }}
                        </span>
                    </RouterLink>
                </div>

                <RouterLink
                    v-else
                    :to="item.to || item.path"
                    :class="linkClasses(item.key, false, desktopCollapsed)"
                    :aria-current="activeKey === item.key ? 'page' : undefined"
                    :aria-label="desktopCollapsed ? item.label : undefined"
                    :title="desktopCollapsed ? item.label : undefined"
                >
                    <component
                        :is="iconFor(item.key)"
                        class="size-5 shrink-0"
                        aria-hidden="true"
                    />
                    <span :class="desktopCollapsed ? 'sr-only' : ''">
                        {{ item.label }}
                    </span>
                </RouterLink>
            </template>
        </nav>

        <div class="mt-auto border-t border-white/80 p-4">
            <div
                class="flex items-center gap-3 rounded-2xl bg-blue-50/80 p-3 text-left shadow-sm"
                :class="desktopCollapsed ? 'justify-center px-2' : ''"
                :title="desktopCollapsed ? `${userName} — ${officeName}` : undefined"
            >
                <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-blue-900 text-sm font-bold text-white shadow-sm" aria-hidden="true">
                    {{ userInitials }}
                </div>
                <div v-if="!desktopCollapsed" class="min-w-0">
                    <p class="truncate text-sm font-semibold text-blue-950">{{ userName }}</p>
                    <p class="truncate text-xs text-slate-600">{{ officeName }}</p>
                </div>
            </div>
        </div>
    </aside>

    <div
        v-if="mobileOpen"
        class="fixed inset-0 z-50 md:hidden"
    >
        <div
            class="absolute inset-0 bg-black/50"
            aria-hidden="true"
            @click="$emit('close-mobile')"
        />

        <aside
            id="mobile-navigation-drawer"
            class="relative flex h-full w-72 max-w-[85vw] flex-col overflow-y-auto bg-slate-100 bg-white/90 shadow-xl backdrop-blur-xl"
            role="dialog"
            aria-modal="true"
            aria-label="Main navigation menu"
        >
            <div class="relative flex min-h-24 items-center justify-center border-b px-5 pt-[30px]">
                <div class="flex min-w-0 flex-col items-center text-center">
                    <img
                        :src="logo"
                        alt="Tuao logo"
                        class="h-[80px] w-[80px] shrink-0 rounded-xl border bg-white object-cover p-1 shadow-sm"
                    >
                    <p class="mt-1 pb-5 text-[12.5pt] text-gray-500">
                        Document Management System
                    </p>
                </div>

                <Button
                    ref="mobileCloseButton"
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="absolute right-5"
                    aria-label="Close main navigation"
                    @click="$emit('close-mobile')"
                >
                    <X aria-hidden="true" />
                </Button>
            </div>

            <nav
                aria-label="Main navigation"
                class="flex-1 space-y-2 p-4"
            >
                <template
                    v-for="item in items"
                    :key="item.key"
                >
                    <div
                        v-if="item.children"
                        class="space-y-1"
                    >
                        <p class="px-3 pt-3 text-xs font-bold uppercase tracking-wide text-gray-400">
                            {{ item.label }}
                        </p>

                        <RouterLink
                            v-for="child in item.children"
                            :key="child.key"
                            :to="child.path"
                            :class="linkClasses(child.key, true)"
                            :aria-current="activeKey === child.key ? 'page' : undefined"
                            @click="$emit('navigate')"
                        >
                            <component
                                :is="iconFor(child.key)"
                                class="size-5 shrink-0"
                                aria-hidden="true"
                            />
                            {{ child.label }}
                        </RouterLink>
                    </div>

                    <RouterLink
                        v-else
                        :to="item.to || item.path"
                        :class="linkClasses(item.key)"
                        :aria-current="activeKey === item.key ? 'page' : undefined"
                        @click="$emit('navigate')"
                    >
                        <component
                            :is="iconFor(item.key)"
                            class="size-5 shrink-0"
                            aria-hidden="true"
                        />
                        {{ item.label }}
                    </RouterLink>
                </template>
            </nav>

            <div class="mt-auto border-t border-slate-200 p-4">
                <div class="flex items-center gap-3 rounded-2xl bg-blue-50 p-3 text-left">
                    <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-blue-900 text-sm font-bold text-white shadow-sm" aria-hidden="true">
                        {{ userInitials }}
                    </div>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-blue-950">{{ userName }}</p>
                        <p class="truncate text-xs text-slate-600">{{ officeName }}</p>
                    </div>
                </div>
            </div>
        </aside>
    </div>
</template>
