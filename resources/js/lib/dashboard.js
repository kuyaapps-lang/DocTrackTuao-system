const MONTH_PATTERN = /^(?!0000)\d{4}-(0[1-9]|1[0-2])$/

const isRecord = value => value !== null && typeof value === 'object' && !Array.isArray(value)
const hasExactKeys = (value, keys) => isRecord(value) && Object.keys(value).length === keys.length && keys.every(key => Object.hasOwn(value, key))
const isSafeId = value => value === null || (Number.isSafeInteger(value) && value > 0)
const isSafeText = value => typeof value === 'string' && value.trim() !== ''
const isNullableSafeText = value => value === null || isSafeText(value)
const isCount = value => Number.isSafeInteger(value) && value >= 0
const isNamedReference = value => hasExactKeys(value, ['id', 'name']) && isSafeId(value.id) && isSafeText(value.name)
const isDistribution = (value, key) => Array.isArray(value) && value.every(item => hasExactKeys(item, [key, 'count']) && isNamedReference(item[key]) && isCount(item.count))
const isPositiveSafeInteger = value => Number.isSafeInteger(value) && value > 0
const isLeapYear = year => year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0)

export const isValidDashboardTimestamp = value => {
    if (typeof value !== 'string') return false
    const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})([+-])(\d{2}):(\d{2})$/.exec(value)
    if (!match) return false
    const [, yearText, monthText, dayText, hourText, minuteText, secondText, , offsetHourText, offsetMinuteText] = match
    const [year, month, day, hour, minute, second, offsetHour, offsetMinute] = [yearText, monthText, dayText, hourText, minuteText, secondText, offsetHourText, offsetMinuteText].map(Number)
    if (year === 0 || month < 1 || month > 12 || hour > 23 || minute > 59 || second > 59 || offsetHour > 14 || offsetMinute > 59 || (offsetHour === 14 && offsetMinute !== 0)) return false
    const days = [31, isLeapYear(year) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31]
    return day >= 1 && day <= days[month - 1]
}

const isDocumentRoutingActivity = value => hasExactKeys(value, ['event_type', 'from_office', 'to_office', 'occurred_at']) && ['forwarded', 'received'].includes(value.event_type) && isNamedReference(value.from_office) && value.from_office.id !== null && isNamedReference(value.to_office) && value.to_office.id !== null && isValidDashboardTimestamp(value.occurred_at)
const isRecentDocument = value => hasExactKeys(value, ['id', 'tracking_no', 'qr_code', 'document_details', 'priority', 'status', 'created_at', 'latest_routing_activity']) && isPositiveSafeInteger(value.id) && isSafeText(value.tracking_no) && isNullableSafeText(value.qr_code) && isSafeText(value.document_details) && (value.priority === null || isNamedReference(value.priority)) && isNamedReference(value.status) && isValidDashboardTimestamp(value.created_at) && (value.latest_routing_activity === null || isDocumentRoutingActivity(value.latest_routing_activity))
const isRoutingActivity = value => hasExactKeys(value, ['document', 'event_type', 'from_office', 'to_office', 'occurred_at']) && hasExactKeys(value.document, ['id', 'tracking_no']) && isPositiveSafeInteger(value.document.id) && isSafeText(value.document.tracking_no) && ['forwarded', 'received'].includes(value.event_type) && isNamedReference(value.from_office) && value.from_office.id !== null && isNamedReference(value.to_office) && value.to_office.id !== null && isValidDashboardTimestamp(value.occurred_at)

export const normalizeDashboardMonth = value => typeof value === 'string' && MONTH_PATTERN.test(value) ? value : null
export const currentDashboardMonth = (date = new Date(), timeZone = 'Asia/Manila') => {
    const parts = new Intl.DateTimeFormat('en', {
        timeZone,
        year: 'numeric',
        month: '2-digit',
    }).formatToParts(date)
    const year = parts.find(part => part.type === 'year')?.value
    const month = parts.find(part => part.type === 'month')?.value

    return normalizeDashboardMonth(`${year}-${month}`)
}
export const currentDashboardDateRange = (date = new Date(), timeZone = 'Asia/Manila') => {
    const parts = new Intl.DateTimeFormat('en', {
        timeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).formatToParts(date)
    const part = type => parts.find(item => item.type === type)?.value
    const year = Number(part('year'))
    const month = Number(part('month'))
    const day = part('day')
    const endDay = String(new Date(Date.UTC(year, month, 0)).getUTCDate()).padStart(2, '0')

    return {
        from: `${year}-${String(month).padStart(2, '0')}-${day}`,
        to: `${year}-${String(month).padStart(2, '0')}-${endDay}`,
    }
}
export const buildDashboardQuery = month => normalizeDashboardMonth(month) ? { month: normalizeDashboardMonth(month) } : {}
export const buildDashboardRequestUrl = month => {
    const suffix = new URLSearchParams(buildDashboardQuery(month)).toString()
    return suffix ? `/api/dashboard/summary?${suffix}` : '/api/dashboard/summary'
}
export const dashboardRequestKey = month => normalizeDashboardMonth(month) || 'all-time'

export const calculateDashboardPercentage = (count, total) => {
    if (!isCount(count) || !isCount(total) || total === 0) return 0
    const percentage = (count / total) * 100
    return Number.isFinite(percentage) ? Math.min(100, Math.max(0, percentage)) : 0
}

export const isValidDashboardResponse = value => {
    if (!hasExactKeys(value, ['filters', 'scope', 'summary', 'status_distribution', 'current_office_distribution', 'origin_office_distribution', 'recent_documents', 'recent_routing_activity'])) return false
    const filtersValid = hasExactKeys(value.filters, ['month', 'date_from', 'date_to', 'timezone']) && (value.filters.month === null || normalizeDashboardMonth(value.filters.month) === value.filters.month) && [value.filters.date_from, value.filters.date_to].every(value => value === null || (/^\d{4}-\d{2}-\d{2}$/.test(value))) && isSafeText(value.filters.timezone)
    const scopeValid = hasExactKeys(value.scope, ['type', 'office']) && ((value.scope.type === 'system' && value.scope.office === null) || (value.scope.type === 'office' && isNamedReference(value.scope.office) && value.scope.office.id !== null))
    const summaryKeys = ['total_documents', 'incoming_movements', 'outgoing_movements', 'in_transit_documents', 'received_documents']
    const summaryValid = hasExactKeys(value.summary, summaryKeys) && summaryKeys.every(key => isCount(value.summary[key]))
    return filtersValid && scopeValid && summaryValid && isDistribution(value.status_distribution, 'status') && isDistribution(value.current_office_distribution, 'office') && isDistribution(value.origin_office_distribution, 'office') && Array.isArray(value.recent_documents) && value.recent_documents.every(isRecentDocument) && Array.isArray(value.recent_routing_activity) && value.recent_routing_activity.every(isRoutingActivity)
}
