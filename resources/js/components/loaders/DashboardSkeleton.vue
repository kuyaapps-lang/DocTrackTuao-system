<script setup>
import SkeletonBlock from './SkeletonBlock.vue'
</script>

<template>
    <div class="space-y-2" role="status" aria-label="Loading dashboard">
        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-5"><div v-for="item in 5" :key="item" class="overflow-hidden rounded-xl border border-blue-100 bg-white"><SkeletonBlock class-name="h-7 rounded-none bg-blue-900/45" /><SkeletonBlock class-name="mx-auto my-3 h-5 w-12" /></div></div>
        <div class="grid gap-2 xl:grid-cols-3"><div v-for="item in 3" :key="item" class="rounded-xl border border-blue-100 bg-white p-3"><SkeletonBlock class-name="h-5 w-2/3" /><SkeletonBlock v-for="row in 4" :key="row" class-name="mt-3 h-3 w-full" /></div></div>
        <div class="grid gap-2 xl:grid-cols-2"><div v-for="item in 2" :key="item" class="rounded-xl border border-blue-100 bg-white p-4"><SkeletonBlock class-name="h-5 w-1/3" /><SkeletonBlock v-for="row in 4" :key="row" class-name="mt-3 h-12 w-full" /></div></div>
        <span class="sr-only">Loading dashboard…</span>
    </div>
</template>
