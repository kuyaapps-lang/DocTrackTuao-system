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
    PanelLeftClose,
    PanelLeftOpen,
    QrCode,
    ScrollText,
    Users,
    X,
} from 'lucide-vue-next'

import { Button } from '@/components/ui/button'
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
        ? 'flex rounded-md px-3 py-2 text-sm font-medium'
        : 'flex rounded-md px-3 py-2 text-sm font-semibold'

    return [
        base,
        'items-center gap-3 outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2',
        collapsed ? 'justify-center' : '',
        activeKey.value === key
            ? 'bg-blue-100 text-blue-800'
            : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900',
    ]
}
</script>

<template>
    <aside
        class="sticky top-0 hidden h-screen shrink-0 flex-col overflow-y-auto border-r bg-white transition-[width] md:flex"
        :class="desktopCollapsed ? 'w-20' : 'w-64'"
    >
        <div
            class="flex min-h-24 items-center border-b"
            :class="desktopCollapsed ? 'justify-center px-3' : 'justify-between gap-3 px-5'"
        >
            <div v-if="!desktopCollapsed">
                <p class="text-lg font-bold text-gray-900">
                    DocTrack Tuao
                </p>

                <p class="mt-1 text-xs text-gray-500">
                    Document Tracking System
                </p>
            </div>

            <Button
                type="button"
                variant="ghost"
                size="icon"
                aria-controls="desktop-navigation"
                :aria-expanded="!desktopCollapsed"
                :aria-label="desktopCollapsed ? 'Expand main navigation' : 'Collapse main navigation'"
                :title="desktopCollapsed ? 'Expand main navigation' : 'Collapse main navigation'"
                @click="$emit('toggle-desktop')"
            >
                <PanelLeftOpen
                    v-if="desktopCollapsed"
                    aria-hidden="true"
                />
                <PanelLeftClose
                    v-else
                    aria-hidden="true"
                />
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
                        class="pt-3 text-xs font-bold uppercase tracking-wide text-gray-400"
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
            class="relative flex h-full w-72 max-w-[85vw] flex-col overflow-y-auto bg-white shadow-xl"
            role="dialog"
            aria-modal="true"
            aria-label="Main navigation menu"
        >
            <div class="flex min-h-20 items-center justify-between gap-3 border-b px-5">
                <div>
                    <p class="text-lg font-bold text-gray-900">
                        DocTrack Tuao
                    </p>
                    <p class="mt-1 text-xs text-gray-500">
                        Document Tracking System
                    </p>
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
