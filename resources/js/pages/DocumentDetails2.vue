<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import QRCode from 'qrcode'

import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card'

import { Button } from '@/components/ui/button'

const route = useRoute()
const router = useRouter()

/*
|--------------------------------------------------------------------------
| Main Data
|--------------------------------------------------------------------------
*/

const document = ref(null)
const routingOptions = ref(null)
const history = ref([])

const loading = ref(true)
const actionLoading = ref(false)

const error = ref('')
const successMessage = ref('')

/*
|--------------------------------------------------------------------------
| Current Processing
|--------------------------------------------------------------------------
*/

const processingInfo = ref(null)
const processingLoading = ref(false)
const processingSaving = ref(false)
const processingError = ref('')

const processingForm = ref({
    current_action_id: '',
    processing_note: '',
})

/*
|--------------------------------------------------------------------------
| Attachments
|--------------------------------------------------------------------------
*/

const attachments = ref([])
const attachmentsLoading = ref(false)
const uploadingAttachment = ref(false)
const attachmentError = ref('')
const selectedFiles = ref([])
const attachmentInput = ref(null)

const showDeleteAttachmentModal = ref(false)
const attachmentToDelete = ref(null)
const deletingAttachment = ref(false)

/*
|--------------------------------------------------------------------------
| QR Code
|--------------------------------------------------------------------------
*/

const qrDataUrl = ref('')
const qrError = ref('')

/*
|--------------------------------------------------------------------------
| Forward Modal
|--------------------------------------------------------------------------
*/

const showForwardModal = ref(false)

const forwardForm = ref({
    to_office_id: '',
    remarks: '',
})

const forwardError = ref('')

/*
|--------------------------------------------------------------------------
| Receive Modal
|--------------------------------------------------------------------------
*/

const showReceiveModal = ref(false)
const receiveError = ref('')

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

const getToken = () => {
    return localStorage.getItem('auth_token')
}

/*
|--------------------------------------------------------------------------
| Fetch Document
|--------------------------------------------------------------------------
*/

const fetchDocument = async () => {
    try {
        const response = await fetch(
            `/api/documents/${route.params.id}`,
            {
                headers: {
                    Accept: 'application/json',
                    Authorization: `Bearer ${getToken()}`,
                },
            }
        )

        const data = await response.json()

        if (!response.ok) {
            throw new Error(
                data.message ||
                'Unable to load document.'
            )
        }

        document.value = data

        await generateQRCode()

    } catch (err) {
        throw new Error(
            err.message ||
            'Unable to load document.'
        )
    }
}

/*
|--------------------------------------------------------------------------
| Generate QR Code
|--------------------------------------------------------------------------
*/

const generateQRCode = async () => {
    qrError.value = ''
    qrDataUrl.value = ''

    if (!document.value) {
        return
    }

    const qrToken =
        document.value.qr_code?.qr_token

    /*
    |--------------------------------------------------------------------------
    | Canonical issued QR only
    |--------------------------------------------------------------------------
    |
    | Do not create a second QR from the tracking number. The QR shown here is
    | the same issued token that was printed on the ORIGINAL and RECORD COPY.
    |
    */

    if (!qrToken) {
        return
    }

    try {
        const documentUrl =
            `${window.location.origin}/q/${encodeURIComponent(
                qrToken
            )}`

        qrDataUrl.value =
            await QRCode.toDataURL(
                documentUrl,
                {
                    width: 300,
                    margin: 2,
                    errorCorrectionLevel: 'H',
                }
            )

    } catch (err) {
        qrError.value =
            'Unable to generate the issued QR code.'
    }
}

/*
|--------------------------------------------------------------------------
| Print QR
|--------------------------------------------------------------------------
*/

const printQRCode = () => {
    if (
        !qrDataUrl.value ||
        !document.value ||
        !document.value.qr_code?.qr_token
    ) {
        return
    }

    const printWindow =
        window.open(
            '',
            '_blank',
            'width=600,height=700'
        )

    if (!printWindow) {
        qrError.value =
            'Unable to open print window. Please allow pop-ups.'
        return
    }

    const qrToken =
        document.value.qr_code.qr_token

    const trackingNo =
        document.value.tracking_no || ''

    const title =
        document.value.title || ''

    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Issued QR - ${qrToken}</title>

            <style>
                body {
                    font-family: Arial, sans-serif;
                    text-align: center;
                    padding: 40px;
                }

                .card {
                    max-width: 420px;
                    margin: auto;
                    border: 1px solid #d1d5db;
                    border-radius: 12px;
                    padding: 30px;
                }

                img {
                    width: 280px;
                    height: 280px;
                }

                h2 {
                    margin-bottom: 5px;
                }

                .token {
                    font-family: monospace;
                    font-size: 20px;
                    font-weight: bold;
                    margin-top: 15px;
                }

                .tracking {
                    margin-top: 8px;
                    font-size: 14px;
                    color: #4b5563;
                }

                .title {
                    margin-top: 8px;
                    color: #4b5563;
                }

                .instruction {
                    margin-top: 18px;
                    font-size: 13px;
                    color: #6b7280;
                }
            </style>
        </head>

        <body>

            <div class="card">

                <h2>
                    LGU Tuao Document Tracking
                </h2>

                <img
                    src="${qrDataUrl.value}"
                    alt="Issued Document QR Code"
                >

                <div class="token">
                    ${qrToken}
                </div>

                <div class="tracking">
                    ${trackingNo}
                </div>

                <div class="title">
                    ${title}
                </div>

                <div class="instruction">
                    This is the issued QR linked to this document.
                </div>

            </div>

            <script>
                window.onload = function () {
                    window.print();
                };
            <\/script>

        </body>
        </html>
    `)

    printWindow.document.close()
}

/*
|--------------------------------------------------------------------------
| Fetch Attachments
|--------------------------------------------------------------------------
*/

const fetchAttachments = async () => {
    attachmentsLoading.value = true
    attachmentError.value = ''

    try {
        const response = await fetch(
            `/api/documents/${route.params.id}/attachments`,
            {
                headers: {
                    Accept: 'application/json',
                    Authorization: `Bearer ${getToken()}`,
                },
            }
        )

        const data = await response.json()

        if (!response.ok) {
            throw new Error(
                data.message ||
                'Unable to load attachments.'
            )
        }

        attachments.value =
            Array.isArray(data)
                ? data
                : []

    } catch (err) {
        attachmentError.value =
            err.message ||
            'Unable to load attachments.'
    } finally {
        attachmentsLoading.value = false
    }
}

/*
|--------------------------------------------------------------------------
| Attachment Permissions
|--------------------------------------------------------------------------
*/

const canManageAttachments = computed(() => {
    return routingOptions.value?.can_act === true
})

const canAccessAttachments = computed(() => {
    const userOfficeId =
        Number(
            routingOptions.value?.user?.office_id
        )

    if (!userOfficeId || !document.value) {
        return false
    }

    return (
        userOfficeId ===
            Number(document.value.current_office_id) ||
        userOfficeId ===
            Number(document.value.origin_office_id)
    )
})

/*
|--------------------------------------------------------------------------
| Select Attachments
|--------------------------------------------------------------------------
*/

const openAttachmentPicker = () => {
    if (uploadingAttachment.value) {
        return
    }

    attachmentInput.value?.click()
}

const handleFileChange = (event) => {
    attachmentError.value = ''

    const files =
        Array.from(
            event.target.files || []
        )

    if (files.length === 0) {
        return
    }

    const maxSize =
        10 * 1024 * 1024

    const allowedExtensions = [
        'pdf',
        'doc',
        'docx',
        'xls',
        'xlsx',
        'jpg',
        'jpeg',
        'png',
    ]

    const acceptedFiles = []
    const rejectedMessages = []

    for (const file of files) {
        const extension =
            file.name
                .split('.')
                .pop()
                ?.toLowerCase()

        if (
            !extension ||
            !allowedExtensions.includes(extension)
        ) {
            rejectedMessages.push(
                `${file.name}: unsupported file type.`
            )
            continue
        }

        if (file.size > maxSize) {
            rejectedMessages.push(
                `${file.name}: exceeds the 10 MB limit.`
            )
            continue
        }

        /*
        |--------------------------------------------------------------------------
        | Prevent duplicate pending selections.
        |--------------------------------------------------------------------------
        */

        const alreadySelected =
            selectedFiles.value.some(
                selected =>
                    selected.name === file.name &&
                    selected.size === file.size &&
                    selected.lastModified === file.lastModified
            )

        if (!alreadySelected) {
            acceptedFiles.push(file)
        }
    }

    selectedFiles.value = [
        ...selectedFiles.value,
        ...acceptedFiles,
    ]

    if (rejectedMessages.length > 0) {
        attachmentError.value =
            rejectedMessages.join(' ')
    }

    /*
    |--------------------------------------------------------------------------
    | Reset input so the same file may be selected again after removal.
    |--------------------------------------------------------------------------
    */

    if (attachmentInput.value) {
        attachmentInput.value.value = ''
    }
}

/*
|--------------------------------------------------------------------------
| Remove Pending Attachment
|--------------------------------------------------------------------------
*/

const removeSelectedFile = (index) => {
    if (uploadingAttachment.value) {
        return
    }

    selectedFiles.value.splice(index, 1)

    attachmentError.value = ''
}

/*
|--------------------------------------------------------------------------
| Upload Attachments
|--------------------------------------------------------------------------
|
| The existing Laravel endpoint accepts one file per request.
| Vue therefore uploads the selected files one at a time while keeping
| the backend unchanged.
|
*/

const uploadAttachments = async () => {
    attachmentError.value = ''
    successMessage.value = ''

    if (selectedFiles.value.length === 0) {
        attachmentError.value =
            'Please select at least one file to upload.'
        return
    }

    uploadingAttachment.value = true

    const filesToUpload = [
        ...selectedFiles.value,
    ]

    let uploadedCount = 0

    try {
        for (const file of filesToUpload) {
            const formData =
                new FormData()

            formData.append(
                'file',
                file
            )

            const response = await fetch(
                `/api/documents/${route.params.id}/attachments`,
                {
                    method: 'POST',

                    headers: {
                        Accept: 'application/json',
                        Authorization: `Bearer ${getToken()}`,
                    },

                    body: formData,
                }
            )

            const data = await response.json()

            if (!response.ok) {
                if (data.errors) {
                    const firstError =
                        Object.values(
                            data.errors
                        )[0]

                    throw new Error(
                        `${file.name}: ${
                            Array.isArray(firstError)
                                ? firstError[0]
                                : firstError
                        }`
                    )
                }

                throw new Error(
                    `${file.name}: ${
                        data.message ||
                        'Unable to upload attachment.'
                    }`
                )
            }

            uploadedCount++
        }

        selectedFiles.value = []

        successMessage.value =
            uploadedCount === 1
                ? 'Attachment uploaded successfully.'
                : `${uploadedCount} attachments uploaded successfully.`

        await fetchAttachments()

    } catch (err) {
        /*
        |--------------------------------------------------------------------------
        | Remove files that already uploaded successfully from the pending list.
        | Leave the failed file and any files after it so the user can retry.
        |--------------------------------------------------------------------------
        */

        selectedFiles.value =
            filesToUpload.slice(uploadedCount)

        attachmentError.value =
            err.message ||
            'Unable to upload attachments.'

        if (uploadedCount > 0) {
            await fetchAttachments()
        }
    } finally {
        uploadingAttachment.value = false
    }
}

/*
|--------------------------------------------------------------------------
| Download Attachment
|--------------------------------------------------------------------------
*/

const downloadAttachment = async (attachment) => {
    attachmentError.value = ''

    try {
        const response = await fetch(
            `/api/attachments/${attachment.id}/download`,
            {
                headers: {
                    Accept: 'application/octet-stream',
                    Authorization: `Bearer ${getToken()}`,
                },
            }
        )

        if (!response.ok) {
            let message =
                'Unable to download attachment.'

            try {
                const data =
                    await response.json()

                message =
                    data.message || message
            } catch {
                // Non-JSON error response.
            }

            throw new Error(message)
        }

        const blob =
            await response.blob()

        const url =
            window.URL.createObjectURL(blob)

        const link =
            window.document.createElement('a')

        link.href = url
        link.download =
            attachment.original_filename ||
            'attachment'

        window.document.body.appendChild(link)

        link.click()
        link.remove()

        window.URL.revokeObjectURL(url)

    } catch (err) {
        attachmentError.value =
            err.message ||
            'Unable to download attachment.'
    }
}

/*
|--------------------------------------------------------------------------
| Delete Attachment
|--------------------------------------------------------------------------
*/

const openDeleteAttachmentModal = (attachment) => {
    attachmentToDelete.value = attachment
    attachmentError.value = ''
    successMessage.value = ''
    showDeleteAttachmentModal.value = true
}

const closeDeleteAttachmentModal = () => {
    if (deletingAttachment.value) {
        return
    }

    showDeleteAttachmentModal.value = false
    attachmentToDelete.value = null
}

const deleteAttachment = async () => {
    if (!attachmentToDelete.value) {
        return
    }

    deletingAttachment.value = true
    attachmentError.value = ''

    try {
        const response = await fetch(
            `/api/attachments/${attachmentToDelete.value.id}`,
            {
                method: 'DELETE',

                headers: {
                    Accept: 'application/json',
                    Authorization: `Bearer ${getToken()}`,
                },
            }
        )

        const data = await response.json()

        if (!response.ok) {
            throw new Error(
                data.message ||
                'Unable to delete attachment.'
            )
        }

        showDeleteAttachmentModal.value = false
        attachmentToDelete.value = null

        successMessage.value =
            'Attachment deleted successfully.'

        await fetchAttachments()

    } catch (err) {
        attachmentError.value =
            err.message ||
            'Unable to delete attachment.'
    } finally {
        deletingAttachment.value = false
    }
}

/*
|--------------------------------------------------------------------------
| File Size Formatting
|--------------------------------------------------------------------------
*/

const formatFileSize = (bytes) => {
    if (
        bytes === null ||
        bytes === undefined
    ) {
        return 'N/A'
    }

    const size =
        Number(bytes)

    if (size < 1024) {
        return `${size} B`
    }

    if (size < 1024 * 1024) {
        return `${(size / 1024).toFixed(1)} KB`
    }

    return `${(
        size /
        (1024 * 1024)
    ).toFixed(2)} MB`
}

/*
|--------------------------------------------------------------------------
| Fetch Current Processing
|--------------------------------------------------------------------------
*/

const fetchProcessing = async () => {
    processingLoading.value = true
    processingError.value = ''

    try {
        const response = await fetch(
            `/api/documents/${route.params.id}/processing`,
            {
                headers: {
                    Accept: 'application/json',
                    Authorization: `Bearer ${getToken()}`,
                },
            }
        )

        const data = await response.json()

        if (!response.ok) {
            throw new Error(
                data.message ||
                'Unable to load current processing information.'
            )
        }

        processingInfo.value = data

        processingForm.value = {
            current_action_id:
                data.current_action?.id
                    ? String(
                        data.current_action.id
                    )
                    : '',

            processing_note:
                data.processing_note || '',
        }

    } catch (err) {
        processingError.value =
            err.message ||
            'Unable to load current processing information.'

        throw err

    } finally {
        processingLoading.value = false
    }
}

/*
|--------------------------------------------------------------------------
| Can Update Current Processing
|--------------------------------------------------------------------------
*/

const canUpdateProcessing = computed(() => {
    return (
        processingInfo.value?.can_update ===
        true
    )
})

const processingEventLabel = (eventType) => {
    const labels = {
        registered: 'Registered',
        action_updated: 'Action Updated',
        forwarded: 'Forwarded',
        received: 'Received',
    }

    return (
        labels[eventType] ||
        'Processing Update'
    )
}


/*
|--------------------------------------------------------------------------
| Consolidated Document History
|--------------------------------------------------------------------------
|
| Processing logs are the authoritative source for Process 3 events.
| Older movement routes created before Processing History existed are
| converted into table rows so legacy document movements remain visible.
| A route already represented by a processing log is not added again.
|
*/

const findRouteForProcessingLog = (item) => {
    const routeId = Number(
        item?.document_route_id ||
        item?.route?.id ||
        0
    )

    if (!routeId) {
        return item?.route || null
    }

    return (
        history.value.find(
            routeItem =>
                Number(routeItem.id) === routeId
        ) ||
        item?.route ||
        null
    )
}

const historyTimestamp = (date) => {
    if (!date) {
        return 0
    }

    const value = new Date(date).getTime()

    return Number.isNaN(value)
        ? 0
        : value
}

/*
|--------------------------------------------------------------------------
| Reconstruct Document Status At A Historical Time
|--------------------------------------------------------------------------
|
| Processing logs currently store the action and event, but not a separate
| document-status snapshot. For manual processing updates we reconstruct the
| status from the latest routing movement that happened before the log time.
|
*/

const statusAtDate = (date) => {
    const targetTime =
        historyTimestamp(date)

    let latestStatus =
        'Registered'

    let latestTime = 0

    for (const routeItem of history.value) {
        const forwardedTime =
            historyTimestamp(
                routeItem?.forwarded_at
            )

        if (
            forwardedTime &&
            forwardedTime <= targetTime &&
            forwardedTime >= latestTime
        ) {
            latestStatus = 'Forwarded'
            latestTime = forwardedTime
        }

        const receivedTime =
            historyTimestamp(
                routeItem?.received_at
            )

        if (
            receivedTime &&
            receivedTime <= targetTime &&
            receivedTime >= latestTime
        ) {
            latestStatus = 'Received'
            latestTime = receivedTime
        }
    }

    return latestStatus
}

const historyRows = computed(() => {
    const processingLogs =
        Array.isArray(
            processingInfo.value?.history
        )
            ? processingInfo.value.history
            : []

    /*
    |--------------------------------------------------------------------------
    | Route IDs already represented in Processing History
    |--------------------------------------------------------------------------
    */

    const representedRouteIds =
        new Set(
            processingLogs
                .map(item =>
                    Number(
                        item?.document_route_id ||
                        item?.route?.id ||
                        0
                    )
                )
                .filter(Boolean)
        )

    /*
    |--------------------------------------------------------------------------
    | Process 3 processing rows
    |--------------------------------------------------------------------------
    */

    const processingRows =
        processingLogs.map(item => {
            const linkedRoute =
                findRouteForProcessingLog(
                    item
                )

            const fromOffice =
                linkedRoute?.from_office
                    ?.office_name ||
                item?.office
                    ?.office_name ||
                document.value
                    ?.origin_office
                    ?.office_name ||
                'N/A'

            const toOffice =
                linkedRoute?.to_office
                    ?.office_name ||
                item?.office
                    ?.office_name ||
                'N/A'

            const actionName =
                item?.action?.action_name ||
                'N/A'

            let status =
                statusAtDate(
                    item?.created_at
                )

            let actionTaken =
                actionName

            let detail =
                item?.processing_note ||
                item?.event_note ||
                ''

            let byOffice =
                item?.office
                    ?.office_name ||
                'N/A'

            if (
                item?.event_type ===
                'registered'
            ) {
                status = 'Registered'
                actionTaken =
                    actionName !== 'N/A'
                        ? actionName
                        : 'Registered'
            }

            if (
                item?.event_type ===
                'forwarded'
            ) {
                status = 'Forwarded'

                actionTaken =
                    `${actionName} → ${toOffice}`

                detail =
                    linkedRoute?.remarks ||
                    item?.event_note ||
                    ''

                byOffice =
                    linkedRoute
                        ?.from_office
                        ?.office_name ||
                    item?.office
                        ?.office_name ||
                    'N/A'
            }

            if (
                item?.event_type ===
                'received'
            ) {
                status = 'Received'

                actionTaken =
                    actionName !== 'N/A'
                        ? actionName
                        : 'For Action'

                byOffice =
                    toOffice
            }

            return {
                key:
                    `processing-${item.id}`,

                status,

                action:
                    processingEventLabel(
                        item?.event_type
                    ),

                from_office:
                    fromOffice,

                action_taken:
                    actionTaken,

                detail,

                date:
                    item?.created_at ||
                    null,

                by_name:
                    item?.user?.name ||
                    'N/A',

                by_office:
                    byOffice,

                source:
                    'processing',
            }
        })

    /*
    |--------------------------------------------------------------------------
    | Legacy movement rows
    |--------------------------------------------------------------------------
    |
    | Routes created before Process 3 have no processing log. Each old route
    | contributes a Forwarded row and, when applicable, a Received row.
    |
    */

    const legacyMovementRows = []

    for (
        const routeItem of
        history.value
    ) {
        if (
            representedRouteIds.has(
                Number(routeItem.id)
            )
        ) {
            continue
        }

        const fromOffice =
            routeItem?.from_office
                ?.office_name ||
            'N/A'

        const toOffice =
            routeItem?.to_office
                ?.office_name ||
            'N/A'

        if (routeItem?.forwarded_at) {
            legacyMovementRows.push({
                key:
                    `route-${routeItem.id}-forwarded`,

                status:
                    'Forwarded',

                action:
                    routeItem?.action
                        ?.action_name ||
                    'Forwarded',

                from_office:
                    fromOffice,

                action_taken:
                    routeItem?.received_at
                        ? `To ${toOffice}`
                        : `To ${toOffice} — Awaiting Receipt`,

                detail:
                    routeItem?.remarks ||
                    '',

                date:
                    routeItem.forwarded_at,

                by_name:
                    routeItem?.forwarded_by
                        ?.name ||
                    'N/A',

                by_office:
                    fromOffice,

                source:
                    'movement',
            })
        }

        if (routeItem?.received_at) {
            legacyMovementRows.push({
                key:
                    `route-${routeItem.id}-received`,

                status:
                    'Received',

                action:
                    'Received',

                from_office:
                    fromOffice,

                action_taken:
                    `Received by ${toOffice}`,

                detail:
                    '',

                date:
                    routeItem.received_at,

                by_name:
                    routeItem?.received_by
                        ?.name ||
                    'N/A',

                by_office:
                    toOffice,

                source:
                    'movement',
            })
        }
    }

    return [
        ...processingRows,
        ...legacyMovementRows,
    ].sort(
        (a, b) =>
            historyTimestamp(b.date) -
            historyTimestamp(a.date)
    )
})

/*
|--------------------------------------------------------------------------
| Save Current Processing
|--------------------------------------------------------------------------
*/

const saveProcessing = async () => {
    processingError.value = ''
    successMessage.value = ''

    if (
        !processingForm.value
            .current_action_id
    ) {
        processingError.value =
            'Please select a current action.'

        return
    }

    processingSaving.value = true

    try {
        const response = await fetch(
            `/api/documents/${route.params.id}/processing`,
            {
                method: 'PUT',

                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    Authorization: `Bearer ${getToken()}`,
                },

                body: JSON.stringify({
                    current_action_id:
                        Number(
                            processingForm.value
                                .current_action_id
                        ),

                    processing_note:
                        processingForm.value
                            .processing_note
                            .trim() ||
                        null,
                }),
            }
        )

        const data =
            await response.json()

        if (!response.ok) {
            if (data.errors) {
                const firstError =
                    Object.values(
                        data.errors
                    )[0]

                throw new Error(
                    Array.isArray(firstError)
                        ? firstError[0]
                        : firstError
                )
            }

            throw new Error(
                data.message ||
                'Unable to update current processing.'
            )
        }

        successMessage.value =
            data.message ||
            'Current processing updated successfully.'

        await Promise.all([
            fetchProcessing(),
            fetchDocument(),
        ])

    } catch (err) {
        processingError.value =
            err.message ||
            'Unable to update current processing.'

    } finally {
        processingSaving.value = false
    }
}

/*
|--------------------------------------------------------------------------
| Fetch Routing Options
|--------------------------------------------------------------------------
*/

const fetchRoutingOptions = async () => {
    try {
        const response = await fetch(
            `/api/documents/${route.params.id}/routing-options`,
            {
                headers: {
                    Accept: 'application/json',
                    Authorization: `Bearer ${getToken()}`,
                },
            }
        )

        const data = await response.json()

        if (!response.ok) {
            throw new Error(
                data.message ||
                'Unable to load routing information.'
            )
        }

        routingOptions.value = data

    } catch (err) {
        throw new Error(
            err.message ||
            'Unable to load routing information.'
        )
    }
}

/*
|--------------------------------------------------------------------------
| Fetch Movement History
|--------------------------------------------------------------------------
*/

const fetchHistory = async () => {
    try {
        const response = await fetch(
            `/api/documents/${route.params.id}/history`,
            {
                headers: {
                    Accept: 'application/json',
                    Authorization: `Bearer ${getToken()}`,
                },
            }
        )

        const data = await response.json()

        if (!response.ok) {
            throw new Error(
                data.message ||
                'Unable to load document history.'
            )
        }

        history.value =
            Array.isArray(data)
                ? data
                : []

    } catch (err) {
        throw new Error(
            err.message ||
            'Unable to load document history.'
        )
    }
}

/*
|--------------------------------------------------------------------------
| Load Page
|--------------------------------------------------------------------------
*/

const loadPage = async () => {
    loading.value = true
    error.value = ''

    try {
        await Promise.all([
            fetchDocument(),
            fetchRoutingOptions(),
            fetchHistory(),
            fetchAttachments(),
            fetchProcessing(),
        ])

    } catch (err) {
        error.value =
            err.message ||
            'Unable to load document.'

    } finally {
        loading.value = false
    }
}

/*
|--------------------------------------------------------------------------
| Pending Route
|--------------------------------------------------------------------------
*/

const pendingRoute = computed(() => {
    if (!history.value.length) {
        return null
    }

    return (
        [...history.value]
            .reverse()
            .find(
                item =>
                    !item.received_at
            ) || null
    )
})

/*
|--------------------------------------------------------------------------
| Permissions
|--------------------------------------------------------------------------
*/

const canReceive = computed(() => {
    if (
        !pendingRoute.value ||
        !routingOptions.value?.user
    ) {
        return false
    }

    return (
        Number(
            pendingRoute.value.to_office_id
        ) ===
        Number(
            routingOptions.value.user.office_id
        )
    )
})

const canForward = computed(() => {
    if (
        !routingOptions.value?.can_act
    ) {
        return false
    }

    /*
    |--------------------------------------------------------------------------
    | Cannot forward again until current route is received.
    |--------------------------------------------------------------------------
    */

    return pendingRoute.value === null
})

/*
|--------------------------------------------------------------------------
| Forward Modal
|--------------------------------------------------------------------------
*/

const openForwardModal = () => {
    forwardForm.value = {
        to_office_id: '',
        remarks: '',
    }

    forwardError.value = ''
    successMessage.value = ''

    showForwardModal.value = true
}

const closeForwardModal = () => {
    if (actionLoading.value) {
        return
    }

    showForwardModal.value = false
    forwardError.value = ''
}

/*
|--------------------------------------------------------------------------
| Forward Document
|--------------------------------------------------------------------------
*/

const forwardDocument = async () => {
    forwardError.value = ''
    successMessage.value = ''

    if (
        !forwardForm.value.to_office_id
    ) {
        forwardError.value =
            'Please select a destination office.'

        return
    }

    actionLoading.value = true

    try {
        const response = await fetch(
            `/api/documents/${route.params.id}/forward`,
            {
                method: 'POST',

                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    Authorization: `Bearer ${getToken()}`,
                },

                body: JSON.stringify({
                    to_office_id:
                        Number(
                            forwardForm.value
                                .to_office_id
                        ),

                    remarks:
                        forwardForm.value
                            .remarks
                            .trim() ||
                        null,
                }),
            }
        )

        const data = await response.json()

        if (!response.ok) {
            if (data.errors) {
                const firstError =
                    Object.values(
                        data.errors
                    )[0]

                throw new Error(
                    Array.isArray(firstError)
                        ? firstError[0]
                        : firstError
                )
            }

            throw new Error(
                data.message ||
                'Unable to forward document.'
            )
        }

        showForwardModal.value = false

        successMessage.value =
            'Document forwarded successfully.'

        await loadPage()

    } catch (err) {
        forwardError.value =
            err.message ||
            'Unable to forward document.'

    } finally {
        actionLoading.value = false
    }
}

/*
|--------------------------------------------------------------------------
| Receive Modal
|--------------------------------------------------------------------------
*/

const openReceiveModal = () => {
    receiveError.value = ''
    successMessage.value = ''

    showReceiveModal.value = true
}

const closeReceiveModal = () => {
    if (actionLoading.value) {
        return
    }

    showReceiveModal.value = false
    receiveError.value = ''
}

/*
|--------------------------------------------------------------------------
| Receive Document
|--------------------------------------------------------------------------
*/

const receiveDocument = async () => {
    receiveError.value = ''
    successMessage.value = ''

    actionLoading.value = true

    try {
        const response = await fetch(
            `/api/documents/${route.params.id}/receive`,
            {
                method: 'POST',

                headers: {
                    Accept: 'application/json',
                    Authorization: `Bearer ${getToken()}`,
                },
            }
        )

        const data = await response.json()

        if (!response.ok) {
            throw new Error(
                data.message ||
                'Unable to receive document.'
            )
        }

        showReceiveModal.value = false

        successMessage.value =
            'Document received successfully.'

        await loadPage()

    } catch (err) {
        receiveError.value =
            err.message ||
            'Unable to receive document.'

    } finally {
        actionLoading.value = false
    }
}

/*
|--------------------------------------------------------------------------
| Navigation
|--------------------------------------------------------------------------
*/

const goBack = () => {
    router.push('/documents')
}

/*
|--------------------------------------------------------------------------
| Formatting
|--------------------------------------------------------------------------
*/

const formatDate = (date) => {
    if (!date) {
        return 'N/A'
    }

    return new Date(
        date
    ).toLocaleString()
}

const formatSimpleDate = (date) => {
    if (!date) {
        return 'N/A'
    }

    return new Date(
        `${date}T00:00:00`
    ).toLocaleDateString()
}

/*
|--------------------------------------------------------------------------
| Initialize
|--------------------------------------------------------------------------
*/

onMounted(() => {
    loadPage()
})
</script>

<template>
    <div class="min-h-screen bg-gray-100">

        <!-- Header -->
        <div class="bg-white border-b px-6 py-4">

            <div
                class="max-w-6xl mx-auto flex items-center justify-between"
            >

                <div>

                    <h1
                        class="text-2xl font-bold text-gray-800"
                    >
                        Document Details
                    </h1>

                    <p
                        class="text-sm text-gray-500 mt-1"
                    >
                        Document information, QR code, attachments,
                        routing and movement history
                    </p>

                </div>

                <Button
                    variant="outline"
                    @click="goBack"
                >
                    ← Back to Documents
                </Button>

            </div>

        </div>

        <!-- Main Content -->
        <div class="max-w-6xl mx-auto p-6">

            <!-- Loading -->
            <div
                v-if="loading"
                class="py-12 text-center text-gray-500"
            >
                Loading document...
            </div>

            <!-- Error -->
            <div
                v-else-if="error"
                class="rounded-md border border-red-200 bg-red-50 p-4 text-red-700"
            >
                {{ error }}
            </div>

            <template v-else-if="document">

                <!-- Success -->
                <div
                    v-if="successMessage"
                    class="mb-5 rounded-md border border-green-200 bg-green-50 p-4 text-sm font-semibold text-green-700"
                >
                    {{ successMessage }}
                </div>

                <!-- Document Information -->
                <Card>

                    <CardHeader>

                        <div
                            class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between"
                        >

                            <div>

                                <CardTitle
                                    class="text-2xl"
                                >
                                    {{ document.title }}
                                </CardTitle>

                                <p
                                    class="mt-1 text-sm font-medium text-gray-500"
                                >
                                    {{ document.tracking_no }}
                                </p>

                            </div>

                            <!-- Actions -->
                            <div
                                class="flex flex-wrap gap-2"
                            >

                                <Button
                                    v-if="canReceive"
                                    class="bg-green-600 text-white hover:bg-green-700"
                                    @click="openReceiveModal"
                                >
                                    Receive Document
                                </Button>

                                <Button
                                    v-if="canForward"
                                    class="bg-blue-600 text-white hover:bg-blue-700"
                                    @click="openForwardModal"
                                >
                                    Forward Document
                                </Button>

                            </div>

                        </div>

                    </CardHeader>

                    <CardContent>

                        <div
                            class="grid grid-cols-1 gap-8 lg:grid-cols-4"
                        >

                            <!-- Document Metadata -->
                            <div
                                class="lg:col-span-3"
                            >

                                <div
                                    class="grid grid-cols-1 gap-5 md:grid-cols-2 lg:grid-cols-3"
                                >

                                    <div>
                                        <p
                                            class="text-xs font-semibold uppercase text-gray-500"
                                        >
                                            Document Type
                                        </p>

                                        <p
                                            class="mt-1 font-medium text-gray-900"
                                        >
                                            {{
                                                document.type
                                                    ?.type_name
                                                || 'N/A'
                                            }}
                                        </p>
                                    </div>

                                    <div>
                                        <p
                                            class="text-xs font-semibold uppercase text-gray-500"
                                        >
                                            Status
                                        </p>

                                        <p
                                            class="mt-1 font-semibold text-gray-900"
                                        >
                                            {{
                                                document.status
                                                    ?.status_name
                                                || 'N/A'
                                            }}
                                        </p>
                                    </div>

                                    <div>
                                        <p
                                            class="text-xs font-semibold uppercase text-gray-500"
                                        >
                                            Priority
                                        </p>

                                        <p
                                            class="mt-1 font-medium text-gray-900"
                                        >
                                            {{
                                                document.priority
                                                    ?.priority_name
                                                || 'N/A'
                                            }}
                                        </p>
                                    </div>

                                    <div>
                                        <p
                                            class="text-xs font-semibold uppercase text-gray-500"
                                        >
                                            Confidentiality
                                        </p>

                                        <p
                                            class="mt-1 font-medium text-gray-900"
                                        >
                                            {{
                                                document.confidentiality
                                                    ?.level_name
                                                || 'N/A'
                                            }}
                                        </p>
                                    </div>

                                    <div>
                                        <p
                                            class="text-xs font-semibold uppercase text-gray-500"
                                        >
                                            Origin Office
                                        </p>

                                        <p
                                            class="mt-1 font-medium text-gray-900"
                                        >
                                            {{
                                                document.origin_office
                                                    ?.office_name
                                                || 'N/A'
                                            }}
                                        </p>
                                    </div>

                                    <div>
                                        <p
                                            class="text-xs font-semibold uppercase text-gray-500"
                                        >
                                            Current Office
                                        </p>

                                        <p
                                            class="mt-1 font-semibold text-blue-700"
                                        >
                                            {{
                                                document.current_office
                                                    ?.office_name
                                                || 'N/A'
                                            }}
                                        </p>
                                    </div>

                                    <div>
                                        <p
                                            class="text-xs font-semibold uppercase text-gray-500"
                                        >
                                            Document Date
                                        </p>

                                        <p
                                            class="mt-1 text-gray-900"
                                        >
                                            {{
                                                formatSimpleDate(
                                                    document.document_date
                                                )
                                            }}
                                        </p>
                                    </div>

                                    <div>
                                        <p
                                            class="text-xs font-semibold uppercase text-gray-500"
                                        >
                                            Due Date
                                        </p>

                                        <p
                                            class="mt-1 text-gray-900"
                                        >
                                            {{
                                                formatSimpleDate(
                                                    document.due_date
                                                )
                                            }}
                                        </p>
                                    </div>

                                    <div>
                                        <p
                                            class="text-xs font-semibold uppercase text-gray-500"
                                        >
                                            Registered By
                                        </p>

                                        <p
                                            class="mt-1 text-gray-900"
                                        >
                                            {{
                                                document.creator
                                                    ?.name
                                                || 'N/A'
                                            }}
                                        </p>
                                    </div>

                                </div>

                                <!-- Description -->
                                <div
                                    class="mt-7 border-t pt-5"
                                >

                                    <p
                                        class="text-xs font-semibold uppercase text-gray-500"
                                    >
                                        Document Details
                                    </p>

                                    <p
                                        class="mt-2 whitespace-pre-line text-gray-800"
                                    >
                                        {{
                                            document.description
                                            || 'No description provided.'
                                        }}
                                    </p>

                                </div>

                            </div>

                            <!-- Canonical Issued QR Card -->
                            <div
                                class="rounded-xl border bg-gray-50 p-5 text-center"
                            >

                                <h3
                                    class="font-bold text-gray-900"
                                >
                                    Issued Document QR
                                </h3>

                                <p
                                    class="mt-1 text-xs text-gray-500"
                                >
                                    Permanent QR assigned to this physical document
                                </p>

                                <div
                                    v-if="qrDataUrl && document.qr_code?.qr_token"
                                    class="mt-4"
                                >

                                    <img
                                        :src="qrDataUrl"
                                        alt="Issued Document QR Code"
                                        class="mx-auto h-48 w-48 rounded-md bg-white p-2"
                                    >

                                    <p
                                        class="mt-3 font-mono text-sm font-bold text-gray-800"
                                    >
                                        {{ document.qr_code.qr_token }}
                                    </p>

                                    <p
                                        class="mt-1 break-all text-xs text-gray-500"
                                    >
                                        {{ document.tracking_no }}
                                    </p>

                                    <div
                                        class="mt-3 rounded-md border border-green-200 bg-green-50 px-3 py-2 text-xs font-semibold text-green-700"
                                    >
                                        Registered QR • Linked to this document
                                    </div>

                                    <Button
                                        class="mt-4 w-full bg-gray-900 text-white hover:bg-black"
                                        @click="printQRCode"
                                    >
                                        Print Linked QR
                                    </Button>

                                </div>

                                <div
                                    v-else-if="qrError"
                                    class="mt-4 text-sm text-red-600"
                                >
                                    {{ qrError }}
                                </div>

                                <div
                                    v-else
                                    class="mt-4 rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-500"
                                >
                                    No issued QR is linked to this document.
                                    This may be a legacy or manually registered record.
                                </div>

                            </div>

                        </div>

                    </CardContent>

                </Card>

                <!-- Current Processing -->
                <Card class="mt-6">

                    <CardHeader>

                        <div
                            class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between"
                        >

                            <div>

                                <CardTitle>
                                    Current Processing
                                </CardTitle>

                                <p
                                    class="mt-1 text-sm text-gray-500"
                                >
                                    Shows what the office currently holding the document is doing with it.
                                </p>

                            </div>

                            <div
                                v-if="processingInfo?.current_action"
                                class="inline-flex self-start rounded-full border border-blue-200 bg-blue-50 px-3 py-1 text-sm font-semibold text-blue-700"
                            >
                                {{
                                    processingInfo
                                        .current_action
                                        .action_name
                                }}
                            </div>

                        </div>

                    </CardHeader>

                    <CardContent>

                        <div
                            v-if="processingLoading"
                            class="py-6 text-center text-sm text-gray-500"
                        >
                            Loading current processing...
                        </div>

                        <div v-else class="space-y-5">

                            <!-- Current Summary -->
                            <div
                                class="grid grid-cols-1 gap-4 md:grid-cols-3"
                            >

                                <div
                                    class="rounded-lg border bg-gray-50 p-4"
                                >
                                    <p
                                        class="text-xs font-semibold uppercase text-gray-500"
                                    >
                                        Current Office
                                    </p>

                                    <p
                                        class="mt-1 font-semibold text-gray-900"
                                    >
                                        {{
                                            processingInfo
                                                ?.current_office
                                                ?.office_name
                                            || document
                                                .current_office
                                                ?.office_name
                                            || 'N/A'
                                        }}
                                    </p>
                                </div>

                                <div
                                    class="rounded-lg border bg-gray-50 p-4"
                                >
                                    <p
                                        class="text-xs font-semibold uppercase text-gray-500"
                                    >
                                        Current Action
                                    </p>

                                    <p
                                        class="mt-1 font-semibold text-blue-700"
                                    >
                                        {{
                                            processingInfo
                                                ?.current_action
                                                ?.action_name
                                            || 'Not set yet'
                                        }}
                                    </p>
                                </div>

                                <div
                                    class="rounded-lg border bg-gray-50 p-4"
                                >
                                    <p
                                        class="text-xs font-semibold uppercase text-gray-500"
                                    >
                                        Last Updated
                                    </p>

                                    <p
                                        class="mt-1 text-sm font-medium text-gray-900"
                                    >
                                        {{
                                            processingInfo
                                                ?.current_action_updated_at
                                                ? formatDate(
                                                    processingInfo
                                                        .current_action_updated_at
                                                )
                                                : 'N/A'
                                        }}
                                    </p>

                                    <p
                                        v-if="processingInfo?.current_action_updated_by"
                                        class="mt-1 text-xs text-gray-500"
                                    >
                                        By
                                        {{
                                            processingInfo
                                                .current_action_updated_by
                                                .name
                                        }}
                                    </p>
                                </div>

                            </div>

                            <!-- Existing Internal Note -->
                            <div
                                v-if="processingInfo?.processing_note"
                                class="rounded-lg border border-amber-200 bg-amber-50 p-4"
                            >
                                <p
                                    class="text-xs font-semibold uppercase text-amber-700"
                                >
                                    Internal Processing Note
                                </p>

                                <p
                                    class="mt-2 whitespace-pre-line text-sm text-amber-900"
                                >
                                    {{
                                        processingInfo
                                            .processing_note
                                    }}
                                </p>
                            </div>

                            <!-- Permission / Restriction -->
                            <div
                                v-if="processingInfo && !canUpdateProcessing"
                                class="rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600"
                            >
                                {{
                                    processingInfo
                                        .restriction_reason
                                    || 'You cannot update the processing action for this document.'
                                }}
                            </div>

                            <!-- Processing Form -->
                            <form
                                v-if="canUpdateProcessing"
                                class="rounded-xl border bg-white p-5"
                                @submit.prevent="saveProcessing"
                            >

                                <div
                                    class="grid grid-cols-1 gap-5 md:grid-cols-2"
                                >

                                    <div>

                                        <label
                                            class="mb-2 block text-sm font-semibold text-gray-700"
                                        >
                                            Current Action
                                        </label>

                                        <select
                                            v-model="processingForm.current_action_id"
                                            class="h-11 w-full rounded-md border border-gray-300 bg-white px-3 text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
                                            :disabled="processingSaving"
                                        >
                                            <option value="">
                                                Select current action
                                            </option>

                                            <option
                                                v-for="action in processingInfo?.available_actions || []"
                                                :key="action.id"
                                                :value="String(action.id)"
                                            >
                                                {{ action.action_name }}
                                            </option>
                                        </select>

                                        <p
                                            class="mt-2 text-xs text-gray-500"
                                        >
                                            Choose the actual work currently being performed on the document.
                                        </p>

                                    </div>

                                    <div>

                                        <label
                                            class="mb-2 block text-sm font-semibold text-gray-700"
                                        >
                                            Processing Note
                                            <span
                                                class="font-normal text-gray-400"
                                            >
                                                (Internal)
                                            </span>
                                        </label>

                                        <textarea
                                            v-model="processingForm.processing_note"
                                            rows="4"
                                            maxlength="2000"
                                            placeholder="Example: For signature of the Municipal Mayor."
                                            class="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
                                            :disabled="processingSaving"
                                        ></textarea>

                                        <p
                                            class="mt-1 text-right text-xs text-gray-400"
                                        >
                                            {{
                                                processingForm
                                                    .processing_note
                                                    .length
                                            }}/2000
                                        </p>

                                    </div>

                                </div>

                                <div
                                    v-if="processingError"
                                    class="mt-4 rounded-md border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-700"
                                >
                                    {{ processingError }}
                                </div>

                                <div
                                    class="mt-5 flex justify-end"
                                >
                                    <Button
                                        type="submit"
                                        :disabled="processingSaving"
                                        class="bg-blue-600 text-white hover:bg-blue-700"
                                    >
                                        {{
                                            processingSaving
                                                ? 'Saving...'
                                                : 'Save Current Processing'
                                        }}
                                    </Button>
                                </div>

                            </form>

                            <div
                                v-else-if="processingError"
                                class="rounded-md border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-700"
                            >
                                {{ processingError }}
                            </div>

                        </div>

                    </CardContent>

                </Card>

                <!-- Consolidated Document History -->
                <Card class="mt-6">

                    <CardHeader>

                        <div
                            class="flex flex-col gap-1 md:flex-row md:items-center md:justify-between"
                        >

                            <div>

                                <CardTitle>
                                    Document History
                                </CardTitle>

                                <p
                                    class="mt-1 text-sm text-gray-500"
                                >
                                    Consolidated chronological view of processing actions and document movements.
                                </p>

                            </div>

                            <span
                                class="inline-flex self-start rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600"
                            >
                                {{ historyRows.length }}
                                records
                            </span>

                        </div>

                    </CardHeader>

                    <CardContent>

                        <div
                            v-if="historyRows.length === 0"
                            class="rounded-lg border border-dashed bg-gray-50 px-5 py-8 text-center text-sm text-gray-500"
                        >
                            No document history has been recorded yet.
                        </div>

                        <div
                            v-else
                            class="overflow-x-auto rounded-lg border"
                        >

                            <table
                                class="w-full min-w-[1100px] border-collapse text-left text-sm"
                            >

                                <thead
                                    class="bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-600"
                                >

                                    <tr>

                                        <th
                                            class="border-b px-4 py-3"
                                        >
                                            Status
                                        </th>

                                        <th
                                            class="border-b px-4 py-3"
                                        >
                                            Action
                                        </th>

                                        <th
                                            class="border-b px-4 py-3"
                                        >
                                            From Office
                                        </th>

                                        <th
                                            class="border-b px-4 py-3"
                                        >
                                            Action Taken / To Office
                                        </th>

                                        <th
                                            class="border-b px-4 py-3"
                                        >
                                            Date
                                        </th>

                                        <th
                                            class="border-b px-4 py-3"
                                        >
                                            By / Office
                                        </th>

                                    </tr>

                                </thead>

                                <tbody
                                    class="divide-y bg-white"
                                >

                                    <tr
                                        v-for="row in historyRows"
                                        :key="row.key"
                                        class="align-top hover:bg-gray-50"
                                    >

                                        <td
                                            class="whitespace-nowrap px-4 py-4"
                                        >

                                            <span
                                                :class="[
                                                    'inline-flex rounded-full px-2.5 py-1 text-xs font-semibold',
                                                    row.status === 'Received'
                                                        ? 'bg-green-50 text-green-700 ring-1 ring-inset ring-green-200'
                                                        : row.status === 'Forwarded'
                                                            ? 'bg-blue-50 text-blue-700 ring-1 ring-inset ring-blue-200'
                                                            : row.status === 'Registered'
                                                                ? 'bg-purple-50 text-purple-700 ring-1 ring-inset ring-purple-200'
                                                                : 'bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-200',
                                                ]"
                                            >
                                                {{ row.status }}
                                            </span>

                                        </td>

                                        <td
                                            class="px-4 py-4 font-medium text-gray-900"
                                        >
                                            {{ row.action }}
                                        </td>

                                        <td
                                            class="px-4 py-4 text-gray-700"
                                        >
                                            {{ row.from_office }}
                                        </td>

                                        <td
                                            class="px-4 py-4"
                                        >

                                            <p
                                                class="font-semibold text-gray-900"
                                            >
                                                {{ row.action_taken }}
                                            </p>

                                            <p
                                                v-if="row.detail"
                                                class="mt-1 max-w-md whitespace-pre-line text-xs leading-5 text-gray-500"
                                            >
                                                {{ row.detail }}
                                            </p>

                                        </td>

                                        <td
                                            class="whitespace-nowrap px-4 py-4 text-gray-600"
                                        >
                                            {{ formatDate(row.date) }}
                                        </td>

                                        <td
                                            class="px-4 py-4"
                                        >

                                            <p
                                                class="font-medium text-gray-900"
                                            >
                                                {{ row.by_name }}
                                            </p>

                                            <p
                                                class="mt-1 text-xs text-gray-500"
                                            >
                                                {{ row.by_office }}
                                            </p>

                                        </td>

                                    </tr>

                                </tbody>

                            </table>

                        </div>

                        <p
                            class="mt-3 text-xs text-gray-500"
                        >
                            Older routes created before Processing History was introduced are included from Movement History automatically.
                        </p>

                    </CardContent>

                </Card>

                <!-- Attachments -->
                <Card class="mt-6">

                    <CardHeader>

                        <div
                            class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between"
                        >

                            <div>
                                <CardTitle>
                                    Attachments
                                </CardTitle>

                                <p
                                    class="mt-1 text-sm text-gray-500"
                                >
                                    Supporting files associated with this document.
                                </p>
                            </div>

                            <span
                                class="w-fit rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600"
                            >
                                {{ attachments.length }}
                                {{
                                    attachments.length === 1
                                        ? 'file'
                                        : 'files'
                                }}
                            </span>

                        </div>

                    </CardHeader>

                    <CardContent>

                        <div
                            v-if="canManageAttachments"
                            class="rounded-lg border bg-gray-50 p-4"
                        >

                            <div
                                class="flex flex-col gap-4 lg:flex-row lg:items-end"
                            >

                                <div class="flex-1">

                                    <label
                                        class="mb-2 block text-sm font-semibold text-gray-700"
                                    >
                                        Upload Attachment
                                    </label>

                                    <!-- Hidden file picker -->
                                    <input
                                        ref="attachmentInput"
                                        type="file"
                                        multiple
                                        accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png"
                                        :disabled="uploadingAttachment"
                                        class="hidden"
                                        @change="handleFileChange"
                                    >

                                    <div
                                        class="flex flex-wrap gap-2"
                                    >

                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-50"
                                            :disabled="uploadingAttachment"
                                            @click="openAttachmentPicker"
                                        >
                                            <span
                                                class="text-lg leading-none"
                                            >
                                                +
                                            </span>

                                            {{
                                                selectedFiles.length === 0
                                                    ? 'Add Attachment'
                                                    : 'Add More'
                                            }}
                                        </button>

                                        <Button
                                            class="bg-blue-600 text-white hover:bg-blue-700"
                                            :disabled="
                                                uploadingAttachment ||
                                                selectedFiles.length === 0
                                            "
                                            @click="uploadAttachments"
                                        >
                                            {{
                                                uploadingAttachment
                                                    ? 'Uploading...'
                                                    : (
                                                        selectedFiles.length === 1
                                                            ? 'Upload Attachment'
                                                            : `Upload ${selectedFiles.length} Attachments`
                                                    )
                                            }}
                                        </Button>

                                    </div>

                                    <p
                                        class="mt-2 text-xs text-gray-500"
                                    >
                                        PDF, DOC, DOCX, XLS, XLSX, JPG, JPEG or PNG. Maximum 10 MB per file.
                                    </p>

                                </div>

                            </div>

                            <!-- Pending Files -->
                            <div
                                v-if="selectedFiles.length > 0"
                                class="mt-4 space-y-2"
                            >

                                <p
                                    class="text-xs font-semibold uppercase tracking-wide text-gray-500"
                                >
                                    Files to upload
                                </p>

                                <div
                                    v-for="(file, index) in selectedFiles"
                                    :key="`${file.name}-${file.size}-${file.lastModified}`"
                                    class="flex items-center justify-between gap-3 rounded-md border bg-white px-3 py-2"
                                >

                                    <div class="min-w-0">

                                        <p
                                            class="truncate text-sm font-semibold text-gray-800"
                                        >
                                            {{ file.name }}
                                        </p>

                                        <p
                                            class="text-xs text-gray-500"
                                        >
                                            {{ formatFileSize(file.size) }}
                                        </p>

                                    </div>

                                    <button
                                        type="button"
                                        title="Remove attachment"
                                        aria-label="Remove attachment"
                                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-gray-300 bg-white text-lg font-bold leading-none text-gray-600 hover:border-red-300 hover:bg-red-50 hover:text-red-600 disabled:cursor-not-allowed disabled:opacity-50"
                                        :disabled="uploadingAttachment"
                                        @click="removeSelectedFile(index)"
                                    >
                                        ×
                                    </button>

                                </div>

                            </div>

                        </div>

                        <div
                            v-else
                            class="rounded-lg border border-blue-100 bg-blue-50 p-4 text-sm text-blue-700"
                        >
                            Only the office currently holding this document can upload or delete attachments.
                        </div>

                        <div
                            v-if="attachmentError"
                            class="mt-4 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-600"
                        >
                            {{ attachmentError }}
                        </div>

                        <div
                            v-if="attachmentsLoading"
                            class="py-8 text-center text-gray-500"
                        >
                            Loading attachments...
                        </div>

                        <div
                            v-else-if="attachments.length === 0"
                            class="py-8 text-center text-gray-500"
                        >
                            No attachments have been uploaded.
                        </div>

                        <div
                            v-else
                            class="mt-5 space-y-3"
                        >

                            <div
                                v-for="attachment in attachments"
                                :key="attachment.id"
                                class="flex flex-col gap-4 rounded-lg border bg-white p-4 md:flex-row md:items-center md:justify-between"
                            >

                                <div class="min-w-0">

                                    <p
                                        class="break-all font-semibold text-gray-900"
                                    >
                                        {{ attachment.original_filename }}
                                    </p>

                                    <div
                                        class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500"
                                    >
                                        <span>
                                            {{ formatFileSize(attachment.file_size) }}
                                        </span>

                                        <span>
                                            {{ attachment.mime_type || 'Unknown type' }}
                                        </span>

                                        <span>
                                            Uploaded by
                                            {{
                                                attachment.uploaded_by
                                                    ?.name
                                                || 'Unknown user'
                                            }}
                                        </span>

                                        <span>
                                            {{ formatDate(attachment.created_at) }}
                                        </span>
                                    </div>

                                </div>

                                <div class="flex shrink-0 gap-2">

                                    <Button
                                        v-if="canAccessAttachments"
                                        variant="outline"
                                        size="sm"
                                        @click="downloadAttachment(attachment)"
                                    >
                                        Download
                                    </Button>

                                    <Button
                                        v-if="canManageAttachments"
                                        variant="destructive"
                                        size="sm"
                                        @click="openDeleteAttachmentModal(attachment)"
                                    >
                                        Delete
                                    </Button>

                                </div>

                            </div>

                        </div>

                    </CardContent>

                </Card>

            </template>

        </div>

        <!-- Forward Modal -->
        <div
            v-if="showForwardModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4"
        >

            <div
                class="w-full max-w-lg rounded-xl bg-white p-6 shadow-2xl"
            >

                <h2
                    class="text-xl font-bold text-gray-900"
                >
                    Forward Document
                </h2>

                <p
                    class="mt-1 text-sm text-gray-500"
                >
                    Select the office that will receive this document.
                </p>

                <!-- Destination -->
                <div class="mt-5">

                    <label
                        class="mb-2 block text-sm font-semibold text-gray-700"
                    >
                        Destination Office *
                    </label>

                    <select
                        v-model="forwardForm.to_office_id"
                        class="h-11 w-full rounded-md border border-gray-300 bg-white px-3 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                    >

                        <option value="">
                            Select Destination Office
                        </option>

                        <option
                            v-for="office in routingOptions?.offices || []"
                            :key="office.id"
                            :value="office.id"
                        >
                            {{ office.office_name }}
                            ({{ office.office_code }})
                        </option>

                    </select>

                </div>

                <!-- Remarks -->
                <div class="mt-4">

                    <label
                        class="mb-2 block text-sm font-semibold text-gray-700"
                    >
                        Remarks
                    </label>

                    <textarea
                        v-model="forwardForm.remarks"
                        rows="4"
                        placeholder="Optional routing remarks"
                        class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                    ></textarea>

                </div>

                <!-- Error -->
                <div
                    v-if="forwardError"
                    class="mt-4 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-600"
                >
                    {{ forwardError }}
                </div>

                <!-- Buttons -->
                <div
                    class="mt-6 flex justify-end gap-3"
                >

                    <button
                        type="button"
                        class="rounded-md bg-black px-5 py-2 text-sm font-semibold text-white hover:bg-gray-800 disabled:opacity-50"
                        :disabled="actionLoading"
                        @click="closeForwardModal"
                    >
                        Cancel
                    </button>

                    <button
                        type="button"
                        class="rounded-md bg-blue-600 px-5 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50"
                        :disabled="actionLoading"
                        @click="forwardDocument"
                    >
                        {{
                            actionLoading
                                ? 'Forwarding...'
                                : 'Forward'
                        }}
                    </button>

                </div>

            </div>

        </div>

        <!-- Receive Modal -->
        <div
            v-if="showReceiveModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4"
        >

            <div
                class="w-full max-w-md rounded-xl bg-white p-6 shadow-2xl"
            >

                <h2
                    class="text-xl font-bold text-gray-900"
                >
                    Receive Document
                </h2>

                <p
                    class="mt-3 text-sm text-gray-600"
                >
                    Confirm that your office has physically received this document.
                </p>

                <div
                    v-if="pendingRoute"
                    class="mt-4 rounded-lg bg-gray-50 p-4 text-sm"
                >

                    <p>

                        <span class="font-semibold">
                            From:
                        </span>

                        {{
                            pendingRoute.from_office
                                ?.office_name
                            || 'N/A'
                        }}

                    </p>

                    <p class="mt-1">

                        <span class="font-semibold">
                            To:
                        </span>

                        {{
                            pendingRoute.to_office
                                ?.office_name
                            || 'N/A'
                        }}

                    </p>

                </div>

                <div
                    v-if="receiveError"
                    class="mt-4 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-600"
                >
                    {{ receiveError }}
                </div>

                <div
                    class="mt-6 flex justify-end gap-3"
                >

                    <button
                        type="button"
                        class="rounded-md bg-black px-5 py-2 text-sm font-semibold text-white hover:bg-gray-800 disabled:opacity-50"
                        :disabled="actionLoading"
                        @click="closeReceiveModal"
                    >
                        Cancel
                    </button>

                    <button
                        type="button"
                        class="rounded-md bg-green-600 px-5 py-2 text-sm font-semibold text-white hover:bg-green-700 disabled:opacity-50"
                        :disabled="actionLoading"
                        @click="receiveDocument"
                    >
                        {{
                            actionLoading
                                ? 'Receiving...'
                                : 'Yes, Receive'
                        }}
                    </button>

                </div>

            </div>

        </div>

        <!-- Delete Attachment Confirmation Modal -->
        <div
            v-if="showDeleteAttachmentModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4"
        >

            <div
                class="w-full max-w-md rounded-xl bg-white p-6 shadow-2xl"
            >

                <h2
                    class="text-xl font-bold text-gray-900"
                >
                    Delete Attachment
                </h2>

                <p
                    class="mt-3 text-sm text-gray-600"
                >
                    Are you sure you want to delete
                    <span class="font-semibold text-gray-900">
                        "{{ attachmentToDelete?.original_filename }}"
                    </span>?
                </p>

                <p
                    class="mt-2 text-sm text-gray-500"
                >
                    This will remove the stored file and cannot be undone.
                </p>

                <div
                    class="mt-6 flex justify-end gap-3"
                >

                    <button
                        type="button"
                        class="rounded-md bg-black px-5 py-2 text-sm font-semibold text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="deletingAttachment"
                        @click="closeDeleteAttachmentModal"
                    >
                        Cancel
                    </button>

                    <button
                        type="button"
                        class="rounded-md bg-red-600 px-5 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="deletingAttachment"
                        @click="deleteAttachment"
                    >
                        {{
                            deletingAttachment
                                ? 'Deleting...'
                                : 'Yes, Delete'
                        }}
                    </button>

                </div>

            </div>

        </div>

    </div>
</template>