export const navigationItems = [
    {
        key: 'dashboard',
        label: 'Dashboard',
        path: '/dashboard',
        permission: null,
        group: null,
    },
    {
        key: 'documents',
        label: 'Documents',
        path: '/documents',
        permission: 'documents.view',
        group: null,
    },
    {
        key: 'qr-codes',
        label: 'QR Codes',
        path: '/qr-codes',
        permission: 'qr.manage',
        group: null,
    },
    {
        key: 'master-data',
        label: 'Master Data',
        path: null,
        permission: null,
        group: null,
        children: [
            {
                key: 'offices',
                label: 'Offices',
                path: '/offices',
                permission: 'master_data.view',
                group: 'master-data',
            },
            {
                key: 'document-types',
                label: 'Document Types',
                path: '/document-types',
                permission: 'master_data.view',
                group: 'master-data',
            },
        ],
    },
    {
        key: 'users',
        label: 'Users',
        path: '/users',
        permission: 'users.manage',
        group: null,
    },
    {
        key: 'audit',
        label: 'Audit',
        path: '/audit',
        permission: 'audit.view',
        group: null,
    },
]

const hasPermission = (permissionNames, permission) => {
    return !permission || permissionNames.has(permission)
}

export const visibleNavigation = (permissionNames = []) => {
    const permissions = new Set(permissionNames)

    return navigationItems.flatMap(item => {
        if (item.children) {
            const children = item.children.filter(child => {
                return hasPermission(permissions, child.permission)
            })

            return children.length > 0
                ? [{ ...item, children }]
                : []
        }

        return hasPermission(permissions, item.permission)
            ? [item]
            : []
    })
}

export const resolveActiveNavigationKey = (routePath) => {
    if (typeof routePath !== 'string') {
        return null
    }

    const path = routePath
        .split(/[?#]/, 1)[0]
        .replace(/\/+$/, '') || '/'

    if (
        path === '/documents' ||
        path.startsWith('/documents/') ||
        path.startsWith('/register-document/')
    ) {
        return 'documents'
    }

    for (const item of navigationItems) {
        if (item.path === path) {
            return item.key
        }

        const child = item.children?.find(entry => {
            return entry.path === path
        })

        if (child) {
            return child.key
        }
    }

    return null
}
