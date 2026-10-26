export function filterDocumentTypes(types, query) {
    const needle = query.trim().toLocaleLowerCase()
    if (!needle) return types

    return types.filter(type => {
        const name = String(type.type_name || '').toLocaleLowerCase()
        const initials = name.match(/[\p{L}\p{N}]+/gu)?.map(word => word[0]).join('') || ''
        return name.includes(needle) || initials.includes(needle.replace(/[\s.]/g, ''))
    })
}
