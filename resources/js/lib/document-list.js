const DOCUMENT_VIEWS = new Set(['all', 'incoming', 'outgoing'])
const INCOMING_STATES = new Set(['all', 'pending', 'received'])

export const DOCUMENT_LIST_DEFAULT_PER_PAGE = 25
export const DOCUMENT_LIST_PER_PAGE_OPTIONS = Object.freeze([10, 25, 50])
export const DOCUMENT_SEARCH_DEBOUNCE_MS = 300
export const DOCUMENT_SEARCH_MAX_LENGTH = 100

const firstQueryValue = value => Array.isArray(value) ? value[0] : value
const isPlainObject = value => (
    value !== null && typeof value === 'object' && !Array.isArray(value)
)

export const normalizeDocumentView = value => {
    const candidate = firstQueryValue(value)
    return DOCUMENT_VIEWS.has(candidate) ? candidate : 'all'
}

export const normalizeDocumentSearch = value => {
    const candidate = firstQueryValue(value)
    return typeof candidate === 'string'
        ? candidate.trim().slice(0, DOCUMENT_SEARCH_MAX_LENGTH)
        : ''
}

export const normalizeIncomingState = value => {
    const candidate = firstQueryValue(value)
    return INCOMING_STATES.has(candidate) ? candidate : 'all'
}

export const normalizeDocumentPage = value => {
    const candidate = Number(firstQueryValue(value))
    return Number.isInteger(candidate) && candidate >= 1 ? candidate : 1
}

export const normalizeDocumentPerPage = value => {
    const candidate = Number(firstQueryValue(value))
    return DOCUMENT_LIST_PER_PAGE_OPTIONS.includes(candidate)
        ? candidate
        : DOCUMENT_LIST_DEFAULT_PER_PAGE
}

export const parseDocumentListQuery = (query = {}) => {
    const view = normalizeDocumentView(query.view)
    return {
        view,
        search: normalizeDocumentSearch(query.search),
        incomingState: view === 'incoming'
            ? normalizeIncomingState(query.state)
            : 'all',
        page: normalizeDocumentPage(query.page),
        perPage: normalizeDocumentPerPage(query.per_page),
    }
}

export const buildDocumentListQuery = (state = {}) => {
    const normalized = parseDocumentListQuery({
        view: state.view,
        search: state.search,
        state: state.incomingState,
        page: state.page,
        per_page: state.perPage,
    })
    const query = { view: normalized.view }

    if (normalized.search) query.search = normalized.search
    if (normalized.view === 'incoming' && normalized.incomingState !== 'all') {
        query.state = normalized.incomingState
    }
    if (normalized.page !== 1) query.page = String(normalized.page)
    if (normalized.perPage !== DOCUMENT_LIST_DEFAULT_PER_PAGE) {
        query.per_page = String(normalized.perPage)
    }

    return query
}

export const resetDocumentListPage = (state, changes = {}) => ({
    ...state,
    ...changes,
    page: 1,
})

export const buildDocumentListRequestQuery = (state = {}) => {
    const normalized = parseDocumentListQuery({
        view: state.view,
        search: state.search,
        state: state.incomingState,
        page: state.page,
        per_page: state.perPage,
    })
    const query = {
        page: String(normalized.page),
        per_page: String(normalized.perPage),
    }

    if (normalized.search) query.search = normalized.search
    if (normalized.view === 'incoming' && normalized.incomingState !== 'all') {
        query.state = normalized.incomingState
    }

    return query
}

const isValidDocumentListItem = document => (
    isPlainObject(document) &&
    Number.isInteger(document.id) &&
    document.id > 0
)

const isNullablePositiveInteger = value => (
    value === null || (Number.isInteger(value) && value >= 1)
)

export const isValidDocumentListResponse = payload => {
    if (
        !isPlainObject(payload) ||
        !Array.isArray(payload.data) ||
        !payload.data.every(isValidDocumentListItem) ||
        !isPlainObject(payload.meta) ||
        !isPlainObject(payload.links)
    ) return false

    const { meta, links } = payload
    const validMeta = (
        Number.isInteger(meta.current_page) && meta.current_page >= 1 &&
        Number.isInteger(meta.last_page) && meta.last_page >= 1 &&
        DOCUMENT_LIST_PER_PAGE_OPTIONS.includes(meta.per_page) &&
        Number.isInteger(meta.total) && meta.total >= 0 &&
        isNullablePositiveInteger(meta.from) &&
        isNullablePositiveInteger(meta.to) &&
        ((meta.from === null) === (meta.to === null)) &&
        (meta.total !== 0 || (meta.from === null && meta.to === null))
    )
    const validLinks = (
        typeof links.first === 'string' && links.first.length > 0 &&
        typeof links.last === 'string' && links.last.length > 0 &&
        (links.prev === null || typeof links.prev === 'string') &&
        (links.next === null || typeof links.next === 'string')
    )

    return validMeta && validLinks
}

export const getDocumentPaginationState = (meta = {}) => {
    const currentPage = normalizeDocumentPage(meta.current_page)
    const lastPage = normalizeDocumentPage(meta.last_page)
    return {
        canGoPrevious: currentPage > 1,
        canGoNext: currentPage < lastPage,
        previousPage: Math.max(1, currentPage - 1),
        nextPage: Math.min(lastPage, currentPage + 1),
    }
}
