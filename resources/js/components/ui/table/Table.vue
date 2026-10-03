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
  <div data-slot="table-container" class="relative w-full overflow-auto border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">
    <table
      data-slot="table"
      :class="cn('w-full caption-bottom text-sm text-slate-800 dark:text-slate-100', props.class)"
    >
      <slot />
    </table>
  </div>
</template>
