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
    Files,
    LayoutDashboard,
    Menu,
    QrCode,
    ScrollText,
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
const { permissions } = useAuth()
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
    documents: Files,
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
    return route.meta?.navKey ||
        resolveActiveNavigationKey(route.path)
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
        class="sticky top-0 hidden h-screen shrink-0 flex-col overflow-y-auto border-r border-white/80 bg-slate-100 bg-white/70 text-slate-800 shadow-[10px_0_30px_rgb(67_86_119/0.12),inset_-1px_0_0_rgb(255_255_255/0.8)] backdrop-blur-xl transition-[width,background-color,border-color,color] duration-200 md:flex"
        :class="desktopCollapsed ? 'w-20' : 'w-64'"
    >
        <div
            class="flex min-h-24 items-center border-b border-white/80"
            :class="desktopCollapsed ? 'justify-center px-3' : 'justify-between gap-3 px-5'"
        >
            <div
                v-if="!desktopCollapsed"
                class="flex min-w-0 items-center gap-3"
            >
                <img
                    :src="logo"
                    alt="Tuao logo"
                    class="h-11 w-11 shrink-0 rounded-xl bg-white object-cover p-1 shadow-sm"
                >

                <div class="min-w-0">
                    <p class="text-[12pt] font-bold uppercase tracking-wide text-blue-950">
                        LGU-Tuao
                    </p>

                    <p class="mt-1 text-xs text-slate-500">
                        Document Management System
                    </p>
                </div>
            </div>

            <Button
                type="button"
                variant="ghost"
                size="icon"
                class="bg-white text-blue-900 hover:bg-white hover:text-blue-950"
                aria-controls="desktop-navigation"
                :aria-expanded="!desktopCollapsed"
                :aria-label="desktopCollapsed ? 'Expand main navigation' : 'Collapse main navigation'"
                :title="desktopCollapsed ? 'Expand main navigation' : 'Collapse main navigation'"
                @click="$emit('toggle-desktop')"
            >
                <Menu aria-hidden="true" />
            </Button>
        </div>

        <nav
            id="desktop-navigation"
            aria-label="Main navigation"
            class="space-y-2 p-4"
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
                    :to="item.path"
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
            <div class="flex min-h-20 items-center justify-between gap-3 border-b px-5">
                <div class="flex min-w-0 items-center gap-3">
                    <img
                        :src="logo"
                        alt="Tuao logo"
                    class="h-11 w-11 shrink-0 rounded-xl border bg-white object-cover p-1 shadow-sm"
                    >
                    <div class="min-w-0">
                        <p class="text-[12pt] font-bold uppercase tracking-wide text-gray-900">
                            LGU-Tuao
                        </p>
                        <p class="mt-1 text-xs text-gray-500">
                            Document Management System
                        </p>
                    </div>
                </div>

                <Button
                    ref="mobileCloseButton"
                    type="button"
                    variant="ghost"
                    size="icon"
                    aria-label="Close main navigation"
                    @click="$emit('close-mobile')"
                >
                    <X aria-hidden="true" />
                </Button>
            </div>

            <nav
                aria-label="Main navigation"
                class="space-y-2 p-4"
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
                        :to="item.path"
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
        </aside>
    </div>
</template>
