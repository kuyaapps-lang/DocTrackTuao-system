<script setup>
import { computed, ref, watch } from 'vue'
import { filterDocumentTypes } from '@/lib/document-type-search'

const props = defineProps({
    modelValue: { type: [String, Number], default: '' },
    types: { type: Array, default: () => [] },
    disabled: Boolean,
})
const emit = defineEmits(['update:modelValue'])
const query = ref('')
const open = ref(false)
const active = ref(-1)
const matches = computed(() => filterDocumentTypes(props.types, query.value))

watch(() => props.modelValue, (value, previous) => {
    const selected = props.types.find(type => String(type.id) === String(value))
    const prior = props.types.find(type => String(type.id) === String(previous))
    if (selected) query.value = selected.type_name
    else if (!value && prior && query.value === prior.type_name) query.value = ''
}, { immediate: true })

function edit(event) {
    query.value = event.target.value
    emit('update:modelValue', '')
    active.value = -1
    open.value = true
}
function select(type) {
    query.value = type.type_name
    emit('update:modelValue', type.id)
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
        event.stopPropagation()
        select(matches.value[active.value])
    } else if (!props.modelValue) {
        event.preventDefault()
        event.stopPropagation()
        open.value = true
    }
}
</script>

<template>
    <div class="relative">
        <input id="registration-document-type" :value="query" :disabled="disabled"
            type="text" maxlength="20" role="combobox" autocomplete="off" aria-required="true"
            :aria-expanded="open" aria-controls="registration-document-type-options"
            :aria-activedescendant="open && active >= 0 ? `registration-type-${matches[active]?.id}` : undefined"
            placeholder="Search and select a document type"
            class="h-11 w-full rounded-md border border-gray-300 bg-white px-3 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
            @input="edit" @focus="open = true" @blur="open = false"
            @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)"
            @keydown.enter="enter" @keydown.esc="open = false" />
        <div v-if="open" class="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded-md border border-gray-300 bg-white shadow-lg">
            <ul id="registration-document-type-options" role="listbox">
                <li v-for="(type, index) in matches" :id="`registration-type-${type.id}`" :key="type.id"
                    role="option" :aria-selected="String(modelValue) === String(type.id)"
                    :class="['cursor-pointer px-3 py-2 text-sm', index === active ? 'bg-blue-100' : 'hover:bg-gray-100']"
                    @mouseenter="active = index" @mousedown.prevent="select(type)">{{ type.type_name }}</li>
            </ul>
            <p v-if="!matches.length" class="px-3 py-2 text-sm text-gray-600" role="status">No matching document types.</p>
        </div>
        <p v-if="query && !modelValue" class="mt-1 text-sm text-gray-600">Select an existing document type from the results.</p>
    </div>
</template>
