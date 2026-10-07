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
    FileText,
    Flag,
    ListFilter,
    RotateCcw,
    Search,
} from 'lucide-vue-next'

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
import TableSkeleton from '@/components/loaders/TableSkeleton.vue'
import { Input } from '@/components/ui/input'
import CameraQrScanner from '@/components/CameraQrScanner.vue'
import DocTrackDatePicker from '@/components/DocTrackDatePicker.vue'
import { formatDateValue } from '@/lib/date-picker'
import { can } from '@/lib/auth'
import { ensureCurrentUser } from '@/lib/auth'
import { listenForRealtimeInvalidation } from '@/lib/realtime'
import { formatDocumentDateTime } from '@/lib/document-dates'
import { normalizeRegistrationQrInput } from '@/lib/qr-registration'
import {
    buildDocumentListQuery,
    buildDocumentListRequestQuery,
    DOCUMENT_LIST_DEFAULT_PER_PAGE,
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

const parsedInitialQuery = parseDocumentListQuery(route.query)
const initialQuery = {
    ...parsedInitialQuery,
    perPage: parsedInitialQuery.view === 'incoming' ? 50 : parsedInitialQuery.perPage,
}
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
const incomingLoadingMore = ref(false)
const incomingSentinel = ref(null)

let activeRequestController = null
let componentUnmounted = false
let lastRequestKey = ''
let pageMounted = false
let requestSequence = 0
let searchDebounceTimer = null
let leaveRealtime = null
let incomingObserver = null

const canLoadMoreIncoming = computed(() => {
    return activeTab.value === 'incoming' && documents.value.length < paginationMeta.value.total
})

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
const offices = ref([])
const officeTagSearch = ref('')

const filteredTagOffices = computed(() => {
    const query = officeTagSearch.value.trim().toLowerCase()

    if (!query) {
        return []
    }

    return offices.value.filter(office => {
        const name = String(office.office_name || '').toLowerCase()
        const code = String(office.office_code || '').toLowerCase()

        return name.includes(query) || code.includes(query)
    })
})

const selectedTagOfficeNames = computed(() => {
    const selectedIds = new Set((form.value.tagged_office_ids || []).map(Number))

    return offices.value
        .filter(office => selectedIds.has(Number(office.id)))
        .map(office => `${office.office_name} (${office.office_code})`)
})

const toggleTaggedOffice = officeId => {
    const id = Number(officeId)
    const selectedIds = new Set((form.value.tagged_office_ids || []).map(Number))

    if (selectedIds.has(id)) {
        selectedIds.delete(id)
    } else {
        selectedIds.add(id)
    }

    form.value.tagged_office_ids = Array.from(selectedIds)
}

const isTaggedOfficeSelected = officeId => {
    return (form.value.tagged_office_ids || []).map(Number).includes(Number(officeId))
}

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
const updatedElsewhere = ref(false)

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
    tagged_office_ids: [],
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

const canSubmitRegistration = computed(() => {
    return Boolean(
        qrVerified.value &&
        qrToken.value &&
        form.value.title.trim() &&
        form.value.document_type_id &&
        form.value.priority_id &&
        form.value.document_date
    )
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
        if (state.view === 'incoming') {
            await nextTick()
            observeIncomingScroll()
        }

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

const loadMoreIncomingDocuments = async () => {
    if (componentUnmounted || loading.value || incomingLoadingMore.value || !canLoadMoreIncoming.value) return

    incomingLoadingMore.value = true
    try {
        const state = currentListState()
        const nextPage = Math.floor(documents.value.length / 10) + 1
        const requestQuery = new URLSearchParams(buildDocumentListRequestQuery({
            ...state,
            page: nextPage,
            perPage: 10,
        }))
        const response = await fetch(`${getDocumentEndpoint('incoming')}?${requestQuery}`, {
            headers: { Accept: 'application/json', Authorization: `Bearer ${getToken()}` },
        })
        const data = await response.json()
        if (!response.ok || !isValidDocumentListResponse(data)) throw new Error('Unable to load more documents. Please try again.')

        const seen = new Set(documents.value.map(document => document.id))
        documents.value.push(...data.data.filter(document => !seen.has(document.id)))
        paginationMeta.value = { ...paginationMeta.value, total: data.meta.total }
    } catch (err) {
        error.value = err?.message || 'Unable to load more documents. Please try again.'
    } finally {
        incomingLoadingMore.value = false
    }
}

const observeIncomingScroll = () => {
    if (!incomingSentinel.value || typeof IntersectionObserver === 'undefined') return

    incomingObserver?.disconnect()
    incomingObserver = new IntersectionObserver(entries => {
        if (entries.some(entry => entry.isIntersecting)) loadMoreIncomingDocuments()
    }, { rootMargin: '240px' })
    incomingObserver.observe(incomingSentinel.value)
}

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

const scanRegistrationCamera = async token => {
    qrInput.value = token
    await verifyQrForRegistration()
}

const clearQrVerificationWhenChanged = () => {
    if (normalizeRegistrationQrInput(qrInput.value) === qrToken.value) {
        return
    }

    qrToken.value = ''
    qrVerified.value = false
    qrVerificationError.value = ''
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
        tagged_office_ids: [],
        document_date: formatDateValue(new Date()),
        due_date: '',
    }

    createError.value = ''
    createSuccess.value = ''
    officeTagSearch.value = ''
}

const loadRegistrationOptions = async () => {
    if (documentTypes.value.length === 0 || priorities.value.length === 0 || offices.value.length === 0) {
        await fetchFormOptions()
    }

    const normalPriority = priorities.value.find(item => item.priority_name === 'Normal')

    if (normalPriority) form.value.priority_id = normalPriority.id
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

                    tagged_office_ids: form.value.tagged_office_ids.map(Number),

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
    await nextTick()
    observeIncomingScroll()
    const user = await ensureCurrentUser().catch(() => null)
    if (user) {
        const channel = ['Administrator', 'Records Officer'].includes(user.role?.role_name || user.role?.name)
            ? 'doc-track.documents.system'
            : `doc-track.documents.office.${user.office_id}`
        leaveRealtime = listenForRealtimeInvalidation([channel], () => {
            if (showCreateForm.value) { updatedElsewhere.value = true; return }
            fetchDocuments(currentListState())
        })
    }

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
    incomingObserver?.disconnect()
    leaveRealtime?.()
})
</script>

<template>
    <div class="min-h-screen bg-slate-100 p-6">
        <p v-if="updatedElsewhere" class="mb-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800" role="status">Documents were updated elsewhere. Close or submit the registration form to refresh safely.</p>

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
                                {{
                                    activeTab === 'outgoing'
                                        ? 'Outgoing Documents'
                                        : activeTab === 'incoming'
                                            ? 'Incoming Documents'
                                            : 'Document Management'
                                }}
                            </CardTitle>

                            <p
                                class="text-[13pt] text-gray-500 mt-1"
                            >
                                {{
                                    activeTab === 'outgoing'
                                        ? 'Released documents sent to the next office for action.'
                                        : activeTab === 'incoming'
                                            ? 'Documents received or awaiting receipt by your office.'
                                            : 'Review registered documents in one place.'
                                }}
                            </p>
                        </div>

                        <Button
                            v-if="canCreateDocuments && activeTab === 'outgoing'"
                            @click="openCreateForm()"
                            class="bg-blue-900 text-white hover:bg-blue-950 hover:text-white"
                        >
                            + Register Document
                        </Button>
                    </div>

                    <div
                        class="grid gap-[26px] xl:gap-5 xl:grid-cols-[minmax(465px,1fr)_250px] [&_*]:!text-[13pt]"
                    >
                        <div class="order-1 min-w-[315px] xl:order-none">
                            <label
                                for="document-search"
                                class="mb-2 block text-sm font-semibold text-gray-700"
                            >
                                Search this document list
                            </label>

                            <div class="relative">
                                <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-500" />
                                <Input
                                    id="document-search"
                                    v-model="searchTerm"
                                    type="search"
                                    :maxlength="DOCUMENT_SEARCH_MAX_LENGTH"
                                    placeholder="Tracking number, QR code, title, type, or office"
                                    autocomplete="off"
                                    class="h-10 border-slate-600 pl-10 pr-[10px]"
                                />
                            </div>
                        </div>

                        <div v-if="activeTab === 'incoming'" class="order-3 xl:order-none">
                            <label
                                for="incoming-state"
                                class="mb-2 block text-sm font-semibold text-gray-700"
                            >
                                Incoming route state
                            </label>

                            <div class="relative">
                                <ListFilter class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-500" />
                                <select
                                    id="incoming-state"
                                    v-model="incomingState"
                                    class="h-10 w-[250px] rounded-md border border-slate-600 bg-white pl-10 pr-[10px] text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
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

                    </div>

                </CardHeader>

                <CardContent
                    id="document-list-panel"
                    role="tabpanel"
                    :aria-busy="loading"
                    class="px-[10px] pb-[10px] pt-[7px] [&_*]:!text-[13pt]"
                >

                    <!-- Loading -->
                    <TableSkeleton v-if="loading" :columns="6" />

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
                            <RotateCcw class="mr-2 h-4 w-4" />
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
                        <Table
                            class="min-w-[46rem] table-auto [&_td]:whitespace-normal [&_td]:px-[10px] [&_td]:py-[10px] [&_th]:whitespace-normal [&_th]:px-[10px] [&_th]:py-[10px]"
                            :class="['incoming', 'outgoing'].includes(activeTab) ? '[&_tbody_td]:!text-[10.5pt] [&_tbody_td_*]:!text-[10.5pt]' : ''"
                        >

                            <TableHeader class="bg-blue-900 text-white">
                                <TableRow>

                                    <TableHead class="text-white font-semibold">
                                        QR Code
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
                                        v-if="activeTab === 'all' || activeTab === 'incoming'"
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
                                                    ? 'Released'
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
                                    :class="activeTab === 'incoming' && String(document.priority?.priority_name).toLowerCase() === 'urgent' ? 'bg-red-400/25 hover:bg-red-400/30' : ''"
                                >

                                    <!-- Tracking -->
                                    <TableCell
                                        class="min-w-0 break-all whitespace-normal font-medium"
                                    >
                                        <RouterLink
                                            :to="{
                                                path: `/documents/${document.id}`,
                                                query: { return_view: activeTab },
                                            }"
                                            class="rounded text-blue-700 underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2"
                                            :aria-label="`View document ${document.qr_code || document.id}: ${document.title || 'Untitled document'}`"
                                        >
                                            {{ document.qr_code || 'No QR code' }}
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
                                        v-if="activeTab === 'all' || activeTab === 'incoming'"
                                    >
                                        <span
                                            class="inline-flex rounded-full
                                                   px-1.5 py-0.5 !text-[5pt]
                                                   font-semibold leading-tight"
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
                                                   px-1.5 py-0.5 !text-[5pt]
                                                   font-semibold leading-tight"
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
                        v-if="!loading && !error && activeTab !== 'incoming'"
                        class="mt-4 flex flex-col items-center border-t pt-4"
                    >
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
                    <div v-if="!loading && !error && activeTab === 'incoming'" ref="incomingSentinel" class="mt-4 h-1" aria-hidden="true" />
                    <p v-if="!loading && !error && activeTab === 'incoming' && incomingLoadingMore" class="mt-4 text-center text-sm text-slate-500">Loading 10 more documents...</p>
                    <p v-else-if="!loading && !error && activeTab === 'incoming' && documents.length > 0 && !canLoadMoreIncoming" class="mt-4 text-center text-sm text-slate-500">All incoming documents are loaded.</p>

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
                class="doctrack-date-picker-boundary w-full max-w-3xl max-h-[90vh]
                       overflow-y-auto bg-white"
            >

                <CardHeader>

                    <CardTitle>Register New Document</CardTitle>

                    <p class="text-sm text-gray-500">
                        Scan or enter an issued QR code before registering the document.
                    </p>

                </CardHeader>

                <CardContent>

                    <form
                        @submit.prevent="createDocument"
                        class="space-y-5"
                    >
                        <div class="mx-auto max-w-md text-center">
                            <label for="registration-qr-token" class="mb-2 block text-sm font-semibold text-gray-700">
                                QR Code <span class="text-red-600">*</span>
                            </label>
                            <Input
                                ref="qrInputElement"
                                id="registration-qr-token"
                                v-model="qrInput"
                                type="text"
                                autocomplete="off"
                                autofocus
                                placeholder="Scan QR here"
                                :disabled="qrVerifying || creating"
                                class="h-11 text-center font-mono"
                                @input="clearQrVerificationWhenChanged"
                                @keydown.enter.prevent="verifyQrForRegistration"
                            />
                            <div class="mt-3 flex justify-center">
                                <CameraQrScanner
                                    :disabled="qrVerifying || creating"
                                    @scan="scanRegistrationCamera"
                                />
                            </div>
                            <p v-if="qrVerifying" class="mt-2 text-sm text-gray-500">Verifying QR code...</p>
                            <p v-else-if="qrVerified" class="mt-2 text-sm font-medium text-green-700" role="status">QR code verified.</p>
                            <p v-if="qrVerificationError" class="mt-3 rounded-md border border-red-200 bg-red-50 p-3 text-left text-sm text-red-700" role="alert">{{ qrVerificationError }}</p>
                        </div>

                    <div
                        v-if="optionsLoading"
                        class="py-10 text-center text-gray-500"
                    >
                        Loading form options...
                    </div>

                    <fieldset
                        v-else
                        :disabled="creating || qrVerifying || !qrVerified"
                        class="contents"
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

                                <div class="relative">
                                    <FileText class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-500" />
                                    <select
                                        v-model="form.document_type_id"
                                        :disabled="creating"
                                        class="w-full h-11 rounded-md
                                               border border-gray-300
                                               bg-white pl-10 pr-3 text-sm
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
                            </div>

                            <div>
                                <label
                                    class="block mb-2 text-sm
                                           font-semibold
                                           text-gray-700"
                                >
                                    Priority <span class="text-red-600">*</span>
                                </label>

                                <div class="relative">
                                    <Flag class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-500" />
                                    <select
                                        v-model="form.priority_id"
                                        :disabled="creating"
                                        class="w-full h-11 rounded-md
                                               border border-gray-300
                                               bg-white pl-10 pr-3 text-sm
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

                        <!-- New documents are always Private; tags grant history visibility. -->
                        <div>
                            <div>
                                <label class="block mb-2 text-sm font-semibold text-gray-700">Tagged Offices</label>
                                <textarea
                                    readonly
                                    :value="selectedTagOfficeNames.join('\n')"
                                    rows="3"
                                    placeholder="Checked offices will appear here"
                                    class="mb-2 w-full resize-none rounded-md border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-700 outline-none"
                                    aria-label="Selected tagged offices"
                                ></textarea>
                                <div class="relative">
                                    <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-500" />
                                    <input
                                        v-model="officeTagSearch"
                                        type="search"
                                        :disabled="creating"
                                        placeholder="Search office name or code"
                                        class="w-full h-11 rounded-md border border-gray-300 bg-white pl-10 pr-3 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                                        aria-label="Search offices to tag"
                                    />
                                </div>
                                <div class="mt-2 max-h-40 overflow-y-auto rounded-md border border-gray-200 bg-white">
                                    <p v-if="!officeTagSearch.trim()" class="px-3 py-2 text-xs text-gray-500">
                                        Type an office name or code to search.
                                    </p>
                                    <p v-else-if="filteredTagOffices.length === 0" class="px-3 py-2 text-xs text-gray-500">
                                        No matching offices.
                                    </p>
                                    <label
                                        v-for="office in filteredTagOffices"
                                        :key="office.id"
                                        class="flex cursor-pointer items-center gap-2 px-3 py-2 text-sm hover:bg-gray-50"
                                    >
                                        <input
                                            type="checkbox"
                                            :checked="isTaggedOfficeSelected(office.id)"
                                            :disabled="creating"
                                            class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                            @change="toggleTaggedOffice(office.id)"
                                        />
                                        <span>{{ office.office_name }} ({{ office.office_code }})</span>
                                    </label>
                                </div>
                                <p class="mt-1 text-xs text-gray-500">Tagged offices can view the document history. They cannot release it or change its status unless it is currently assigned to their office.</p>
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

                                <DocTrackDatePicker
                                    v-model="form.document_date"
                                    required
                                    placement="prefer-above"
                                    aria-label="Document date"
                                    :disabled="creating || qrVerifying || !qrVerified"
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

                                <DocTrackDatePicker
                                    v-model="form.due_date"
                                    clearable
                                    placement="prefer-above"
                                    aria-label="Due date"
                                    :disabled="creating || qrVerifying || !qrVerified"
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

                    </fieldset>

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
                                :disabled="creating || qrVerifying || !canSubmitRegistration"
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
