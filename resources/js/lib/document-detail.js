const TERMINAL_STATUSES = new Set(['completed', 'archived'])

export const documentStatusName = document => (
    typeof document?.status?.status_name === 'string'
        ? document.status.status_name
        : ''
)

export const isTerminalDocument = document => (
    TERMINAL_STATUSES.has(documentStatusName(document).toLowerCase())
)

export const canCompleteDocument = ({
    document,
    routingOptions,
    pendingRoute,
    hasProcessPermission,
} = {}) => (
    hasProcessPermission === true &&
    routingOptions?.can_act === true &&
    pendingRoute === null &&
    !isTerminalDocument(document)
)

export const completeDocumentFailureMessage = status => {
    if (status === 403) {
        return 'You are not allowed to complete this document.'
    }

    if (status === 409) {
        return 'This document cannot be completed in its current state.'
    }

    if (status === 422) {
        return 'Unable to complete document. Please check the document state and try again.'
    }

    return 'Unable to complete document.'
}

export const completeDocumentRequest = async ({
    fetchImpl,
    documentId,
    token,
} = {}) => {
    const response = await fetchImpl(
        `/api/documents/${documentId}/complete`,
        {
            method: 'POST',

            headers: {
                Accept: 'application/json',
                Authorization: `Bearer ${token}`,
            },
        }
    )

    if (!response.ok) {
        throw new Error(
            completeDocumentFailureMessage(response.status)
        )
    }

    return response.json()
}
