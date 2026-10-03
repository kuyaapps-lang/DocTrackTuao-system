<script setup>
import { cn } from "@/lib/utils";

const props = defineProps({
  class: {
    type: [Boolean, null, String, Object, Array],
    required: false,
    skipCheck: true,
  },
});
</script>

<template>
  <thead data-slot="table-header" :class="cn('[&_tr]:border-b [&_tr]:border-slate-200 dark:[&_tr]:border-slate-700', props.class)">
    <slot />
  </thead>
</template>
