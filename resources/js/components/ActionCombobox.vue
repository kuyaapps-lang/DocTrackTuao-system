<script setup>
import { computed, ref, watch } from 'vue'
import { filterActions } from '@/lib/action-search'
import { sanitizeBulkWorkflowText } from '@/lib/bulk-workflow-text'

const props = defineProps({
    id: { type: String, required: true },
    modelValue: { type: [String, Number], default: '' },
    actions: { type: Array, default: () => [] },
    disabled: Boolean,
    optional: Boolean,
})
const emit = defineEmits(['update:modelValue', 'select'])
const query = ref('')
const open = ref(false)
const active = ref(-1)
const matches = computed(() => filterActions(props.actions, query.value))

watch(() => [props.modelValue, props.actions], ([value], previous) => {
    const selected = props.actions.find(action => String(action.id) === String(value))
    const prior = props.actions.find(action => String(action.id) === String(previous?.[0]))
    if (selected) query.value = selected.action_name
    else if (!value && prior && query.value === prior.action_name) query.value = ''
}, { immediate: true })

function edit(event) {
    query.value = sanitizeBulkWorkflowText(event.target.value)
    emit('update:modelValue', '')
    active.value = -1
    open.value = true
}
function select(action) {
    query.value = action.action_name
    emit('update:modelValue', String(action.id))
    emit('select')
    open.value = false
    active.value = -1
}
function clear() {
    query.value = ''
    emit('update:modelValue', '')
    open.value = false
    active.value = -1
}
function move(direction) {
    open.value = true
    if (!matches.value.length) return
    active.value = direction > 0
        ? Math.min(active.value + 1, matches.value.length - 1)
        : (active.value <= 0 ? matches.value.length - 1 : active.value - 1)
}
function enter(event) {
    if (open.value && active.value >= 0 && matches.value[active.value]) {
        event.preventDefault()
        select(matches.value[active.value])
    } else if (!props.modelValue) {
        event.preventDefault()
        open.value = true
    }
}
</script>

<template>
    <div class="relative min-w-0 flex-1">
        <div class="relative">
            <input :id="id" :value="query" :disabled="disabled" type="text" maxlength="30"
                role="combobox" autocomplete="off" :aria-required="!optional" :aria-expanded="open"
                :aria-controls="`${id}-options`" :aria-activedescendant="open && active >= 0 ? `${id}-option-${matches[active]?.id}` : undefined"
                :placeholder="optional ? 'Search or keep the received action' : 'Search and select a Current Action'"
                class="h-11 w-full rounded-md border border-gray-300 bg-white px-3 pr-9 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                @input="edit" @focus="open = true" @blur="open = false"
                @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" @keydown.enter="enter" @keydown.esc="open = false" />
            <button v-if="optional && (query || modelValue)" type="button" class="absolute inset-y-0 right-0 px-3 text-slate-500 hover:text-slate-800" aria-label="Keep the received action" :disabled="disabled" @mousedown.prevent="clear">×</button>
        </div>
        <div v-if="open" class="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded-md border border-gray-300 bg-white shadow-lg">
            <ul :id="`${id}-options`" role="listbox">
                <li v-for="(action, index) in matches" :id="`${id}-option-${action.id}`" :key="action.id" role="option"
                    :aria-selected="String(modelValue) === String(action.id)"
                :class="['cursor-pointer truncate px-3 py-2 text-sm', index === active ? 'bg-blue-100' : 'hover:bg-gray-100']"
                    :title="action.action_name" @mouseenter="active = index" @mousedown.prevent="select(action)">{{ action.action_name }}</li>
            </ul>
            <p v-if="!matches.length" role="status" class="px-3 py-2 text-sm text-gray-600">No matching Current Actions.</p>
        </div>
        <p v-if="query && !modelValue" class="mt-1 text-sm text-gray-600">Select a Current Action from the results.</p>
    </div>
</template>
