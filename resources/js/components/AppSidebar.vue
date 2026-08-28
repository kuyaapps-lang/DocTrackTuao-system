<script setup>
import { computed } from 'vue'
import {
    RouterLink,
    useRoute,
} from 'vue-router'

import { useAuth } from '@/lib/auth'
import {
    resolveActiveNavigationKey,
    visibleNavigation,
} from '@/lib/navigation'

const route = useRoute()
const { permissions } = useAuth()

const items = computed(() => {
    return visibleNavigation(permissions.value)
})

const activeKey = computed(() => {
    return route.meta?.navKey ||
        resolveActiveNavigationKey(route.path)
})

const linkClasses = (key, grouped = false) => {
    const base = grouped
        ? 'block rounded-md px-3 py-2 text-sm font-medium'
        : 'block rounded-md px-3 py-2 text-sm font-semibold'

    return [
        base,
        activeKey.value === key
            ? 'bg-blue-100 text-blue-800'
            : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900',
    ]
}
</script>

<template>
    <aside
        class="sticky top-0 h-screen w-64 shrink-0 overflow-y-auto border-r bg-white"
    >
        <div class="border-b px-5 py-5">
            <p class="text-lg font-bold text-gray-900">
                DocTrack Tuao
            </p>

            <p class="mt-1 text-xs text-gray-500">
                Document Tracking System
            </p>
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
                    <p
                        class="px-3 pt-3 text-xs font-bold uppercase tracking-wide text-gray-400"
                    >
                        {{ item.label }}
                    </p>

                    <RouterLink
                        v-for="child in item.children"
                        :key="child.key"
                        :to="child.path"
                        :class="linkClasses(child.key, true)"
                        :aria-current="activeKey === child.key ? 'page' : undefined"
                    >
                        {{ child.label }}
                    </RouterLink>
                </div>

                <RouterLink
                    v-else
                    :to="item.path"
                    :class="linkClasses(item.key)"
                    :aria-current="activeKey === item.key ? 'page' : undefined"
                >
                    {{ item.label }}
                </RouterLink>
            </template>
        </nav>
    </aside>
</template>
