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
