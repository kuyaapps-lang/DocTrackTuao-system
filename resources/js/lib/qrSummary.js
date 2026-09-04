const isRecord = value => value !== null && typeof value === 'object' && !Array.isArray(value)
const exactKeys = (value, keys) => isRecord(value)
    && Object.keys(value).length === keys.length
    && keys.every(key => Object.hasOwn(value, key))
const count = value => Number.isSafeInteger(value) && value >= 0
const timestamp = value => {
    if (value === null) return true
    if (typeof value !== 'string') return false
    const match = value.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d{6})?(?:Z|[+-]\d{2}:\d{2})$/)
    if (!match || Number.isNaN(Date.parse(value))) return false
    const [, year, month, day, hour, minute, second] = match.map(Number)
    return month >= 1 && month <= 12
        && day >= 1 && day <= new Date(Date.UTC(year, month, 0)).getUTCDate()
        && hour <= 23 && minute <= 59 && second <= 59
}

export const emptyQrSummary = () => ({
    total_issued: 0,
    counts: { unused: 0, registered: 0, void: 0 },
    latest_issued_at: null,
})

export const isValidQrSummary = value => exactKeys(value, ['total_issued', 'counts', 'latest_issued_at'])
    && count(value.total_issued)
    && exactKeys(value.counts, ['unused', 'registered', 'void'])
    && count(value.counts.unused)
    && count(value.counts.registered)
    && count(value.counts.void)
    && value.counts.unused + value.counts.registered + value.counts.void <= value.total_issued
    && timestamp(value.latest_issued_at)
    && ((value.total_issued === 0) === (value.latest_issued_at === null))

export const qrSummaryFailureMessage = status => {
    if (status === 401) return 'Authentication is required.'
    if (status === 403) return 'You are not authorized to view QR records.'
    return 'Unable to load QR summary. Please try again.'
}

export const createQrSummaryManager = ({ fetchImpl, getToken, onUnauthorized, onState }) => {
    let sequence = 0
    let controller = null
    let disposed = false
    let state = { summary: emptyQrSummary(), loading: false, error: '' }

    const publish = changes => {
        if (disposed) return
        state = { ...state, ...changes }
        onState({
            ...state,
            summary: { ...state.summary, counts: { ...state.summary.counts } },
        })
    }

    const load = async () => {
        if (disposed) return { kind: 'disposed' }
        const request = ++sequence
        controller?.abort()
        controller = new AbortController()
        const signal = controller.signal
        publish({ summary: emptyQrSummary(), loading: true, error: '' })

        try {
            const response = await fetchImpl('/api/qr-codes/summary', {
                headers: { Accept: 'application/json', Authorization: `Bearer ${getToken()}` },
                signal,
            })
            if (disposed) return { kind: 'disposed' }
            if (request !== sequence) return { kind: 'stale' }
            if (response.status === 401) {
                publish({ loading: false, error: '' })
                onUnauthorized()
                return { kind: 'unauthorized' }
            }
            if (!response.ok) {
                const message = qrSummaryFailureMessage(response.status)
                publish({ loading: false, error: message })
                return { kind: 'error', message }
            }

            const payload = await response.json()
            if (disposed) return { kind: 'disposed' }
            if (request !== sequence) return { kind: 'stale' }
            if (!isValidQrSummary(payload)) {
                const message = qrSummaryFailureMessage()
                publish({ loading: false, error: message })
                return { kind: 'error', message }
            }

            const summary = { ...payload, counts: { ...payload.counts } }
            publish({ summary, loading: false, error: '' })
            return { kind: 'success' }
        } catch (error) {
            if (disposed) return { kind: 'disposed' }
            if (request !== sequence) return { kind: 'stale' }
            if (error?.name === 'AbortError') {
                publish({ loading: false, error: '' })
                return { kind: 'aborted' }
            }
            const message = qrSummaryFailureMessage()
            publish({ loading: false, error: message })
            return { kind: 'error', message }
        } finally {
            if (request === sequence) controller = null
        }
    }

    return {
        load,
        retry: load,
        dispose() {
            if (disposed) return
            disposed = true
            sequence += 1
            controller?.abort()
            controller = null
        },
        isDisposed: () => disposed,
        snapshot: () => ({ ...state, summary: { ...state.summary, counts: { ...state.summary.counts } } }),
    }
}
