import { computed, ref } from 'vue'

const currentUser = ref(null)
const authLoading = ref(false)
const authError = ref('')

let activeRequest = null
let resolvedToken = null

const AUTHENTICATION_REQUIRED_MESSAGE =
    'Authentication is required.'
const AUTHENTICATION_TEMPORARY_ERROR_MESSAGE =
    'Unable to verify authentication right now.'

export const getToken = () => {
    return localStorage.getItem('auth_token')
}

export const clearCurrentUser = () => {
    currentUser.value = null
    authError.value = ''
    authLoading.value = false
    activeRequest = null
    resolvedToken = null
}

export const ensureCurrentUser = async (force = false) => {
    const token = getToken()

    if (!token) {
        clearCurrentUser()
        return null
    }

    if (
        !force &&
        currentUser.value &&
        resolvedToken === token
    ) {
        return currentUser.value
    }

    if (!force && activeRequest) {
        return activeRequest
    }

    authLoading.value = true
    authError.value = ''

    activeRequest = (async () => {
        try {
            const response = await fetch('/api/user', {
                headers: {
                    Accept: 'application/json',
                    Authorization: `Bearer ${token}`,
                },
            })

            if (response.status === 401) {
                localStorage.removeItem('auth_token')
                localStorage.removeItem('auth_user')
                clearCurrentUser()

                throw new Error(
                    AUTHENTICATION_REQUIRED_MESSAGE
                )
            }

            if (!response.ok) {
                throw new Error(
                    AUTHENTICATION_TEMPORARY_ERROR_MESSAGE
                )
            }

            const data = await response.json()

            currentUser.value = data
            resolvedToken = token

            return data
        } catch (error) {
            const message =
                error?.message === AUTHENTICATION_REQUIRED_MESSAGE
                    ? AUTHENTICATION_REQUIRED_MESSAGE
                    : AUTHENTICATION_TEMPORARY_ERROR_MESSAGE

            authError.value = message

            throw new Error(message)
        } finally {
            authLoading.value = false
            activeRequest = null
        }
    })()

    return activeRequest
}

export const can = (permission) => {
    if (!permission) {
        return true
    }

    const permissions =
        currentUser.value?.permissions || []

    return permissions.includes(permission)
}

export const canAny = (permissions = []) => {
    return permissions.some(
        permission => can(permission)
    )
}

export const useAuth = () => {
    const permissions = computed(() => {
        return currentUser.value?.permissions || []
    })

    const roleName = computed(() => {
        return currentUser.value?.role?.name || null
    })

    const office = computed(() => {
        return currentUser.value?.office || null
    })

    return {
        currentUser,
        permissions,
        roleName,
        office,
        authLoading,
        authError,
        getToken,
        ensureCurrentUser,
        clearCurrentUser,
        can,
        canAny,
    }
}
