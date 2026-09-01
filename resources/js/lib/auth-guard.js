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

    if (!authenticated || to.meta?.public) {
        return true
    }

    if (!getToken()) {
        return loginRouteFor(to)
    }

    try {
        await ensureCurrentUser()
    } catch {
        if (!getToken()) {
            return loginRouteFor(to)
        }

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
