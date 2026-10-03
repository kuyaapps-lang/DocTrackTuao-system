<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { CalendarDays, ChevronLeft, ChevronRight } from 'lucide-vue-next'
import {
    buildCalendarDays,
    formatDateDisplay,
    formatDateValue,
    parseDateValue,
    sameDate,
} from '@/lib/date-picker'

const props = defineProps({
    from: { type: String, default: '' },
    to: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    ariaLabel: { type: String, default: 'Choose date range' },
})

const emit = defineEmits(['update:from', 'update:to'])

const root = ref(null)
const trigger = ref(null)
const popover = ref(null)
const isOpen = ref(false)
const selectingEnd = ref(false)
const resolvedPlacement = ref('below')
const startDate = computed(() => parseDateValue(props.from))
const endDate = computed(() => parseDateValue(props.to))
const viewDate = ref(startDate.value || endDate.value || new Date())
const calendarDays = computed(() => buildCalendarDays(viewDate.value))
const monthYearLabel = computed(() => new Intl.DateTimeFormat('en-US', { month: 'long', year: 'numeric' }).format(viewDate.value))
const displayValue = computed(() => {
    const from = formatDateDisplay(props.from)
    const to = formatDateDisplay(props.to)

    return from && to ? `${from} - ${to}` : from || to
})
const today = new Date()

watch([startDate, endDate], ([start, end]) => {
    if (!isOpen.value) viewDate.value = start || end || new Date()
})

const isInRange = date => Boolean(startDate.value && endDate.value
    && date > startDate.value && date < endDate.value)
const isRangeEndpoint = date => sameDate(date, startDate.value) || sameDate(date, endDate.value)
const focusTrigger = () => nextTick(() => trigger.value?.focus())
const resolvePlacement = () => {
    if (!isOpen.value || !root.value || !popover.value) return

    const triggerBounds = root.value.getBoundingClientRect()
    const popoverBounds = popover.value.getBoundingClientRect()
    const margin = 8
    const spaceBelow = window.innerHeight - triggerBounds.bottom
    const spaceAbove = triggerBounds.top

    resolvedPlacement.value = spaceBelow >= popoverBounds.height + margin || spaceBelow >= spaceAbove
        ? 'below'
        : 'above'
}
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

    viewDate.value = startDate.value || endDate.value || new Date()
    selectingEnd.value = Boolean(startDate.value && !endDate.value)
    isOpen.value = true
    nextTick(resolvePlacement)
}
const changeView = offset => {
    viewDate.value = new Date(viewDate.value.getFullYear(), viewDate.value.getMonth() + offset, 1)
}
const chooseDate = date => {
    if (!startDate.value || (startDate.value && endDate.value && !selectingEnd.value)) {
        emit('update:from', formatDateValue(date))
        emit('update:to', '')
        selectingEnd.value = true
        return
    }

    if (date < startDate.value) {
        emit('update:from', formatDateValue(date))
        emit('update:to', formatDateValue(startDate.value))
    } else {
        emit('update:to', formatDateValue(date))
    }

    selectingEnd.value = false
    close()
}
const chooseToday = () => chooseDate(today)
const clear = () => {
    emit('update:from', '')
    emit('update:to', '')
    selectingEnd.value = false
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
    window.addEventListener('resize', resolvePlacement)
    document.addEventListener('scroll', resolvePlacement, true)
})

onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', onDocumentPointerDown)
    document.removeEventListener('keydown', onDocumentKeydown)
    window.removeEventListener('resize', resolvePlacement)
    document.removeEventListener('scroll', resolvePlacement, true)
})
</script>

<template>
    <div ref="root" class="doctrack-date-picker doctrack-date-range-picker">
        <button
            ref="trigger"
            type="button"
            class="doctrack-date-picker-trigger"
            :class="{ 'is-placeholder': !displayValue }"
            :disabled="disabled"
            :aria-label="ariaLabel"
            :aria-expanded="isOpen"
            aria-haspopup="dialog"
            @click="toggle"
        >
            <span>{{ displayValue || 'Select date range' }}</span>
            <CalendarDays :size="18" aria-hidden="true" />
        </button>

        <div v-if="isOpen" ref="popover" class="doctrack-date-picker-popover" :class="{ 'is-above': resolvedPlacement === 'above' }" role="dialog" :aria-label="`${ariaLabel} calendar`">
            <div class="doctrack-date-picker-header">
                <button type="button" class="doctrack-date-picker-nav" aria-label="Previous month" @click="changeView(-1)"><ChevronLeft :size="18" aria-hidden="true" /></button>
                <p class="doctrack-date-picker-heading">{{ monthYearLabel }}</p>
                <button type="button" class="doctrack-date-picker-nav" aria-label="Next month" @click="changeView(1)"><ChevronRight :size="18" aria-hidden="true" /></button>
            </div>
            <div class="doctrack-date-picker-weekdays" aria-hidden="true"><span v-for="weekday in ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']" :key="weekday">{{ weekday }}</span></div>
            <div class="doctrack-date-picker-grid" role="grid" :aria-label="monthYearLabel">
                <button
                    v-for="date in calendarDays"
                    :key="formatDateValue(date)"
                    type="button"
                    class="doctrack-date-picker-day"
                    :class="{
                        'is-outside': date.getMonth() !== viewDate.getMonth(),
                        'is-in-range': isInRange(date),
                        'is-range-endpoint': isRangeEndpoint(date),
                        'is-today': sameDate(date, today),
                    }"
                    role="gridcell"
                    :aria-label="new Intl.DateTimeFormat('en-US', { dateStyle: 'full' }).format(date)"
                    :aria-selected="isRangeEndpoint(date)"
                    @click="chooseDate(date)"
                >{{ date.getDate() }}</button>
            </div>
            <div class="doctrack-date-picker-actions">
                <button type="button" class="doctrack-date-picker-action" @click="chooseToday">Today</button>
                <button type="button" class="doctrack-date-picker-action" @click="clear">Clear</button>
            </div>
        </div>
    </div>
</template>
