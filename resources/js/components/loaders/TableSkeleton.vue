<script setup>
import SkeletonBlock from './SkeletonBlock.vue'

const props = defineProps({ columns: { type: Number, required: true }, rows: { type: Number, default: 5 } })
</script>

<template>
    <div class="overflow-x-auto" role="status" aria-label="Loading records">
        <div class="min-w-max overflow-hidden rounded-lg border border-slate-200 bg-white">
            <div v-for="row in props.rows" :key="row" class="grid gap-3 border-t border-slate-100 p-3" :style="{ gridTemplateColumns: `repeat(${props.columns}, minmax(7rem, 1fr))` }"><SkeletonBlock v-for="column in props.columns" :key="column" class-name="h-4 w-full" /></div>
        </div>
        <span class="sr-only">Loading records</span>
    </div>
</template>
