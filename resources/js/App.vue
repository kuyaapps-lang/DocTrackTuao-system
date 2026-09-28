<script setup>
import { onMounted, ref } from 'vue'
import router from './router'
import AppLoading from '@/components/loaders/AppLoading.vue'

const appReady = ref(false)

onMounted(async () => {
    await router.isReady()
    appReady.value = true
})
</script>

<template>
    <AppLoading v-if="!appReady" />
    <router-view v-else />
</template>
