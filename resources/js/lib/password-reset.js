export const canResetUserPassword = (currentUser, targetUser) => {
    if (!currentUser || !targetUser) {
        return false
    }

    const permissions = currentUser.permissions || []

    return permissions.includes('users.manage') &&
        Number(currentUser.id) !== Number(targetUser.id)
}

export const firstValidationError = (data) => {
    if (!data?.errors) {
        return null
    }

    const first = Object.values(data.errors)[0]

    if (Array.isArray(first)) {
        return first[0]
    }

    return first || null
}

export const resetPasswordRequest = async ({
    fetcher = fetch,
    token,
    userId,
    password,
    passwordConfirmation,
}) => {
    const response = await fetcher(`/api/users/${userId}/reset-password`, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({
            password,
            password_confirmation: passwordConfirmation,
        }),
    })

    const data = await response.json()

    if (!response.ok) {
        throw new Error(
            firstValidationError(data) ||
            data.message ||
            'Unable to reset password.'
        )
    }

    return data
}

export const submitPasswordResetRequest = async ({
    fetcher = fetch,
    email,
    name,
    message,
}) => {
    const response = await fetcher('/api/password-reset-requests', {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            email,
            name: name || null,
            message: message || null,
        }),
    })

    const data = await response.json()

    if (!response.ok) {
        throw new Error(
            firstValidationError(data) ||
            data.message ||
            'Unable to submit password reset request.'
        )
    }

    return data
}

export const listPasswordResetRequests = async ({
    fetcher = fetch,
    token,
    status = 'pending',
} = {}) => {
    const response = await fetcher(
        `/api/password-reset-requests?status=${encodeURIComponent(status)}`,
        {
            headers: {
                Accept: 'application/json',
                Authorization: `Bearer ${token}`,
            },
        }
    )

    const data = await response.json()

    if (!response.ok) {
        throw new Error(
            firstValidationError(data) ||
            data.message ||
            'Unable to load password reset requests.'
        )
    }

    return Array.isArray(data.data)
        ? data.data
        : []
}

export const resolvePasswordResetRequest = async ({
    fetcher = fetch,
    token,
    requestId,
    password,
    passwordConfirmation,
    resolutionNote = null,
}) => {
    const response = await fetcher(
        `/api/password-reset-requests/${requestId}/resolve`,
        {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                Authorization: `Bearer ${token}`,
            },
            body: JSON.stringify({
                password,
                password_confirmation: passwordConfirmation,
                resolution_note: resolutionNote || null,
            }),
        }
    )

    const data = await response.json()

    if (!response.ok) {
        throw new Error(
            firstValidationError(data) ||
            data.message ||
            'Unable to resolve password reset request.'
        )
    }

    return data
}

export const rejectPasswordResetRequest = async ({
    fetcher = fetch,
    token,
    requestId,
    resolutionNote = null,
}) => {
    const response = await fetcher(
        `/api/password-reset-requests/${requestId}/reject`,
        {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                Authorization: `Bearer ${token}`,
            },
            body: JSON.stringify({
                resolution_note: resolutionNote || null,
            }),
        }
    )

    const data = await response.json()

    if (!response.ok) {
        throw new Error(
            firstValidationError(data) ||
            data.message ||
            'Unable to reject password reset request.'
        )
    }

    return data
}

export const validatePasswordChangeForm = ({
    currentPassword,
    password,
    passwordConfirmation,
}) => {
    if (!currentPassword) {
        return 'Current temporary password is required.'
    }

    if (!password) {
        return 'New password is required.'
    }

    if (password.length < 8) {
        return 'New password must be at least 8 characters.'
    }

    if (password !== passwordConfirmation) {
        return 'Password confirmation does not match.'
    }

    if (currentPassword === password) {
        return 'New password must be different from the temporary password.'
    }

    return ''
}

export const changePasswordRequest = async ({
    fetcher = fetch,
    token,
    currentPassword,
    password,
    passwordConfirmation,
}) => {
    const response = await fetcher('/api/me/password', {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({
            current_password: currentPassword,
            password,
            password_confirmation: passwordConfirmation,
        }),
    })

    const data = await response.json()

    if (!response.ok) {
        throw new Error(
            firstValidationError(data) ||
            data.message ||
            'Unable to change password.'
        )
    }

    return data
}
