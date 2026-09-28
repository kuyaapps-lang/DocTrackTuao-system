<script setup>
import SkeletonBlock from './SkeletonBlock.vue'
</script>

<template>
    <div class="space-y-6" role="status" aria-label="Loading document details"><div class="rounded-xl border border-slate-200 bg-white p-6"><SkeletonBlock class-name="h-7 w-2/5" /><SkeletonBlock class-name="mt-3 h-4 w-1/4" /><div class="mt-8 grid gap-4 md:grid-cols-3"><SkeletonBlock v-for="item in 6" :key="item" class-name="h-16 w-full" /></div></div><div v-for="section in 3" :key="section" class="rounded-xl border border-slate-200 bg-white p-6"><SkeletonBlock class-name="h-6 w-1/4" /><SkeletonBlock v-for="row in 3" :key="row" class-name="mt-4 h-4 w-full" /></div><span class="sr-only">Loading document details…</span></div>
</template>
