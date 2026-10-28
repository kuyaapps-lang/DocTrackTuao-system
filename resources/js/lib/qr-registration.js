export const qrTokenFromPublicUrl = value => {
    if (typeof value !== 'string') {
        return ''
    }

    const input = value.trim()

    try {
        const url = new URL(input)

        if (!['http:', 'https:'].includes(url.protocol)) {
            return ''
        }

        const match = url.pathname.match(/^\/q\/([^/]+)\/?$/i)

        return match ? decodeURIComponent(match[1]).trim() : ''
    } catch {
        return ''
    }
}

export const normalizeRegistrationQrInput = value => {
    if (typeof value !== 'string') {
        return ''
    }

    const input = value.trim()

    return qrTokenFromPublicUrl(input) || input
}

export const isValidRegistrationQrToken = value => typeof value === 'string' &&
    /^(?:[A-HJ-KM-NP-Z2-9]{5}-[A-HJ-KM-NP-Z2-9]{7}|[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[1-5][0-9A-Fa-f]{3}-[89ABab][0-9A-Fa-f]{3}-[0-9A-Fa-f]{12})$/.test(value)

export const publicQrUrl = (token, locationRef = window.location) => {
    const url = new URL(locationRef.href)

    // Printed labels should target the Apache site, not a legacy Artisan URL.
    if (url.port === '8000') {
        url.port = ''
    }

    url.pathname = `/q/${encodeURIComponent(token)}`
    url.search = ''
    url.hash = ''

    return url.toString()
}
