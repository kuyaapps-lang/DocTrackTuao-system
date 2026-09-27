export const normalizeRegistrationQrInput = value => {
    if (typeof value !== 'string') {
        return ''
    }

    const input = value.trim()

    if (!input) {
        return ''
    }

    try {
        const url = new URL(input)

        if (!['http:', 'https:'].includes(url.protocol)) {
            return input
        }

        const segment = url.pathname
            .split('/')
            .filter(Boolean)
            .at(-1)

        return segment ? decodeURIComponent(segment).trim() : input
    } catch {
        return input
    }
}

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
