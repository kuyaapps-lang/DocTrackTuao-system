const REQUEST_STATUSES = ['pending', 'approved', 'rejected']

const isRecord = value => value !== null && typeof value === 'object' && !Array.isArray(value)
const positiveInteger = value => Number.isSafeInteger(value) && value > 0
const nullableString = value => value === null || typeof value === 'string'
const requestStatus = value => REQUEST_STATUSES.includes(value)

const userShape = value => value === null || (
    isRecord(value) &&
    positiveInteger(value.id) &&
    typeof value.name === 'string'
)

const officeShape = value => value === null || (
    isRecord(value) &&
    positiveInteger(value.id) &&
    typeof value.office_name === 'string'
)

const qrShape = value => isRecord(value) &&
    positiveInteger(value.id) &&
    typeof value.qr_token === 'string' &&
    typeof value.status === 'string' &&
    typeof value.linked === 'boolean' &&
    typeof value.scan_path === 'string'

export const isQrCodeRequest = value => isRecord(value) &&
    positiveInteger(value.id) &&
    positiveInteger(value.quantity) &&
    nullableString(value.purpose) &&
    requestStatus(value.status) &&
    userShape(value.requested_by) &&
    officeShape(value.requested_office) &&
    userShape(value.reviewed_by) &&
    nullableString(value.reviewed_at) &&
    nullableString(value.review_note) &&
    Array.isArray(value.qr_codes) &&
    value.qr_codes.every(qrShape)

export const isQrCodeRequestListResponse = value => isRecord(value) &&
    Array.isArray(value.data) &&
    value.data.every(isQrCodeRequest)

export const qrRequestFailureMessage = status => {
    if (status === 401) return 'Authentication is required.'
    if (status === 403) return 'You are not authorized to manage QR requests.'
    if (status === 409) return 'This QR request has already been reviewed.'
    if (status === 422) return 'The QR request is invalid.'
    return 'Unable to load QR requests. Please try again.'
}

export const createQrRequestPayload = ({ quantity, purpose }) => ({
    quantity,
    purpose: typeof purpose === 'string' ? purpose.trim() : '',
})

export const fetchQrCodeRequests = async ({ fetchImpl, getToken, status = '' }) => {
    const query = REQUEST_STATUSES.includes(status)
        ? `?status=${encodeURIComponent(status)}`
        : ''
    const response = await fetchImpl(`/api/qr-code-requests${query}`, {
        headers: {
            Accept: 'application/json',
            Authorization: `Bearer ${getToken()}`,
        },
    })

    if (!response.ok) {
        throw new Error(qrRequestFailureMessage(response.status))
    }

    const payload = await response.json()
    if (!isQrCodeRequestListResponse(payload)) {
        throw new Error(qrRequestFailureMessage())
    }

    return payload.data.map(request => ({
        ...request,
        qr_codes: request.qr_codes.map(qr => ({ ...qr })),
    }))
}

export const submitQrCodeRequest = async ({ fetchImpl, getToken, form }) => {
    const response = await fetchImpl('/api/qr-code-requests', {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            Authorization: `Bearer ${getToken()}`,
        },
        body: JSON.stringify(createQrRequestPayload(form)),
    })

    const payload = await response.json()
    if (!response.ok) {
        throw new Error(qrRequestFailureMessage(response.status))
    }
    if (!isQrCodeRequest(payload.request)) {
        throw new Error(qrRequestFailureMessage())
    }

    return payload
}

export const reviewQrCodeRequest = async ({
    fetchImpl,
    getToken,
    requestId,
    action,
    reviewNote = '',
}) => {
    if (!positiveInteger(requestId) || !['approve', 'reject'].includes(action)) {
        throw new Error(qrRequestFailureMessage(422))
    }

    const response = await fetchImpl(`/api/qr-code-requests/${requestId}/${action}`, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            Authorization: `Bearer ${getToken()}`,
        },
        body: JSON.stringify({ review_note: reviewNote.trim() }),
    })

    const payload = await response.json()
    if (!response.ok) {
        throw new Error(qrRequestFailureMessage(response.status))
    }
    if (!isQrCodeRequest(payload.request)) {
        throw new Error(qrRequestFailureMessage())
    }

    return payload
}
