export function inputMaxLength(type, maxlength, unrestricted = false) {
    const existing = maxlength === undefined ? undefined : Number(maxlength)
    if (unrestricted || ['email', 'password'].includes(type)) return existing
    if (!['text', 'search', 'tel', 'url'].includes(type)) return existing
    return Number.isFinite(existing) && existing >= 0 ? Math.min(existing, 20) : 20
}
