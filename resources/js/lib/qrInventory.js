const STATUSES = ['unused', 'registered', 'void']
const PAGE_SIZES = [10, 25, 50]

const isRecord = value => value !== null && typeof value === 'object' && !Array.isArray(value)
const exactKeys = (value, keys) => isRecord(value) && Object.keys(value).length === keys.length && keys.every(key => Object.hasOwn(value, key))
const positiveInteger = value => Number.isSafeInteger(value) && value > 0
const nullableCount = value => value === null || (Number.isSafeInteger(value) && value >= 0)
const validTimestamp = value => {
    if (typeof value !== 'string') return false
    const match = value.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d{6})?(?:Z|[+-]\d{2}:\d{2})$/)
    if (!match || Number.isNaN(Date.parse(value))) return false
    const [, year, month, day, hour, minute, second] = match.map(Number)
    const daysInMonth = new Date(Date.UTC(year, month, 0)).getUTCDate()
    return month >= 1 && month <= 12 && day >= 1 && day <= daysInMonth
        && hour <= 23 && minute <= 59 && second <= 59
}

export const isInventoryItem = value => exactKeys(value, ['id', 'status', 'issued_at', 'linked']) && positiveInteger(value.id) && STATUSES.includes(value.status) && validTimestamp(value.issued_at) && typeof value.linked === 'boolean'

export const isValidInventoryResponse = value => {
    if (!exactKeys(value, ['data', 'meta']) || !Array.isArray(value.data) || !value.data.every(isInventoryItem)) return false
    const meta = value.meta
    if (!exactKeys(meta, ['current_page', 'last_page', 'per_page', 'total', 'from', 'to'])) return false
    if (!positiveInteger(meta.current_page) || !positiveInteger(meta.last_page) || !PAGE_SIZES.includes(meta.per_page) || !Number.isSafeInteger(meta.total) || meta.total < 0 || !nullableCount(meta.from) || !nullableCount(meta.to)) return false
    const expectedLastPage = Math.max(1, Math.ceil(meta.total / meta.per_page))
    if (meta.last_page !== expectedLastPage || meta.current_page > meta.last_page) return false
    if (meta.total === 0) return value.data.length === 0 && meta.from === null && meta.to === null

    const expectedFrom = ((meta.current_page - 1) * meta.per_page) + 1
    const expectedLength = Math.min(meta.per_page, meta.total - expectedFrom + 1)
    if (value.data.length !== expectedLength || meta.from !== expectedFrom || meta.to !== expectedFrom + expectedLength - 1) return false

    return value.data.every((item, index, items) => {
        if (index === 0) return true
        const previous = items[index - 1]
        return previous.issued_at > item.issued_at || (previous.issued_at === item.issued_at && previous.id > item.id)
    })
}

export const buildInventoryUrl = ({ page = 1, perPage = 10, status = '' } = {}) => {
    const safePage = positiveInteger(page) ? page : 1
    const safePerPage = PAGE_SIZES.includes(perPage) ? perPage : 10
    const parameters = new URLSearchParams({ page: String(safePage), per_page: String(safePerPage) })
    if (STATUSES.includes(status)) parameters.set('status', status)
    return `/api/qr-codes/inventory?${parameters.toString()}`
}

export const canVoidInventoryItem = item => isInventoryItem(item) && item.status === 'unused' && item.linked === false
export const canBeginVoid = (pendingId, item) => pendingId === null && canVoidInventoryItem(item)

export const voidConfirmationText = item => canVoidInventoryItem(item)
    ? `Void QR record #${item.id} issued ${item.issued_at}? This cannot be undone.`
    : ''

export const inventoryFailureMessage = status => {
    if (status === 401) return 'Authentication is required.'
    if (status === 403) return 'You are not authorized to manage QR records.'
    if (status === 409) return 'This QR record changed and can no longer be voided.'
    if (status === 422) return 'The QR inventory request is invalid.'
    return 'Unable to load QR inventory. Please try again.'
}

const cloneItems = items => items.map(item => ({ ...item }))

export const createInventoryManager = ({
    fetchImpl,
    getToken,
    onUnauthorized,
    onState,
    onVoided = () => {},
}) => {
    let requestSequence = 0
    let activeController = null
    let activeVoidController = null
    let pendingId = null
    let disposed = false
    let lastRequest = { page: 1, perPage: 10, status: 'unused' }
    let state = {
        items: [],
        meta: null,
        page: 1,
        requestUrl: buildInventoryUrl(lastRequest),
        loading: false,
        error: '',
        notice: '',
        noticeKind: 'success',
        pendingId: null,
    }

    const publish = changes => {
        if (disposed) return false
        state = { ...state, ...changes }
        onState({ ...state, items: cloneItems(state.items), meta: state.meta ? { ...state.meta } : null })
        return true
    }

    const requestPage = async (options, signal) => {
        const url = buildInventoryUrl(options)
        const response = await fetchImpl(url, {
            headers: {
                Accept: 'application/json',
                Authorization: `Bearer ${getToken()}`,
            },
            signal,
        })
        return { response, url }
    }

    const load = async (options = {}, recovery = false, notice = '', noticeKind = 'success') => {
        if (disposed) return { kind: 'disposed' }
        const requested = {
            page: options.page ?? lastRequest.page,
            perPage: options.perPage ?? lastRequest.perPage,
            status: options.status ?? lastRequest.status,
        }
        lastRequest = { ...requested }
        const sequence = ++requestSequence
        activeController?.abort()
        const controller = new AbortController()
        activeController = controller

        publish({
            items: [],
            meta: null,
            page: requested.page,
            requestUrl: buildInventoryUrl(requested),
            loading: true,
            error: '',
            notice,
            noticeKind,
        })

        try {
            let result = await requestPage(requested, controller.signal)
            let recovered = false

            if (disposed) return { kind: 'disposed' }
            if (result.response.status === 401) {
                if (sequence === requestSequence) onUnauthorized()
                return { kind: 'unauthorized' }
            }

            if (result.response.status === 422 && recovery && requested.page > 1) {
                const fallback = { ...requested, page: 1 }
                lastRequest = fallback
                publish({ page: 1, requestUrl: buildInventoryUrl(fallback) })
                result = await requestPage(fallback, controller.signal)
                recovered = true
            }

            if (disposed) return { kind: 'disposed' }
            if (sequence !== requestSequence) return { kind: 'stale' }
            if (result.response.status === 401) {
                onUnauthorized()
                return { kind: 'unauthorized' }
            }
            if (!result.response.ok) {
                const message = recovered
                    ? inventoryFailureMessage()
                    : inventoryFailureMessage(result.response.status)
                publish({ items: [], meta: null, loading: false, error: message })
                return { kind: 'error', message }
            }

            const payload = await result.response.json()
            if (disposed) return { kind: 'disposed' }
            if (sequence !== requestSequence) return { kind: 'stale' }
            if (!isValidInventoryResponse(payload)) {
                const message = inventoryFailureMessage()
                publish({ items: [], meta: null, loading: false, error: message })
                return { kind: 'error', message }
            }

            const safeItems = cloneItems(payload.data)
            const safeMeta = { ...payload.meta }
            lastRequest = { ...requested, page: safeMeta.current_page }
            publish({
                items: safeItems,
                meta: safeMeta,
                page: safeMeta.current_page,
                requestUrl: buildInventoryUrl(lastRequest),
                loading: false,
                error: '',
            })
            return { kind: 'success', recovered, page: safeMeta.current_page }
        } catch (error) {
            if (disposed) return { kind: 'disposed' }
            if (sequence !== requestSequence) return { kind: 'stale' }
            if (error?.name === 'AbortError') {
                publish({ items: [], meta: null, loading: false, error: '' })
                return { kind: 'aborted' }
            }
            const message = inventoryFailureMessage()
            publish({ items: [], meta: null, loading: false, error: message })
            return { kind: 'error', message }
        } finally {
            if (sequence === requestSequence) activeController = null
        }
    }

    const voidItem = async (item, options = {}) => {
        if (disposed) return { kind: 'disposed' }
        if (pendingId !== null || !canVoidInventoryItem(item)) return { kind: 'duplicate' }
        pendingId = item.id
        const controller = new AbortController()
        activeVoidController = controller
        const request = {
            page: options.page ?? lastRequest.page,
            perPage: options.perPage ?? lastRequest.perPage,
            status: options.status ?? lastRequest.status,
        }
        publish({
            items: state.items.filter(candidate => candidate.id !== item.id),
            pendingId,
            error: '',
            notice: '',
        })

        try {
            const response = await fetchImpl(`/api/qr-codes/${item.id}/void`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    Authorization: `Bearer ${getToken()}`,
                },
                signal: controller.signal,
            })

            if (disposed) return { kind: 'disposed' }
            if (response.status === 401) {
                publish({ items: [], meta: null, loading: false, error: '' })
                onUnauthorized()
                return { kind: 'unauthorized' }
            }
            if (response.status === 409) {
                const message = inventoryFailureMessage(409)
                const refreshed = await load(request, true, message, 'conflict')
                return { kind: 'conflict', refreshed }
            }
            if (!response.ok) {
                const message = response.status === 403
                    ? inventoryFailureMessage(403)
                    : 'Unable to void this QR record. Please try again.'
                publish({ items: [], meta: null, loading: false, error: message })
                return { kind: 'error', message }
            }

            onVoided(item.id)
            const message = `QR record #${item.id} was voided.`
            const refreshed = await load(request, true, message, 'success')
            return { kind: 'success', refreshed }
        } catch (error) {
            if (disposed) return { kind: 'disposed' }
            if (error?.name === 'AbortError') {
                publish({ items: [], meta: null, loading: false, error: '' })
                return { kind: 'aborted' }
            }
            const message = 'Unable to void this QR record. Please try again.'
            publish({ items: [], meta: null, loading: false, error: message })
            return { kind: 'error', message }
        } finally {
            if (activeVoidController === controller) activeVoidController = null
            if (!disposed) {
                pendingId = null
                publish({ pendingId: null })
            }
        }
    }

    const dispose = () => {
        if (disposed) return
        disposed = true
        requestSequence += 1
        pendingId = null
        activeController?.abort()
        activeVoidController?.abort()
        activeController = null
        activeVoidController = null
    }

    return {
        load,
        retry: () => load(lastRequest),
        voidItem,
        dispose,
        isDisposed: () => disposed,
        snapshot: () => ({ ...state, items: cloneItems(state.items), meta: state.meta ? { ...state.meta } : null }),
    }
}
