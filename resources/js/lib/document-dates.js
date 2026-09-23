export const formatDocumentDateTime = value => {
    if (!value) return 'N/A'

    const date = new Date(value)
    if (Number.isNaN(date.getTime())) return 'N/A'

    const parts = new Intl.DateTimeFormat('en-US', {
        month: '2-digit',
        day: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: true,
        timeZone: 'Asia/Manila',
    }).formatToParts(date)

    const part = type => parts.find(item => item.type === type)?.value || ''

    return `${part('month')}/${part('day')}/${part('year')} ${part('hour')}:${part('minute')} ${part('dayPeriod')}`
}

export const formatDocumentDateField = value => {
    if (!value) return 'N/A'

    return formatDocumentDateTime(`${value}T00:00:00+08:00`)
}
