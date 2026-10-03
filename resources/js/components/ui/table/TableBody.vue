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
  <tbody
    data-slot="table-body"
    :class="cn('divide-y divide-slate-200 bg-white dark:divide-slate-700 dark:bg-slate-900 [&_tr:last-child]:border-0', props.class)"
  >
    <slot />
  </tbody>
</template>
