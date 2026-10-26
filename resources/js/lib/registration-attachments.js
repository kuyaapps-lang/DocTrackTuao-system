const allowedExtensions = new Set(['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'])

export function validateRegistrationAttachments(files) {
    for (const file of files) {
        const extension = file.name.split('.').pop()?.toLowerCase()
        if (!file.name.includes('.') || !allowedExtensions.has(extension)) return `${file.name}: unsupported file type.`
        if (file.size > 10 * 1024 * 1024) return `${file.name}: exceeds the 10 MB limit.`
    }
    return ''
}

export async function uploadRegistrationAttachments(documentId, files, token, send = fetch) {
    const uploaded = []
    for (const file of files) {
        const body = new FormData()
        body.append('file', file)
        try {
            const response = await send(`/api/documents/${documentId}/attachments`, {
                method: 'POST', headers: { Accept: 'application/json', Authorization: `Bearer ${token}` }, body,
            })
            if (!response.ok) {
                const data = await response.json().catch(() => ({}))
                return { uploaded, failed: file.name, message: data.errors?.file?.[0] || data.message || 'Upload failed.', uncertain: false }
            }
            uploaded.push(file.name)
        } catch {
            return { uploaded, failed: file.name, message: 'The upload response was lost.', uncertain: true }
        }
    }
    return { uploaded, failed: null }
}
