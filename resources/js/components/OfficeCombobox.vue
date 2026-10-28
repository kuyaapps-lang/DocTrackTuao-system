<script setup>
import { computed, ref, watch } from 'vue'
import { filterOffices } from '@/lib/office-search'

const props = defineProps({
    id: { type: String, required: true },
    modelValue: { type: [String, Number], default: '' },
    offices: { type: Array, default: () => [] },
    disabled: Boolean,
})
const emit = defineEmits(['update:modelValue', 'select'])
const query = ref('')
const open = ref(false)
const active = ref(-1)
const matches = computed(() => filterOffices(props.offices, query.value))

watch(() => [props.modelValue, props.offices], ([value], previous) => {
    const selected = props.offices.find(office => String(office.id) === String(value))
    const prior = props.offices.find(office => String(office.id) === String(previous?.[0]))
    if (selected) query.value = selected.office_name
    else if (!value && prior && query.value === prior.office_name) query.value = ''
}, { immediate: true })

function edit(event) {
    query.value = event.target.value
    emit('update:modelValue', '')
    active.value = -1
    open.value = true
}
function select(office) {
    query.value = office.office_name
    emit('update:modelValue', String(office.id))
    emit('select')
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
    <div class="relative min-w-48 flex-1">
        <input :id="id" :value="query" :disabled="disabled" type="text" maxlength="20"
            role="combobox" autocomplete="off" aria-required="true" :aria-expanded="open"
            :aria-controls="`${id}-options`" :aria-activedescendant="open && active >= 0 ? `${id}-option-${matches[active]?.id}` : undefined"
            placeholder="Search office name or code" class="h-10 w-full rounded-md border border-gray-300 bg-white px-3 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
            @input="edit" @focus="open = true" @blur="open = false"
            @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" @keydown.enter="enter" @keydown.esc="open = false" />
        <div v-if="open" class="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded-md border border-gray-300 bg-white shadow-lg">
            <ul :id="`${id}-options`" role="listbox">
                <li v-for="(office, index) in matches" :id="`${id}-option-${office.id}`" :key="office.id" role="option"
                    :aria-selected="String(modelValue) === String(office.id)"
                    :class="['cursor-pointer px-3 py-2 text-sm', index === active ? 'bg-blue-100' : 'hover:bg-gray-100']"
                    @mouseenter="active = index" @mousedown.prevent="select(office)">{{ office.office_name }}<span v-if="office.office_code"> ({{ office.office_code }})</span></li>
            </ul>
            <p v-if="!matches.length" role="status" class="px-3 py-2 text-sm text-gray-600">No matching offices.</p>
        </div>
        <p v-if="query && !modelValue" class="mt-1 text-sm text-gray-600">Select an office from the results.</p>
    </div>
</template>
