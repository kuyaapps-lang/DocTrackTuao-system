import {
    createRouter,
    createWebHistory,
} from 'vue-router'

import Login from '../pages/Login.vue'
import Dashboard from '../pages/Dashboard.vue'
import Documents from '../pages/Documents.vue'
import DocumentDetails from '../pages/DocumentDetails.vue'
import DocumentTracking from '../pages/DocumentTracking.vue'
import QrResolver from '../pages/QrResolver.vue'
import QrCodes from '../pages/QrCodes.vue'
import Offices from '../pages/Offices.vue'
import DocumentTypes from '../pages/DocumentTypes.vue'
import Users from '../pages/Users.vue'
import AuditLogs from '../pages/AuditLogs.vue'

import {
    can,
    ensureCurrentUser,
    getToken,
} from '../lib/auth'

const routes = [
    {
        path: '/',
        redirect: '/login',
    },

    /*
    |--------------------------------------------------------------------------
    | Public Routes
    |--------------------------------------------------------------------------
    */

    {
        path: '/login',
        component: Login,
        meta: {
            public: true,
        },
    },

    {
        path: '/q/:token',
        name: 'qr-resolver',
        component: QrResolver,
        meta: {
            public: true,
        },
    },

    {
        path: '/track/:trackingNo',
        component: DocumentTracking,
        meta: {
            public: true,
        },
    },

    /*
    |--------------------------------------------------------------------------
    | Authenticated Application Routes
    |--------------------------------------------------------------------------
    */

    {
        path: '/register-document/:qrToken',
        name: 'qr-document-registration',
        component: Documents,
        meta: {
            permission: 'documents.create',
        },
    },

    {
        path: '/dashboard',
        component: Dashboard,
        meta: {
            authenticated: true,
        },
    },

    {
        path: '/documents',
        component: Documents,
        meta: {
            permission: 'documents.view',
        },
    },

    {
        path: '/documents/:id',
        component: DocumentDetails,
        meta: {
            permission: 'documents.view',
        },
    },

    {
        path: '/qr-codes',
        component: QrCodes,
        meta: {
            permission: 'qr.view',
        },
    },

    {
        path: '/offices',
        component: Offices,
        meta: {
            permission: 'master_data.view',
        },
    },

    {
        path: '/document-types',
        component: DocumentTypes,
        meta: {
            permission: 'master_data.view',
        },
    },

    {
        path: '/users',
        component: Users,
        meta: {
            permission: 'users.manage',
        },
    },

    {
        path: '/audit',
        component: AuditLogs,
        meta: {
            permission: 'audit.view',
        },
    },
]

const router = createRouter({
    history: createWebHistory(),
    routes,
})

/*
|--------------------------------------------------------------------------
| Permission-Aware Navigation Guard
|--------------------------------------------------------------------------
|
| Backend authorization remains authoritative. This guard improves the UI by
| stopping an already-authenticated user from opening a page that their role
| does not permit.
|
| If no token is present, the guard deliberately leaves the existing login / QR
| redirect behavior untouched. We will integrate the login page more tightly in
| the next frontend step after inspecting its current QR redirect logic.
|
*/

router.beforeEach(async (to) => {
    const permission = to.meta?.permission
    const authenticated =
        to.meta?.authenticated === true ||
        Boolean(permission)

    if (!authenticated || to.meta?.public) {
        return true
    }

    if (!getToken()) {
        return true
    }

    try {
        await ensureCurrentUser()
    } catch {
        // API authentication/error handling on the destination page remains
        // the fallback. Do not disturb the existing login/QR workflow here.
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
})

export default router
