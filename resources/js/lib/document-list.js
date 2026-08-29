const DOCUMENT_VIEWS = new Set([
    'all',
    'incoming',
    'outgoing',
])

const INCOMING_STATES = new Set([
    'all',
    'pending',
    'received',
])

export const DOCUMENT_SEARCH_MAX_LENGTH = 100

const firstQueryValue = (value) => {
    return Array.isArray(value)
        ? value[0]
        : value
}

const searchableValue = (value) => {
    return typeof value === 'string'
        ? value.toLocaleLowerCase()
        : ''
}

export const normalizeDocumentView = (value) => {
    const candidate = firstQueryValue(value)

    return DOCUMENT_VIEWS.has(candidate)
        ? candidate
        : 'all'
}

export const normalizeDocumentSearch = (value) => {
    const candidate = firstQueryValue(value)

    return typeof candidate === 'string'
        ? candidate.trim().slice(
            0,
            DOCUMENT_SEARCH_MAX_LENGTH
        )
        : ''
}

export const isValidDocumentListPayload = (payload) => {
    return (
        Array.isArray(payload) &&
        payload.every(document => {
            return (
                document !== null &&
                typeof document === 'object' &&
                !Array.isArray(document) &&
                Number.isInteger(document.id) &&
                document.id > 0
            )
        })
    )
}

export const normalizeIncomingState = (value) => {
    const candidate = firstQueryValue(value)

    return INCOMING_STATES.has(candidate)
        ? candidate
        : 'all'
}

export const parseDocumentListQuery = (query = {}) => {
    const view = normalizeDocumentView(query.view)

    return {
        view,
        search: normalizeDocumentSearch(query.search),
        incomingState: view === 'incoming'
            ? normalizeIncomingState(query.state)
            : 'all',
    }
}

export const buildDocumentListQuery = ({
    view,
    search,
    incomingState,
} = {}) => {
    const normalizedView = normalizeDocumentView(view)
    const normalizedSearch = normalizeDocumentSearch(search)
    const normalizedState = normalizeIncomingState(incomingState)
    const query = {
        view: normalizedView,
    }

    if (normalizedSearch) {
        query.search = normalizedSearch
    }

    if (
        normalizedView === 'incoming' &&
        normalizedState !== 'all'
    ) {
        query.state = normalizedState
    }

    return query
}

const relevantOfficeName = (document, view) => {
    if (view === 'incoming') {
        return document?.routes?.[0]?.from_office?.office_name
    }

    if (view === 'outgoing') {
        return document?.routes?.[0]?.to_office?.office_name
    }

    return document?.current_office?.office_name
}

export const filterDocuments = (
    documents,
    {
        view = 'all',
        search = '',
        incomingState = 'all',
    } = {}
) => {
    const source = Array.isArray(documents)
        ? documents
        : []
    const normalizedView = normalizeDocumentView(view)
    const normalizedSearch = searchableValue(
        normalizeDocumentSearch(search)
    )
    const normalizedState = normalizeIncomingState(incomingState)

    return source.filter(document => {
        if (
            normalizedView === 'incoming' &&
            normalizedState !== 'all'
        ) {
            const route = document?.routes?.[0]

            if (!route) {
                return false
            }

            const isReceived = Boolean(
                route.received_at
            )

            if (
                (normalizedState === 'received') !== isReceived
            ) {
                return false
            }
        }

        if (!normalizedSearch) {
            return true
        }

        return [
            document?.tracking_no,
            document?.title,
            document?.type?.type_name,
            relevantOfficeName(document, normalizedView),
        ].some(value => {
            return searchableValue(value).includes(normalizedSearch)
        })
    })
}
