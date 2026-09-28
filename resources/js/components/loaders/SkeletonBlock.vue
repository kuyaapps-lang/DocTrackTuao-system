<script setup>
defineProps({ className: { type: String, default: '' } })
</script>

<template>
    <div aria-hidden="true" class="doctrack-skeleton rounded-md bg-slate-200/80" :class="className" />
</template>
