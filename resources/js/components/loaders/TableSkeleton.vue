<script setup>
import { computed } from 'vue'
import SkeletonBlock from './SkeletonBlock.vue'

const props = defineProps({
    columns: { type: Number, required: true },
    headers: { type: Array, default: () => [] },
    rows: { type: Number, default: 5 },
})

const columnCount = computed(() => props.headers.length || props.columns)
</script>

<template>
    <div class="overflow-x-auto" role="status" aria-label="Loading records">
        <div class="min-w-max overflow-hidden rounded-lg border border-slate-200 bg-white">
            <div v-if="props.headers.length" class="grid gap-3 bg-blue-900 p-3 text-sm font-semibold text-white" :style="{ gridTemplateColumns: `repeat(${columnCount}, minmax(7rem, 1fr))` }">
                <span v-for="header in props.headers" :key="header">{{ header }}</span>
            </div>
            <div v-for="row in props.rows" :key="row" class="grid gap-3 border-t border-slate-100 p-3" :style="{ gridTemplateColumns: `repeat(${columnCount}, minmax(7rem, 1fr))` }"><SkeletonBlock v-for="column in columnCount" :key="column" class-name="h-4 w-full" /></div>
        </div>
        <span class="sr-only">Loading records</span>
    </div>
</template>
