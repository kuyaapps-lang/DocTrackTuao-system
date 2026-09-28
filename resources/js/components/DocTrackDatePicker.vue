<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { CalendarDays, ChevronLeft, ChevronRight } from 'lucide-vue-next'
import {
    buildCalendarDays,
    formatDateDisplay,
    formatDateValue,
    formatMonthDisplay,
    formatMonthValue,
    parseDateValue,
    parseMonthValue,
    sameDate,
} from '@/lib/date-picker'

const props = defineProps({
    modelValue: { type: String, default: '' },
    mode: {
        type: String,
        default: 'date',
        validator: value => ['date', 'month'].includes(value),
    },
    disabled: { type: Boolean, default: false },
    clearable: { type: Boolean, default: false },
    required: { type: Boolean, default: false },
    ariaLabel: { type: String, default: 'Choose date' },
})

const emit = defineEmits(['update:modelValue'])

const root = ref(null)
const trigger = ref(null)
const isOpen = ref(false)
const selectedDate = computed(() => props.mode === 'month'
    ? parseMonthValue(props.modelValue)
    : parseDateValue(props.modelValue))
const viewDate = ref(selectedDate.value || new Date())
const calendarDays = computed(() => buildCalendarDays(viewDate.value))
const displayValue = computed(() => props.mode === 'month'
    ? formatMonthDisplay(props.modelValue)
    : formatDateDisplay(props.modelValue))
const monthYearLabel = computed(() => new Intl.DateTimeFormat('en-US', {
    month: props.mode === 'month' ? undefined : 'long',
    year: 'numeric',
}).format(viewDate.value))
const monthNames = Array.from({ length: 12 }, (_, index) => new Intl.DateTimeFormat('en-US', { month: 'long' }).format(new Date(2024, index, 1)))
const today = new Date()

watch(selectedDate, date => {
    if (!isOpen.value && date) viewDate.value = date
})

const focusTrigger = () => nextTick(() => trigger.value?.focus())
const close = ({ restoreFocus = true } = {}) => {
    if (!isOpen.value) return

    isOpen.value = false
    if (restoreFocus) focusTrigger()
}
const toggle = () => {
    if (props.disabled) return

    if (isOpen.value) {
        close({ restoreFocus: false })
        return
    }

    viewDate.value = selectedDate.value || new Date()
    isOpen.value = true
}
const changeView = offset => {
    viewDate.value = props.mode === 'month'
        ? new Date(viewDate.value.getFullYear() + offset, viewDate.value.getMonth(), 1)
        : new Date(viewDate.value.getFullYear(), viewDate.value.getMonth() + offset, 1)
}
const chooseDate = date => {
    emit('update:modelValue', formatDateValue(date))
    close()
}
const chooseMonth = month => {
    emit('update:modelValue', formatMonthValue(new Date(viewDate.value.getFullYear(), month, 1)))
    close()
}
const chooseToday = () => {
    emit('update:modelValue', props.mode === 'month' ? formatMonthValue(today) : formatDateValue(today))
    close()
}
const clear = () => {
    if (!props.clearable) return

    emit('update:modelValue', '')
    close()
}
const onDocumentPointerDown = event => {
    if (isOpen.value && root.value && !root.value.contains(event.target)) close()
}
const onDocumentKeydown = event => {
    if (event.key !== 'Escape' || !isOpen.value) return

    event.preventDefault()
    close()
}

onMounted(() => {
    document.addEventListener('pointerdown', onDocumentPointerDown)
    document.addEventListener('keydown', onDocumentKeydown)
})

onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', onDocumentPointerDown)
    document.removeEventListener('keydown', onDocumentKeydown)
})
</script>

<template>
    <div ref="root" class="doctrack-date-picker">
        <button
            ref="trigger"
            type="button"
            class="doctrack-date-picker-trigger"
            :class="{ 'is-placeholder': !displayValue }"
            :disabled="disabled"
            :aria-label="ariaLabel"
            :aria-expanded="isOpen"
            aria-haspopup="dialog"
            :aria-required="required || undefined"
            @click="toggle"
        >
            <span>{{ displayValue || (mode === 'month' ? 'Select reporting month' : 'Select date') }}</span>
            <CalendarDays :size="18" aria-hidden="true" />
        </button>

        <div v-if="isOpen" class="doctrack-date-picker-popover" role="dialog" :aria-label="`${ariaLabel} calendar`">
            <div class="doctrack-date-picker-header">
                <button type="button" class="doctrack-date-picker-nav" aria-label="Previous month" @click="changeView(-1)">
                    <ChevronLeft :size="18" aria-hidden="true" />
                </button>
                <p class="doctrack-date-picker-heading">{{ monthYearLabel }}</p>
                <button type="button" class="doctrack-date-picker-nav" aria-label="Next month" @click="changeView(1)">
                    <ChevronRight :size="18" aria-hidden="true" />
                </button>
            </div>

            <template v-if="mode === 'date'">
                <div class="doctrack-date-picker-weekdays" aria-hidden="true">
                    <span v-for="weekday in ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']" :key="weekday">{{ weekday }}</span>
                </div>
                <div class="doctrack-date-picker-grid" role="grid" :aria-label="monthYearLabel">
                    <button
                        v-for="date in calendarDays"
                        :key="formatDateValue(date)"
                        type="button"
                        class="doctrack-date-picker-day"
                        :class="{
                            'is-outside': date.getMonth() !== viewDate.getMonth(),
                            'is-selected': sameDate(date, selectedDate),
                            'is-today': sameDate(date, today),
                        }"
                        role="gridcell"
                        :aria-label="new Intl.DateTimeFormat('en-US', { dateStyle: 'full' }).format(date)"
                        :aria-selected="sameDate(date, selectedDate)"
                        @click="chooseDate(date)"
                    >{{ date.getDate() }}</button>
                </div>
            </template>

            <div v-else class="doctrack-date-picker-months" role="grid" :aria-label="`${viewDate.getFullYear()} months`">
                <button
                    v-for="(month, index) in monthNames"
                    :key="month"
                    type="button"
                    class="doctrack-date-picker-month"
                    :class="{ 'is-selected': selectedDate?.getFullYear() === viewDate.getFullYear() && selectedDate?.getMonth() === index, 'is-today': today.getFullYear() === viewDate.getFullYear() && today.getMonth() === index }"
                    role="gridcell"
                    :aria-selected="selectedDate?.getFullYear() === viewDate.getFullYear() && selectedDate?.getMonth() === index"
                    @click="chooseMonth(index)"
                >{{ month.slice(0, 3) }}</button>
            </div>

            <div class="doctrack-date-picker-actions">
                <button type="button" class="doctrack-date-picker-action" @click="chooseToday">Today</button>
                <button v-if="clearable" type="button" class="doctrack-date-picker-action" @click="clear">Clear</button>
            </div>
        </div>
    </div>
</template>
