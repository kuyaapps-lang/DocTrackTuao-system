<script setup>
import {
    computed,
    nextTick,
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
import { formatDocumentDateTime } from '@/lib/document-dates'
import { normalizeRegistrationQrInput } from '@/lib/qr-registration'
import {
    buildDocumentListQuery,
    buildDocumentListRequestQuery,
    DOCUMENT_LIST_DEFAULT_PER_PAGE,
    DOCUMENT_LIST_PER_PAGE_OPTIONS,
    DOCUMENT_SEARCH_DEBOUNCE_MS,
    DOCUMENT_SEARCH_MAX_LENGTH,
    getDocumentPaginationState,
    isValidDocumentListResponse,
    normalizeDocumentSearch,
    parseDocumentListQuery,
    resetDocumentListPage,
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
const currentPage = ref(initialQuery.page)
const perPage = ref(initialQuery.perPage)
const paginationMeta = ref({
    current_page: initialQuery.page,
    last_page: 1,
    per_page: initialQuery.perPage,
    total: 0,
    from: null,
    to: null,
})

let activeRequestController = null
let componentUnmounted = false
let lastRequestKey = ''
let pageMounted = false
let requestSequence = 0
let searchDebounceTimer = null

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

const paginationState = computed(() => {
    return getDocumentPaginationState(paginationMeta.value)
})

const paginationItems = computed(() => {
    const lastPage = Math.max(1, paginationMeta.value.last_page)
    const page = Math.min(
        Math.max(1, paginationMeta.value.current_page),
        lastPage
    )

    if (lastPage <= 7) {
        return Array.from(
            { length: lastPage },
            (_, index) => ({ type: 'page', page: index + 1 })
        )
    }

    const pages = [1, page - 1, page, page + 1, lastPage]
        .filter(item => item >= 1 && item <= lastPage)
        .filter((item, index, items) => items.indexOf(item) === index)
        .sort((left, right) => left - right)

    return pages.flatMap((item, index) => {
        const previous = pages[index - 1]
        const ellipsis = previous && item - previous > 1
            ? [{ type: 'ellipsis', key: `ellipsis-${previous}-${item}` }]
            : []

        return [...ellipsis, { type: 'page', page: item }]
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
const qrInput = ref('')
const qrVerified = ref(false)
const qrVerifying = ref(false)
const qrVerificationError = ref('')
const qrInputElement = ref(null)

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

const currentListState = () => ({
    view: activeTab.value,
    search: normalizeDocumentSearch(searchTerm.value),
    incomingState: incomingState.value,
    page: currentPage.value,
    perPage: perPage.value,
})

const documentRequestKey = state => JSON.stringify({
    view: state.view,
    query: buildDocumentListRequestQuery(state),
})

const fetchDocuments = async (state = currentListState()) => {
    if (componentUnmounted) {
        return
    }

    const requestId = ++requestSequence
    lastRequestKey = documentRequestKey(state)

    activeRequestController?.abort()
    const requestController = new AbortController()
    activeRequestController = requestController

    loading.value = true
    error.value = ''

    try {
        const requestQuery = new URLSearchParams(
            buildDocumentListRequestQuery(state)
        )
        const response = await fetch(
            `${getDocumentEndpoint(state.view)}?${requestQuery}`,
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

        if (!isValidDocumentListResponse(data)) {
            throw new Error()
        }

        documents.value = data.data
        paginationMeta.value = data.meta

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
    return buildDocumentListQuery(currentListState())
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

    clearTimeout(searchDebounceTimer)

    const nextState = resetDocumentListPage(currentListState(), {
        view: tab,
        incomingState: tab === 'incoming'
            ? incomingState.value
            : 'all',
    })

    await router.push({
        path: '/documents',
        query: buildDocumentListQuery(nextState),
    })
}

const changePage = async page => {
    if (
        loading.value ||
        page === currentPage.value ||
        page < 1 ||
        page > paginationMeta.value.last_page
    ) {
        return
    }

    await router.push({
        path: '/documents',
        query: buildDocumentListQuery({
            ...currentListState(),
            page,
        }),
    })
}

/*
|--------------------------------------------------------------------------
| Resolve Scanned QR
|--------------------------------------------------------------------------
*/

const verifyQrForRegistration = async () => {
    const token = normalizeRegistrationQrInput(qrInput.value)
    qrInput.value = token
    qrVerificationError.value = ''
    qrVerified.value = false

    if (!token) {
        qrVerificationError.value = 'Enter or scan a QR code first.'
        return
    }

    qrVerifying.value = true

    try {
        const response = await fetch(
            '/api/qr-codes/verify-registration',
            {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    Authorization: `Bearer ${getToken()}`,
                },
                body: JSON.stringify({ qr_token: token }),
            }
        )

        const data = await response.json().catch(() => ({}))

        if (!response.ok) {
            throw new Error(
                data?.errors?.qr_token?.[0] || data?.message ||
                'This QR code cannot be used for document registration.'
            )
        }

        qrToken.value = data.qr_token
        qrVerified.value = true
        await loadRegistrationOptions()
    } catch (err) {
        qrToken.value = ''
        qrVerificationError.value =
            err.message ||
            'This QR code cannot be used for document registration.'
    } finally {
        qrVerifying.value = false
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

const loadRegistrationOptions = async () => {
    if (documentTypes.value.length === 0 || priorities.value.length === 0 || confidentialityLevels.value.length === 0 || offices.value.length === 0) {
        await fetchFormOptions()
    }

    const normalPriority = priorities.value.find(item => item.priority_name === 'Normal')
    const publicLevel = confidentialityLevels.value.find(item => item.level_name === 'Public')

    if (normalPriority) form.value.priority_id = normalPriority.id
    if (publicLevel) form.value.confidentiality_level_id = publicLevel.id
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
    qrToken.value = ''
    qrInput.value = ''
    qrVerified.value = false
    qrVerificationError.value = ''
    showCreateForm.value = true
    await nextTick()
    qrInputElement.value?.$el?.focus()
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

    qrToken.value = ''
    qrInput.value = ''
    qrVerified.value = false
    qrVerificationError.value = ''

    if (route.name === 'qr-document-registration') {
        router.replace('/documents')
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

    if (!qrVerified.value || !qrToken.value) {
        createError.value = 'Verify a valid QR code before registering this document.'
        return
    }

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
                        qrToken.value,
                }),
            }
        )

        const data = await response.json()

        if (!response.ok) {
            if (data.errors) {
                if (data.errors.qr_token?.[0]) {
                    qrVerified.value = false
                    qrToken.value = ''
                    qrVerificationError.value = data.errors.qr_token[0]
                    return
                }
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
    return formatDocumentDateTime(date)
}

/*
|--------------------------------------------------------------------------
| Empty State Text
|--------------------------------------------------------------------------
*/

const emptyMessage = () => {
    if (
        normalizeDocumentSearch(searchTerm.value) !== '' ||
        (
            activeTab.value === 'incoming' &&
            incomingState.value !== 'all'
        )
    ) {
        return 'No documents match the current search or filter. Try a different keyword or clear the filter.'
    }

    if (activeTab.value === 'incoming') {
        return 'No incoming documents are waiting for this view.'
    }

    if (activeTab.value === 'outgoing') {
        return 'No outgoing documents have been recorded for this view.'
    }

    return 'No registered documents are available yet.'
}

watch(
    () => route.query,
    async query => {
        if (!pageMounted || route.path !== '/documents') {
            return
        }

        const nextState = parseDocumentListQuery(query)

        activeTab.value = nextState.view
        searchTerm.value = nextState.search
        incomingState.value = nextState.incomingState
        currentPage.value = nextState.page
        perPage.value = nextState.perPage

        await replaceListQuery()

        if (documentRequestKey(nextState) !== lastRequestKey) {
            await fetchDocuments(nextState)
        }
    },
    { deep: true }
)

watch(searchTerm, value => {
    if (!pageMounted) {
        return
    }

    const normalizedSearch = normalizeDocumentSearch(value)

    if (normalizedSearch === parseDocumentListQuery(route.query).search) {
        return
    }

    clearTimeout(searchDebounceTimer)
    searchDebounceTimer = setTimeout(async () => {
        await router.replace({
            path: '/documents',
            query: buildDocumentListQuery(
                resetDocumentListPage(currentListState(), {
                    search: normalizedSearch,
                })
            ),
        })
    }, DOCUMENT_SEARCH_DEBOUNCE_MS)
})

watch(incomingState, async () => {
    if (
        pageMounted &&
        incomingState.value !==
            parseDocumentListQuery(route.query).incomingState
    ) {
        await router.replace({
            path: '/documents',
            query: buildDocumentListQuery(
                resetDocumentListPage(currentListState())
            ),
        })
    }
})

watch(perPage, async () => {
    if (
        pageMounted &&
        perPage.value !== parseDocumentListQuery(route.query).perPage
    ) {
        await router.replace({
            path: '/documents',
            query: buildDocumentListQuery(
                resetDocumentListPage(currentListState())
            ),
        })
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

    await fetchDocuments(currentListState())

    const scannedToken =
        route.params.qrToken

    if (scannedToken) {
        await openCreateForm()
    }
})

onBeforeUnmount(() => {
    componentUnmounted = true
    requestSequence++
    clearTimeout(searchDebounceTimer)
    activeRequestController?.abort()
    activeRequestController = null
})
</script>

<template>
    <div class="min-h-screen bg-slate-100 p-6">

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
                                class="text-[13pt] text-gray-500 mt-1"
                            >
                                Review registered documents, incoming items,
                                and outgoing routes in one place.
                            </p>
                        </div>

                        <Button
                            v-if="canCreateDocuments"
                            @click="openCreateForm()"
                            class="bg-blue-900 text-white hover:bg-blue-950 hover:text-white"
                        >
                            + Register Document
                        </Button>
                    </div>

                    <!-- Tabs -->
                    <div
                        class="flex flex-wrap gap-2 rounded-lg border border-blue-900 bg-blue-900 px-3 pt-3 [&_*]:!text-[13pt]"
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
                            class="rounded-t-md border-b-2 px-4 py-3 text-sm font-semibold transition-colors"
                            :class="
                                activeTab === tab.key
                                    ? 'border-white bg-white text-blue-900 shadow-sm'
                                    : 'border-transparent text-blue-100 hover:border-blue-200 hover:bg-blue-800 hover:text-white'
                            "
                        >
                            {{ tab.label }}
                        </button>
                    </div>

                    <div
                        class="grid gap-[26px] xl:gap-5 xl:grid-cols-[minmax(465px,1fr)_250px_130px] [&_*]:!text-[13pt]"
                    >
                        <div class="order-1 min-w-[315px] xl:order-none">
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
                                placeholder="Tracking number, QR code, title, type, or office"
                                autocomplete="off"
                                class="h-10 border-slate-600 px-[10px]"
                            />
                        </div>

                        <div v-if="activeTab === 'incoming'" class="order-3 xl:order-none">
                            <label
                                for="incoming-state"
                                class="mb-2 block text-sm font-semibold text-gray-700"
                            >
                                Incoming route state
                            </label>

                            <select
                                id="incoming-state"
                                v-model="incomingState"
                                class="h-10 w-[250px] rounded-md border border-slate-600 bg-white px-[10px] text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
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

                        <div
                            class="order-2 flex items-center justify-start gap-5 xl:order-none xl:block xl:text-right"
                            :class="activeTab === 'incoming' ? '' : 'xl:col-start-3'"
                        >
                            <label
                                for="documents-per-page"
                                class="w-[130px] shrink-0 whitespace-nowrap text-sm font-semibold text-gray-700 xl:mb-2 xl:ml-auto xl:block"
                            >
                                Results per page
                            </label>

                            <select
                                id="documents-per-page"
                                v-model.number="perPage"
                                class="h-10 w-[130px] rounded-md border border-slate-600 bg-white px-[10px] text-right text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                            >
                                <option
                                    v-for="option in DOCUMENT_LIST_PER_PAGE_OPTIONS"
                                    :key="option"
                                    :value="option"
                                >
                                    {{ option }}
                                </option>
                            </select>
                        </div>
                    </div>

                </CardHeader>

                <CardContent
                    id="document-list-panel"
                    role="tabpanel"
                    :aria-busy="loading"
                    class="px-[10px] pb-[10px] pt-[7px] [&_*]:!text-[13pt]"
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
                            @click="fetchDocuments()"
                        >
                            Retry
                        </Button>
                    </div>

                    <!-- Empty -->
                    <div
                        v-else-if="documents.length === 0"
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
                        <Table class="min-w-[46rem] table-auto [&_td]:whitespace-normal [&_td]:px-[10px] [&_td]:py-[10px] [&_th]:whitespace-normal [&_th]:px-[10px] [&_th]:py-[10px]">

                            <TableHeader class="bg-blue-900 text-white">
                                <TableRow>

                                    <TableHead class="text-white font-semibold">
                                        Tracking No.
                                    </TableHead>

                                    <TableHead class="text-white font-semibold">
                                        Type
                                    </TableHead>

                                    <TableHead class="w-[28%] whitespace-normal text-white font-semibold">
                                        Title / Subject
                                    </TableHead>

                                    <!-- Incoming -->
                                    <TableHead
                                        v-if="activeTab === 'incoming'"
                                        class="text-white font-semibold"
                                    >
                                        From Office
                                    </TableHead>

                                    <!-- Outgoing -->
                                    <TableHead
                                        v-if="activeTab === 'outgoing'"
                                        class="text-white font-semibold"
                                    >
                                        To Office
                                    </TableHead>

                                    <!-- All -->
                                    <TableHead
                                        v-if="activeTab === 'all'"
                                        class="text-white font-semibold"
                                    >
                                        Priority
                                    </TableHead>

                                    <TableHead class="text-white font-semibold">
                                        Status
                                    </TableHead>

                                    <!-- All -->
                                    <TableHead
                                        v-if="activeTab === 'all'"
                                        class="text-white font-semibold"
                                    >
                                        Current Office
                                    </TableHead>

                                    <TableHead class="text-white font-semibold">
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
                                    v-for="document in documents"
                                    :key="document.id"
                                    class="border-b border-slate-200 hover:bg-gray-50 last:border-b-0"
                                >

                                    <!-- Tracking -->
                                    <TableCell
                                        class="min-w-0 break-all whitespace-normal font-medium"
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
                                    <TableCell class="min-w-0 break-words whitespace-normal">
                                        {{
                                            document.type?.type_name
                                            || 'N/A'
                                        }}
                                    </TableCell>

                                    <!-- Title -->
                                    <TableCell class="min-w-0 whitespace-normal">
                                        <div
                                            class="max-w-xs whitespace-normal break-words"
                                        >
                                            <p
                                                class="break-words font-medium leading-6
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
                                                   px-2.5 py-1 !text-[15.5px]
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
                                                   px-2.5 py-1 !text-[15.5px]
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

                    <div
                        v-if="!loading && !error"
                        class="mt-4 flex flex-col items-center gap-3 border-t pt-4"
                    >
                        <p class="text-center text-sm text-gray-600">
                            {{ paginationMeta.total }} total results
                        </p>

                        <nav class="max-w-full overflow-x-auto rounded-full bg-white p-1 shadow-[0_8px_18px_rgb(15_41_70/0.12)]" aria-label="Document list pagination">
                            <div class="flex min-w-max items-center gap-1">
                                <button
                                    type="button"
                                    class="h-10 rounded-full px-3 font-semibold text-blue-900 transition-colors hover:bg-blue-50 disabled:cursor-not-allowed disabled:opacity-40"
                                    :disabled="loading || !paginationState.canGoPrevious"
                                    @click="changePage(paginationState.previousPage)"
                                >
                                    ‹ Prev
                                </button>

                                <template v-for="item in paginationItems" :key="item.type === 'page' ? item.page : item.key">
                                    <span v-if="item.type === 'ellipsis'" class="flex size-10 items-center justify-center font-bold text-blue-900" aria-hidden="true">…</span>
                                    <button
                                        v-else
                                        type="button"
                                        class="size-10 rounded-full font-semibold transition-colors"
                                        :class="item.page === paginationMeta.current_page ? 'bg-blue-900 text-white shadow-[inset_0_1px_2px_rgb(15_41_70/0.18)]' : 'text-blue-900 hover:bg-blue-50'"
                                        :aria-current="item.page === paginationMeta.current_page ? 'page' : undefined"
                                        :disabled="loading"
                                        @click="changePage(item.page)"
                                    >
                                        {{ item.page }}
                                    </button>
                                </template>

                                <button
                                    type="button"
                                    class="h-10 rounded-full px-3 font-semibold text-blue-900 transition-colors hover:bg-blue-50 disabled:cursor-not-allowed disabled:opacity-40"
                                    :disabled="loading || !paginationState.canGoNext"
                                    @click="changePage(paginationState.nextPage)"
                                >
                                    Next ›
                                </button>
                            </div>
                        </nav>
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

                    <CardTitle>{{ qrVerified ? 'Register New Document' : 'Verify QR Code' }}</CardTitle>

                    <p v-if="!qrVerified" class="text-sm text-gray-500">
                        Scan or enter an issued QR code before registering a document.
                    </p>

                </CardHeader>

                <CardContent>

                    <form v-if="!qrVerified" class="space-y-4" @submit.prevent="verifyQrForRegistration">
                        <div>
                            <label for="registration-qr-token" class="mb-2 block text-sm font-semibold text-gray-700">QR Code <span class="text-red-600">*</span></label>
                            <Input ref="qrInputElement" id="registration-qr-token" v-model="qrInput" type="text" autocomplete="off" autofocus placeholder="Scan or enter QR code" :disabled="qrVerifying" class="h-11 font-mono" />
                        </div>
                        <p v-if="qrVerificationError" class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert">{{ qrVerificationError }}</p>
                        <div class="flex justify-end gap-3">
                            <Button type="button" variant="outline" class="border-black bg-black text-white hover:bg-black/90 hover:text-white" :disabled="qrVerifying" @click="closeCreateForm">Cancel</Button>
                            <Button type="submit" class="bg-blue-900 text-white hover:bg-blue-950 hover:text-white" :disabled="qrVerifying">{{ qrVerifying ? 'Verifying...' : 'Verify QR' }}</Button>
                        </div>
                    </form>

                    <template v-else>
                    <output aria-label="Verified QR code" class="mb-4 inline-flex max-w-full rounded-md border border-slate-300 bg-slate-50 px-3 py-2 font-mono text-sm font-semibold text-slate-800">
                        {{ qrToken }}
                    </output>

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
                                    Document Type <span class="text-red-600">*</span>
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
                                    Priority <span class="text-red-600">*</span>
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
                                Title / Subject <span class="text-red-600">*</span>
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
                                    Confidentiality <span class="text-red-600">*</span>
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
                                    Origin Office <span class="text-red-600">*</span>
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
                        <div class="ml-auto grid w-full grid-cols-1 gap-4 md:w-auto md:grid-cols-2">

                            <div>
                                <label
                                    class="block mb-2 text-sm
                                           font-semibold
                                           text-gray-700"
                                >
                                    Document Date <span class="text-red-600">*</span>
                                </label>

                                <Input
                                    v-model="form.document_date"
                                    type="date"
                                    class="h-11 text-right"
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
                                    class="h-11 text-right"
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
                                class="border-black bg-black text-[17px] text-white hover:bg-black/90 hover:text-white"
                                @click="closeCreateForm"
                                :disabled="creating"
                            >
                                Cancel
                            </Button>

                            <Button
                                type="submit"
                                class="h-12 px-5 text-[18px] leading-none bg-blue-900 text-white hover:bg-blue-950 hover:text-white"
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
                    </template>

                </CardContent>

            </Card>

        </div>

    </div>
</template>
