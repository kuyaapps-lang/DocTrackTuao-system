<script setup>
import SkeletonBlock from './SkeletonBlock.vue'
import tuaoLogo from '@/assets/tuao-logo.png'
</script>

<template>
    <main class="flex min-h-screen items-center justify-center bg-slate-100 bg-[#eaf1ff] p-6">
        <div class="w-full max-w-sm rounded-2xl border border-white/80 bg-white/90 p-8 text-center shadow-[0_12px_30px_rgb(15_41_70/0.10)]">
            <img :src="tuaoLogo" alt="Municipality of Tuao" class="mx-auto size-20 rounded-full object-cover">
            <h1 class="mt-4 text-xl font-bold text-blue-950">Document Management System</h1>
            <div class="mt-5 flex items-center justify-center gap-3 text-sm font-medium text-slate-600" role="status"><span class="size-4 animate-spin rounded-full border-2 border-blue-900 border-t-transparent motion-reduce:animate-none" aria-hidden="true" />Loading system…</div>
            <SkeletonBlock class-name="mx-auto mt-5 h-2 w-3/4" />
        </div>
    </main>
</template>
