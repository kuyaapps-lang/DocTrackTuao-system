<script setup>
import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card'

import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table'

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { can } from '@/lib/auth'
import {
    buildDocumentListQuery,
    DOCUMENT_SEARCH_MAX_LENGTH,
    filterDocuments,
    isValidDocumentListPayload,
    parseDocumentListQuery,
} from '@/lib/document-list'

const route = useRoute()
const router = useRouter()

/*
|--------------------------------------------------------------------------
| Document List
|--------------------------------------------------------------------------
*/

const initialQuery = parseDocumentListQuery(route.query)
const documents = ref([])
const loading = ref(true)
const error = ref('')
const activeTab = ref(initialQuery.view)
const searchTerm = ref(initialQuery.search)
const incomingState = ref(initialQuery.incomingState)

let activeRequestController = null
let componentUnmounted = false
let pageMounted = false
let requestSequence = 0

const tabs = [
    {
        key: 'all',
        label: 'All Documents',
    },
    {
        key: 'incoming',
        label: 'Incoming',
    },
    {
        key: 'outgoing',
        label: 'Outgoing',
    },
]

const filteredDocuments = computed(() => {
    return filterDocuments(documents.value, {
        view: activeTab.value,
        search: searchTerm.value,
        incomingState: incomingState.value,
    })
})

/*
|--------------------------------------------------------------------------
| Form Options
|--------------------------------------------------------------------------
*/

const documentTypes = ref([])
const priorities = ref([])
const confidentialityLevels = ref([])
const offices = ref([])

const optionsLoading = ref(false)

/*
|--------------------------------------------------------------------------
| Create Document
|--------------------------------------------------------------------------
*/

const showCreateForm = ref(false)
const creating = ref(false)
const createError = ref('')
const createSuccess = ref('')

/*
|--------------------------------------------------------------------------
| QR Registration Mode
|--------------------------------------------------------------------------
*/

const qrToken = ref('')
const qrResolving = ref(false)
const qrStateError = ref('')

const isQrRegistration = () => {
    return Boolean(qrToken.value)
}

const form = ref({
    title: '',
    description: '',
    document_type_id: '',
    priority_id: '',
    confidentiality_level_id: '',
    origin_office_id: '',
    document_date: '',
    due_date: '',
})

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

const getToken = () => {
    return localStorage.getItem('auth_token')
}

const canCreateDocuments = computed(() => {
    return can('documents.create')
})

/*
|--------------------------------------------------------------------------
| Document List API
|--------------------------------------------------------------------------
*/

const getDocumentEndpoint = (view) => {
    if (view === 'incoming') {
        return '/api/documents/incoming'
    }

    if (view === 'outgoing') {
        return '/api/documents/outgoing'
    }

    return '/api/documents'
}

const fetchDocuments = async (view = activeTab.value) => {
    if (componentUnmounted) {
        return
    }

    const requestId = ++requestSequence

    activeRequestController?.abort()
    const requestController = new AbortController()
    activeRequestController = requestController

    loading.value = true
    error.value = ''

    try {
        const response = await fetch(
            getDocumentEndpoint(view),
            {
                signal: requestController.signal,
                headers: {
                    Accept: 'application/json',
                    Authorization: `Bearer ${getToken()}`,
                },
            }
        )

        if (!response.ok) {
            throw new Error(
                response.status === 401
                    ? 'Your session has expired. Please sign in again.'
                    : 'Unable to load documents. Please try again.'
            )
        }

        const data = await response.json()

        if (
            componentUnmounted ||
            requestId !== requestSequence
        ) {
            return
        }

        if (!isValidDocumentListPayload(data)) {
            throw new Error()
        }

        documents.value = data

    } catch (err) {
        if (
            err?.name === 'AbortError' ||
            componentUnmounted ||
            requestId !== requestSequence
        ) {
            return
        }

        documents.value = []
        error.value = err?.message ===
            'Your session has expired. Please sign in again.'
            ? err.message
            : 'Unable to load documents. Please try again.'
    } finally {
        if (
            !componentUnmounted &&
            requestId === requestSequence
        ) {
            loading.value = false

            if (activeRequestController === requestController) {
                activeRequestController = null
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Change Document Tab
|--------------------------------------------------------------------------
*/

const currentListQuery = () => {
    return buildDocumentListQuery({
        view: activeTab.value,
        search: searchTerm.value,
        incomingState: incomingState.value,
    })
}

const queryMatches = (query, expected) => {
    const actualKeys = Object.keys(query)

    return (
        actualKeys.length === Object.keys(expected).length &&
        Object.entries(expected).every(([key, value]) => {
            return query[key] === value
        })
    )
}

const replaceListQuery = async () => {
    if (route.path !== '/documents') {
        return
    }

    const query = currentListQuery()

    if (!queryMatches(route.query, query)) {
        await router.replace({
            path: '/documents',
            query,
        })
    }
}

const changeTab = async (tab) => {
    if (activeTab.value === tab) {
        return
    }

    await router.push({
        path: '/documents',
        query: buildDocumentListQuery({
            view: tab,
            search: searchTerm.value,
            incomingState: tab === 'incoming'
                ? incomingState.value
                : 'all',
        }),
    })
}

/*
|--------------------------------------------------------------------------
| Resolve Scanned QR
|--------------------------------------------------------------------------
*/

const resolveQrForRegistration = async (token) => {
    qrResolving.value = true
    qrStateError.value = ''

    try {
        const response = await fetch(
            `/api/q/${encodeURIComponent(token)}`,
            {
                headers: {
                    Accept: 'application/json',
                },
            }
        )

        const data = await response.json()

        if (!response.ok) {
            throw new Error(
                data.message ||
                'Unable to verify this QR code.'
            )
        }

        /*
        |--------------------------------------------------------------------------
        | UNUSED = allow registration
        |--------------------------------------------------------------------------
        */

        if (data.state === 'unused') {
            qrToken.value = token

            await openCreateForm()

            return
        }

        /*
        |--------------------------------------------------------------------------
        | REGISTERED = redirect to tracking
        |--------------------------------------------------------------------------
        */

        if (
            data.state === 'registered' &&
            data.tracking_path
        ) {
            router.replace(
                data.tracking_path
            )

            return
        }

        throw new Error(
            data.message ||
            'This QR code cannot be used for document registration.'
        )

    } catch (err) {
        qrStateError.value =
            err.message ||
            'Unable to verify this QR code.'
    } finally {
        qrResolving.value = false
    }
}

/*
|--------------------------------------------------------------------------
| Form Options API
|--------------------------------------------------------------------------
*/

const fetchFormOptions = async () => {
    optionsLoading.value = true
    createError.value = ''

    try {
        const response = await fetch(
            '/api/document-form-options',
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
                'Unable to load document form options.'
            )
        }

        documentTypes.value =
            data.document_types || []

        priorities.value =
            data.priorities || []

        confidentialityLevels.value =
            data.confidentiality_levels || []

        offices.value =
            data.offices || []

    } catch (err) {
        createError.value =
            err.message ||
            'Unable to load document form options.'
    } finally {
        optionsLoading.value = false
    }
}

/*
|--------------------------------------------------------------------------
| Reset Registration Form
|--------------------------------------------------------------------------
*/

const resetForm = () => {
    form.value = {
        title: '',
        description: '',
        document_type_id: '',
        priority_id: '',
        confidentiality_level_id: '',
        origin_office_id: '',
        document_date:
            new Date().toISOString().slice(0, 10),
        due_date: '',
    }

    createError.value = ''
    createSuccess.value = ''
}

/*
|--------------------------------------------------------------------------
| Open Registration Form
|--------------------------------------------------------------------------
*/

const openCreateForm = async () => {
    if (!canCreateDocuments.value) {
        return
    }

    resetForm()

    showCreateForm.value = true

    if (
        documentTypes.value.length === 0 ||
        priorities.value.length === 0 ||
        confidentialityLevels.value.length === 0 ||
        offices.value.length === 0
    ) {
        await fetchFormOptions()
    }

    /*
    |--------------------------------------------------------------------------
    | Default Priority = Normal
    |--------------------------------------------------------------------------
    */

    const normalPriority =
        priorities.value.find(
            priority =>
                priority.priority_name === 'Normal'
        )

    if (normalPriority) {
        form.value.priority_id =
            normalPriority.id
    }

    /*
    |--------------------------------------------------------------------------
    | Default Confidentiality = Public
    |--------------------------------------------------------------------------
    */

    const publicLevel =
        confidentialityLevels.value.find(
            level =>
                level.level_name === 'Public'
        )

    if (publicLevel) {
        form.value.confidentiality_level_id =
            publicLevel.id
    }
}

/*
|--------------------------------------------------------------------------
| Close Registration Form
|--------------------------------------------------------------------------
*/

const closeCreateForm = () => {
    if (creating.value) {
        return
    }

    showCreateForm.value = false

    resetForm()

    if (qrToken.value) {
        qrToken.value = ''

        if (route.name === 'qr-document-registration') {
            router.replace('/documents')
        }
    }
}

/*
|--------------------------------------------------------------------------
| Register Document
|--------------------------------------------------------------------------
*/

const createDocument = async () => {
    if (!canCreateDocuments.value) {
        createError.value =
            'You do not have permission to register documents.'
        return
    }

    createError.value = ''
    createSuccess.value = ''

    if (!form.value.title.trim()) {
        createError.value =
            'Document title is required.'

        return
    }

    if (!form.value.document_type_id) {
        createError.value =
            'Document type is required.'

        return
    }

    if (!form.value.priority_id) {
        createError.value =
            'Priority is required.'

        return
    }

    if (!form.value.confidentiality_level_id) {
        createError.value =
            'Confidentiality level is required.'

        return
    }

    if (!form.value.origin_office_id) {
        createError.value =
            'Origin office is required.'

        return
    }

    if (!form.value.document_date) {
        createError.value =
            'Document date is required.'

        return
    }

    creating.value = true

    try {
        const response = await fetch(
            '/api/documents',
            {
                method: 'POST',

                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    Authorization: `Bearer ${getToken()}`,
                },

                body: JSON.stringify({
                    title:
                        form.value.title.trim(),

                    description:
                        form.value.description.trim() ||
                        null,

                    document_type_id:
                        Number(
                            form.value.document_type_id
                        ),

                    priority_id:
                        Number(
                            form.value.priority_id
                        ),

                    confidentiality_level_id:
                        Number(
                            form.value
                                .confidentiality_level_id
                        ),

                    origin_office_id:
                        Number(
                            form.value.origin_office_id
                        ),

                    document_date:
                        form.value.document_date,

                    due_date:
                        form.value.due_date ||
                        null,

                    qr_token:
                        qrToken.value ||
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
                'Unable to register document.'
            )
        }

        createSuccess.value =
            data.message ||
            'Document registered successfully.'

        /*
        |--------------------------------------------------------------------------
        | Refresh current tab
        |--------------------------------------------------------------------------
        */

        await fetchDocuments()

        /*
        |--------------------------------------------------------------------------
        | Open Newly Registered Document
        |--------------------------------------------------------------------------
        */

        if (data.document?.id) {
            setTimeout(() => {
                showCreateForm.value = false

                router.push(
                    `/documents/${data.document.id}`
                )
            }, 700)
        }

    } catch (err) {
        createError.value =
            err.message ||
            'Unable to register document.'
    } finally {
        creating.value = false
    }
}

/*
|--------------------------------------------------------------------------
| Relevant Route
|--------------------------------------------------------------------------
|
| Incoming/outgoing endpoints return the routes relevant to the user's
| office ordered newest first.
|
*/

const getRelevantRoute = (document) => {
    if (
        !document.routes ||
        document.routes.length === 0
    ) {
        return null
    }

    return document.routes[0]
}

/*
|--------------------------------------------------------------------------
| Incoming From Office
|--------------------------------------------------------------------------
*/

const getFromOffice = (document) => {
    const route =
        getRelevantRoute(document)

    return (
        route?.from_office?.office_name ||
        'N/A'
    )
}

/*
|--------------------------------------------------------------------------
| Outgoing To Office
|--------------------------------------------------------------------------
*/

const getToOffice = (document) => {
    const route =
        getRelevantRoute(document)

    return (
        route?.to_office?.office_name ||
        'N/A'
    )
}

/*
|--------------------------------------------------------------------------
| Route Status
|--------------------------------------------------------------------------
*/

const getRouteStatus = (document) => {
    const route =
        getRelevantRoute(document)

    if (!route) {
        return (
            document.status?.status_name ||
            'N/A'
        )
    }

    if (route.received_at) {
        return 'Received'
    }

    return 'Awaiting Receipt'
}

/*
|--------------------------------------------------------------------------
| Status Badge
|--------------------------------------------------------------------------
*/

const statusClass = (status) => {
    switch (
        String(status)
            .toLowerCase()
    ) {
        case 'received':
            return 'bg-blue-100 text-blue-700'

        case 'forwarded':
        case 'awaiting receipt':
            return 'bg-indigo-100 text-indigo-700'

        case 'pending':
            return 'bg-yellow-100 text-yellow-700'

        case 'approved':
            return 'bg-green-100 text-green-700'

        case 'completed':
            return 'bg-emerald-100 text-emerald-700'

        case 'returned':
            return 'bg-orange-100 text-orange-700'

        case 'cancelled':
            return 'bg-red-100 text-red-700'

        case 'archived':
            return 'bg-slate-100 text-slate-700'

        default:
            return 'bg-gray-100 text-gray-700'
    }
}

/*
|--------------------------------------------------------------------------
| Priority Badge
|--------------------------------------------------------------------------
*/

const priorityClass = (priority) => {
    switch (
        String(priority)
            .toLowerCase()
    ) {
        case 'urgent':
            return 'bg-red-100 text-red-700'

        case 'high':
            return 'bg-orange-100 text-orange-700'

        case 'normal':
            return 'bg-blue-100 text-blue-700'

        case 'low':
            return 'bg-gray-100 text-gray-700'

        default:
            return 'bg-gray-100 text-gray-700'
    }
}

/*
|--------------------------------------------------------------------------
| Date Formatting
|--------------------------------------------------------------------------
*/

const formatDate = (date) => {
    if (!date) {
        return 'N/A'
    }

    return new Date(date).toLocaleString()
}

/*
|--------------------------------------------------------------------------
| Empty State Text
|--------------------------------------------------------------------------
*/

const emptyMessage = () => {
    if (
        documents.value.length > 0 &&
        filteredDocuments.value.length === 0
    ) {
        return 'No documents match the current search or filter.'
    }

    if (activeTab.value === 'incoming') {
        return 'No incoming documents found.'
    }

    if (activeTab.value === 'outgoing') {
        return 'No outgoing documents found.'
    }

    return 'No documents found.'
}

watch(
    () => route.query,
    async query => {
        if (!pageMounted || route.path !== '/documents') {
            return
        }

        const nextState = parseDocumentListQuery(query)
        const viewChanged = nextState.view !== activeTab.value

        activeTab.value = nextState.view
        searchTerm.value = nextState.search
        incomingState.value = nextState.incomingState

        await replaceListQuery()

        if (viewChanged) {
            await fetchDocuments(nextState.view)
        }
    },
    { deep: true }
)

watch(searchTerm, async () => {
    if (pageMounted) {
        await replaceListQuery()
    }
})

watch(incomingState, async () => {
    if (pageMounted) {
        await replaceListQuery()
    }
})

/*
|--------------------------------------------------------------------------
| Page Load
|--------------------------------------------------------------------------
*/

onMounted(async () => {
    if (route.path === '/documents') {
        await replaceListQuery()
    }

    pageMounted = true

    await fetchDocuments(activeTab.value)

    const scannedToken =
        route.params.qrToken

    if (scannedToken) {
        await resolveQrForRegistration(
            String(scannedToken)
        )
    }
})

onBeforeUnmount(() => {
    componentUnmounted = true
    requestSequence++
    activeRequestController?.abort()
    activeRequestController = null
})
</script>

<template>
    <div class="p-6">

            <!-- QR Verification -->
            <div
                v-if="qrResolving"
                class="mb-5 rounded-md border border-blue-200 bg-blue-50 p-4 text-sm font-semibold text-blue-700"
            >
                Verifying scanned QR code...
            </div>

            <div
                v-if="qrStateError"
                class="mb-5 rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-700"
            >
                {{ qrStateError }}
            </div>

            <Card>

                <CardHeader
                    class="space-y-4"
                >
                    <div
                        class="flex flex-wrap items-start
                               justify-between gap-4"
                    >
                        <div>
                            <CardTitle>
                                Document Management
                            </CardTitle>

                            <p
                                class="text-sm text-gray-500 mt-1"
                            >
                                View and manage registered,
                                incoming, and outgoing documents.
                            </p>
                        </div>

                        <Button
                            v-if="canCreateDocuments"
                            @click="openCreateForm"
                            class="bg-blue-600 hover:bg-blue-700"
                        >
                            + Register Document
                        </Button>
                    </div>

                    <!-- Tabs -->
                    <div
                        class="flex flex-wrap gap-2
                               border-b border-gray-200"
                        role="tablist"
                        aria-label="Document views"
                    >
                        <button
                            v-for="tab in tabs"
                            :key="tab.key"
                            type="button"
                            @click="changeTab(tab.key)"
                            role="tab"
                            :aria-selected="activeTab === tab.key"
                            aria-controls="document-list-panel"
                            class="px-4 py-3 text-sm
                                   font-semibold border-b-2
                                   transition-colors"
                            :class="
                                activeTab === tab.key
                                    ? 'border-blue-600 text-blue-600'
                                    : 'border-transparent text-gray-500 hover:text-gray-800 hover:border-gray-300'
                            "
                        >
                            {{ tab.label }}
                        </button>
                    </div>

                    <div
                        class="grid gap-4 md:grid-cols-2"
                    >
                        <div>
                            <label
                                for="document-search"
                                class="mb-2 block text-sm font-semibold text-gray-700"
                            >
                                Search this document list
                            </label>

                            <Input
                                id="document-search"
                                v-model="searchTerm"
                                type="search"
                                :maxlength="DOCUMENT_SEARCH_MAX_LENGTH"
                                placeholder="Tracking number, title, type, or office"
                                autocomplete="off"
                            />
                        </div>

                        <div v-if="activeTab === 'incoming'">
                            <label
                                for="incoming-state"
                                class="mb-2 block text-sm font-semibold text-gray-700"
                            >
                                Incoming route state
                            </label>

                            <select
                                id="incoming-state"
                                v-model="incomingState"
                                class="h-10 w-full rounded-md border border-gray-300 bg-white px-3 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                            >
                                <option value="all">
                                    All states
                                </option>
                                <option value="pending">
                                    Pending receipt
                                </option>
                                <option value="received">
                                    Received
                                </option>
                            </select>
                        </div>
                    </div>

                    <p class="text-xs text-gray-500">
                        Search and filters apply to the documents currently returned for this view.
                    </p>
                </CardHeader>

                <CardContent
                    id="document-list-panel"
                    role="tabpanel"
                    :aria-busy="loading"
                >

                    <!-- Loading -->
                    <div
                        v-if="loading"
                        class="py-10 text-center text-gray-500"
                        role="status"
                        aria-live="polite"
                    >
                        Loading documents...
                    </div>

                    <!-- Error -->
                    <div
                        v-else-if="error"
                        class="rounded-md border border-red-200
                               bg-red-50 p-4 text-center"
                        role="alert"
                    >
                        <p class="text-red-600">
                            {{ error }}
                        </p>

                        <Button
                            type="button"
                            variant="outline"
                            class="mt-3"
                            @click="fetchDocuments(activeTab)"
                        >
                            Retry
                        </Button>
                    </div>

                    <!-- Empty -->
                    <div
                        v-else-if="filteredDocuments.length === 0"
                        class="py-10 text-center text-gray-500"
                        role="status"
                        aria-live="polite"
                    >
                        {{ emptyMessage() }}
                    </div>

                    <!-- Document Table -->
                    <div
                        v-else
                        class="overflow-x-auto"
                    >
                        <Table>

                            <TableHeader>
                                <TableRow>

                                    <TableHead>
                                        Tracking No.
                                    </TableHead>

                                    <TableHead>
                                        Type
                                    </TableHead>

                                    <TableHead>
                                        Title / Subject
                                    </TableHead>

                                    <!-- Incoming -->
                                    <TableHead
                                        v-if="activeTab === 'incoming'"
                                    >
                                        From Office
                                    </TableHead>

                                    <!-- Outgoing -->
                                    <TableHead
                                        v-if="activeTab === 'outgoing'"
                                    >
                                        To Office
                                    </TableHead>

                                    <!-- All -->
                                    <TableHead
                                        v-if="activeTab === 'all'"
                                    >
                                        Priority
                                    </TableHead>

                                    <TableHead>
                                        Status
                                    </TableHead>

                                    <!-- All -->
                                    <TableHead
                                        v-if="activeTab === 'all'"
                                    >
                                        Current Office
                                    </TableHead>

                                    <TableHead>
                                        {{
                                            activeTab === 'incoming'
                                                ? 'Received'
                                                : activeTab === 'outgoing'
                                                    ? 'Forwarded'
                                                    : 'Date'
                                        }}
                                    </TableHead>

                                </TableRow>
                            </TableHeader>

                            <TableBody>

                                <TableRow
                                    v-for="document in filteredDocuments"
                                    :key="document.id"
                                    class="hover:bg-gray-50"
                                >

                                    <!-- Tracking -->
                                    <TableCell
                                        class="font-medium"
                                    >
                                        <RouterLink
                                            :to="`/documents/${document.id}`"
                                            class="rounded text-blue-700 underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2"
                                            :aria-label="`View document ${document.tracking_no || document.id}: ${document.title || 'Untitled document'}`"
                                        >
                                            {{ document.tracking_no || 'N/A' }}
                                        </RouterLink>
                                    </TableCell>

                                    <!-- Type -->
                                    <TableCell>
                                        {{
                                            document.type?.type_name
                                            || 'N/A'
                                        }}
                                    </TableCell>

                                    <!-- Title -->
                                    <TableCell>
                                        <div
                                            class="max-w-xs"
                                        >
                                            <p
                                                class="font-medium
                                                       text-gray-800"
                                            >
                                                {{ document.title }}
                                            </p>
                                        </div>
                                    </TableCell>

                                    <!-- Incoming From -->
                                    <TableCell
                                        v-if="activeTab === 'incoming'"
                                    >
                                        {{ getFromOffice(document) }}
                                    </TableCell>

                                    <!-- Outgoing To -->
                                    <TableCell
                                        v-if="activeTab === 'outgoing'"
                                    >
                                        {{ getToOffice(document) }}
                                    </TableCell>

                                    <!-- Priority -->
                                    <TableCell
                                        v-if="activeTab === 'all'"
                                    >
                                        <span
                                            class="inline-flex rounded-full
                                                   px-2.5 py-1 text-xs
                                                   font-semibold"
                                            :class="
                                                priorityClass(
                                                    document.priority
                                                        ?.priority_name
                                                )
                                            "
                                        >
                                            {{
                                                document.priority
                                                    ?.priority_name
                                                || 'N/A'
                                            }}
                                        </span>
                                    </TableCell>

                                    <!-- Status -->
                                    <TableCell>
                                        <span
                                            class="inline-flex rounded-full
                                                   px-2.5 py-1 text-xs
                                                   font-semibold"
                                            :class="
                                                statusClass(
                                                    activeTab === 'all'
                                                        ? document.status
                                                            ?.status_name
                                                        : getRouteStatus(
                                                            document
                                                        )
                                                )
                                            "
                                        >
                                            {{
                                                activeTab === 'all'
                                                    ? (
                                                        document.status
                                                            ?.status_name
                                                        || 'N/A'
                                                    )
                                                    : getRouteStatus(
                                                        document
                                                    )
                                            }}
                                        </span>
                                    </TableCell>

                                    <!-- Current Office -->
                                    <TableCell
                                        v-if="activeTab === 'all'"
                                    >
                                        {{
                                            document.current_office
                                                ?.office_name
                                            || 'N/A'
                                        }}
                                    </TableCell>

                                    <!-- Date -->
                                    <TableCell>

                                        <!-- Incoming -->
                                        <template
                                            v-if="
                                                activeTab ===
                                                'incoming'
                                            "
                                        >
                                            {{
                                                getRelevantRoute(document)
                                                    ?.received_at
                                                    ? formatDate(
                                                        getRelevantRoute(
                                                            document
                                                        ).received_at
                                                    )
                                                    : 'Awaiting Receipt'
                                            }}
                                        </template>

                                        <!-- Outgoing -->
                                        <template
                                            v-else-if="
                                                activeTab ===
                                                'outgoing'
                                            "
                                        >
                                            {{
                                                formatDate(
                                                    getRelevantRoute(
                                                        document
                                                    )?.forwarded_at
                                                )
                                            }}
                                        </template>

                                        <!-- All -->
                                        <template
                                            v-else
                                        >
                                            {{
                                                formatDate(
                                                    document.created_at
                                                )
                                            }}
                                        </template>

                                    </TableCell>

                                </TableRow>

                            </TableBody>

                        </Table>
                    </div>

                </CardContent>

            </Card>

        <!-- Register Document Modal -->
        <div
            v-if="showCreateForm && canCreateDocuments"
            class="fixed inset-0 z-50 flex
                   items-center justify-center
                   bg-black/50 px-4 py-6"
        >

            <Card
                class="w-full max-w-3xl max-h-[90vh]
                       overflow-y-auto bg-white"
            >

                <CardHeader>

                    <CardTitle>
                        {{
                            isQrRegistration()
                                ? 'Register Scanned Document'
                                : 'Register New Document'
                        }}
                    </CardTitle>

                    <p
                        class="text-sm text-gray-500"
                    >
                        {{
                            isQrRegistration()
                                ? 'Enter the document information below. Saving will activate and permanently link this QR code to the document.'
                                : 'Enter the document information below. Tracking number will be generated automatically.'
                        }}
                    </p>

                </CardHeader>

                <CardContent>

                    <!-- Scanned QR Token -->
                    <div
                        v-if="isQrRegistration()"
                        class="mb-5 rounded-lg border border-blue-200 bg-blue-50 p-4"
                    >
                        <p
                            class="text-xs font-semibold uppercase tracking-wide text-blue-600"
                        >
                            Scanned QR Token
                        </p>

                        <p
                            class="mt-1 font-mono text-lg font-bold text-blue-900"
                        >
                            {{ qrToken }}
                        </p>

                        <p
                            class="mt-1 text-xs text-blue-700"
                        >
                            This QR is currently unused and will become registered after this form is saved successfully.
                        </p>
                    </div>

                    <!-- Options Loading -->
                    <div
                        v-if="optionsLoading"
                        class="py-10 text-center text-gray-500"
                    >
                        Loading form options...
                    </div>

                    <!-- Registration Form -->
                    <form
                        v-else
                        @submit.prevent="createDocument"
                        class="space-y-5"
                    >

                        <!-- Type + Priority -->
                        <div
                            class="grid grid-cols-1
                                   md:grid-cols-2 gap-4"
                        >

                            <div>
                                <label
                                    class="block mb-2 text-sm
                                           font-semibold
                                           text-gray-700"
                                >
                                    Document Type *
                                </label>

                                <select
                                    v-model="form.document_type_id"
                                    :disabled="creating"
                                    class="w-full h-11 rounded-md
                                           border border-gray-300
                                           bg-white px-3 text-sm
                                           outline-none
                                           focus:border-blue-500
                                           focus:ring-1
                                           focus:ring-blue-500"
                                >
                                    <option value="">
                                        Select Document Type
                                    </option>

                                    <option
                                        v-for="type in documentTypes"
                                        :key="type.id"
                                        :value="type.id"
                                    >
                                        {{ type.type_name }}
                                    </option>
                                </select>
                            </div>

                            <div>
                                <label
                                    class="block mb-2 text-sm
                                           font-semibold
                                           text-gray-700"
                                >
                                    Priority *
                                </label>

                                <select
                                    v-model="form.priority_id"
                                    :disabled="creating"
                                    class="w-full h-11 rounded-md
                                           border border-gray-300
                                           bg-white px-3 text-sm
                                           outline-none
                                           focus:border-blue-500
                                           focus:ring-1
                                           focus:ring-blue-500"
                                >
                                    <option value="">
                                        Select Priority
                                    </option>

                                    <option
                                        v-for="priority in priorities"
                                        :key="priority.id"
                                        :value="priority.id"
                                    >
                                        {{ priority.priority_name }}
                                    </option>
                                </select>
                            </div>

                        </div>

                        <!-- Title -->
                        <div>
                            <label
                                class="block mb-2 text-sm
                                       font-semibold text-gray-700"
                            >
                                Title / Subject *
                            </label>

                            <Input
                                v-model="form.title"
                                type="text"
                                placeholder="Enter document title or subject"
                                class="h-11"
                                :disabled="creating"
                            />
                        </div>

                        <!-- Description -->
                        <div>
                            <label
                                class="block mb-2 text-sm
                                       font-semibold text-gray-700"
                            >
                                Document Details
                            </label>

                            <textarea
                                v-model="form.description"
                                rows="4"
                                placeholder="Enter document details"
                                :disabled="creating"
                                class="w-full rounded-md
                                       border border-gray-300
                                       px-3 py-2 text-sm
                                       focus:outline-none
                                       focus:ring-2
                                       focus:ring-blue-500"
                            ></textarea>
                        </div>

                        <!-- Confidentiality + Origin -->
                        <div
                            class="grid grid-cols-1
                                   md:grid-cols-2 gap-4"
                        >

                            <div>
                                <label
                                    class="block mb-2 text-sm
                                           font-semibold
                                           text-gray-700"
                                >
                                    Confidentiality *
                                </label>

                                <select
                                    v-model="
                                        form.confidentiality_level_id
                                    "
                                    :disabled="creating"
                                    class="w-full h-11 rounded-md
                                           border border-gray-300
                                           bg-white px-3 text-sm
                                           outline-none
                                           focus:border-blue-500
                                           focus:ring-1
                                           focus:ring-blue-500"
                                >
                                    <option value="">
                                        Select Confidentiality
                                    </option>

                                    <option
                                        v-for="
                                            level in
                                            confidentialityLevels
                                        "
                                        :key="level.id"
                                        :value="level.id"
                                    >
                                        {{ level.level_name }}
                                    </option>
                                </select>
                            </div>

                            <div>
                                <label
                                    class="block mb-2 text-sm
                                           font-semibold
                                           text-gray-700"
                                >
                                    Origin Office *
                                </label>

                                <select
                                    v-model="form.origin_office_id"
                                    :disabled="creating"
                                    class="w-full h-11 rounded-md
                                           border border-gray-300
                                           bg-white px-3 text-sm
                                           outline-none
                                           focus:border-blue-500
                                           focus:ring-1
                                           focus:ring-blue-500"
                                >
                                    <option value="">
                                        Select Origin Office
                                    </option>

                                    <option
                                        v-for="office in offices"
                                        :key="office.id"
                                        :value="office.id"
                                    >
                                        {{ office.office_name }}
                                        ({{ office.office_code }})
                                    </option>
                                </select>
                            </div>

                        </div>

                        <!-- Dates -->
                        <div
                            class="grid grid-cols-1
                                   md:grid-cols-2 gap-4"
                        >

                            <div>
                                <label
                                    class="block mb-2 text-sm
                                           font-semibold
                                           text-gray-700"
                                >
                                    Document Date *
                                </label>

                                <Input
                                    v-model="form.document_date"
                                    type="date"
                                    class="h-11"
                                    :disabled="creating"
                                />
                            </div>

                            <div>
                                <label
                                    class="block mb-2 text-sm
                                           font-semibold
                                           text-gray-700"
                                >
                                    Due Date
                                </label>

                                <Input
                                    v-model="form.due_date"
                                    type="date"
                                    class="h-11"
                                    :disabled="creating"
                                />
                            </div>

                        </div>

                        <!-- Error -->
                        <div
                            v-if="createError"
                            class="rounded-md bg-red-50
                                   border border-red-200 p-3
                                   text-sm text-red-600"
                        >
                            {{ createError }}
                        </div>

                        <!-- Success -->
                        <div
                            v-if="createSuccess"
                            class="rounded-md bg-green-50
                                   border border-green-200 p-3
                                   text-sm font-semibold
                                   text-green-700"
                        >
                            {{ createSuccess }}
                        </div>

                        <!-- Buttons -->
                        <div
                            class="flex justify-end gap-3 pt-2"
                        >
                            <Button
                                type="button"
                                variant="outline"
                                @click="closeCreateForm"
                                :disabled="creating"
                            >
                                Cancel
                            </Button>

                            <Button
                                type="submit"
                                class="bg-blue-600 hover:bg-blue-700"
                                :disabled="creating"
                            >
                                {{
                                    creating
                                        ? 'Registering...'
                                        : 'Register Document'
                                }}
                            </Button>
                        </div>

                    </form>

                </CardContent>

            </Card>

        </div>

    </div>
</template>
