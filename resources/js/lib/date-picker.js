const pad = value => String(value).padStart(2, '0')

const isValidDateParts = (year, month, day) => {
    const date = new Date(year, month - 1, day)

    return date.getFullYear() === year
        && date.getMonth() === month - 1
        && date.getDate() === day
}

export const formatDateValue = date => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
export const formatMonthValue = date => `${date.getFullYear()}-${pad(date.getMonth() + 1)}`

export const parseDateValue = value => {
    const match = /^([0-9]{4})-([0-9]{2})-([0-9]{2})$/.exec(value || '')
    if (!match) return null

    const [, rawYear, rawMonth, rawDay] = match
    const year = Number(rawYear)
    const month = Number(rawMonth)
    const day = Number(rawDay)

    return isValidDateParts(year, month, day) ? new Date(year, month - 1, day) : null
}

export const parseMonthValue = value => {
    const match = /^([0-9]{4})-([0-9]{2})$/.exec(value || '')
    if (!match) return null

    const [, rawYear, rawMonth] = match
    const year = Number(rawYear)
    const month = Number(rawMonth)

    return month >= 1 && month <= 12 ? new Date(year, month - 1, 1) : null
}

export const formatDateDisplay = value => {
    const date = parseDateValue(value)
    return date ? `${pad(date.getMonth() + 1)}/${pad(date.getDate())}/${date.getFullYear()}` : ''
}

export const formatMonthDisplay = value => {
    const date = parseMonthValue(value)
    return date
        ? new Intl.DateTimeFormat('en-US', { month: 'long', year: 'numeric' }).format(date)
        : ''
}

export const sameDate = (left, right) => Boolean(left && right
    && left.getFullYear() === right.getFullYear()
    && left.getMonth() === right.getMonth()
    && left.getDate() === right.getDate())

export const buildCalendarDays = viewDate => {
    const firstDay = new Date(viewDate.getFullYear(), viewDate.getMonth(), 1)
    const start = new Date(firstDay)
    start.setDate(firstDay.getDate() - firstDay.getDay())

    return Array.from({ length: 42 }, (_, index) => {
        const date = new Date(start)
        date.setDate(start.getDate() + index)
        return date
    })
}
