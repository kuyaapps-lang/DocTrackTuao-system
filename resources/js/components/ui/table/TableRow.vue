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
  <tr
    data-slot="table-row"
    :class="
      cn(
        'border-b border-slate-200 transition-colors hover:bg-blue-50/70 data-[state=selected]:bg-blue-50 dark:border-slate-700 dark:hover:bg-slate-800/80 dark:data-[state=selected]:bg-slate-800',
        props.class,
      )
    "
  >
    <slot />
  </tr>
</template>
