export const loginRouteFor = (to) => {
    if (to.name === 'qr-document-registration') {
        return {
            path: '/login',
            query: {
                redirect: to.fullPath,
            },
        }
    }

    return {
        path: '/login',
    }
}

export const resolveAuthenticationNavigation = async (
    to,
    {
        getToken,
        ensureCurrentUser,
        can,
    }
) => {
    const permission = to.meta?.permission
    const authenticated =
        to.meta?.authenticated === true ||
        Boolean(permission)
    const isPasswordChangeRoute =
        to.meta?.passwordChange === true ||
        to.path === '/change-password'

    const isLoginRoute = to.path === '/login'

    // A valid session should never leave an already-authenticated user on the
    // login screen. Public tracking/QR routes remain accessible anonymously.
    if (isLoginRoute && getToken()) {
        try {
            const user = await ensureCurrentUser()

            if (user?.must_change_password) {
                return { path: '/change-password' }
            }

            const redirect = typeof to.query?.redirect === 'string'
                ? to.query.redirect
                : ''

            if (redirect.startsWith('/') && !redirect.startsWith('//')) {
                return { path: redirect }
            }

            return { path: '/dashboard' }
        } catch {
            // ensureCurrentUser clears an expired/401 token. Temporary
            // verification failures stay on the login page so the user can
            // retry without losing a still-present session.
            return true
        }
    }

    if (!authenticated || to.meta?.public) {
        return true
    }

    if (!getToken()) {
        return loginRouteFor(to)
    }

    try {
        const user = await ensureCurrentUser()

        if (
            user?.must_change_password &&
            !isPasswordChangeRoute
        ) {
            return {
                path: '/change-password',
            }
        }
    } catch {
        if (!getToken()) {
            return loginRouteFor(to)
        }

        return true
    }

    if (isPasswordChangeRoute) {
        return true
    }

    if (permission && !can(permission)) {
        return {
            path: '/dashboard',
            query: {
                forbidden: '1',
            },
        }
    }

    return true
}
