import {
    createRouter,
    createWebHistory,
} from 'vue-router'

import Login from '../pages/Login.vue'
import ChangePassword from '../pages/ChangePassword.vue'
import Profile from '../pages/Profile.vue'
import Dashboard from '../pages/Dashboard.vue'
import Documents from '../pages/Documents.vue'
import ChangeStatus from '../pages/ChangeStatus.vue'
import ReleaseDocument from '../pages/ReleaseDocument.vue'
import ReceivedDocument from '../pages/ReceivedDocument.vue'
import DocumentDetails from '../pages/DocumentDetails.vue'
import DocumentTracking from '../pages/DocumentTracking.vue'
import QrResolver from '../pages/QrResolver.vue'
import QrCodes from '../pages/QrCodes.vue'
import Offices from '../pages/Offices.vue'
import DocumentTypes from '../pages/DocumentTypes.vue'
import Users from '../pages/Users.vue'
import AuditLogs from '../pages/AuditLogs.vue'
import AccomplishmentReport from '../pages/AccomplishmentReport.vue'
import Notifications from '../pages/Notifications.vue'
import AppShell from '../layouts/AppShell.vue'

import {
    can,
    ensureCurrentUser,
    getToken,
} from '../lib/auth'
import { resolveAuthenticationNavigation } from '../lib/auth-guard'

const routes = [
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
            title: 'Login',
            navKey: null,
        },
    },

    {
        path: '/q/:token',
        name: 'qr-resolver',
        component: QrResolver,
        meta: {
            public: true,
            title: 'QR Code',
            navKey: null,
        },
    },

    {
        path: '/track/:trackingNo',
        component: DocumentTracking,
        meta: {
            public: true,
            title: 'Document Tracking',
            navKey: null,
        },
    },

    /*
    |--------------------------------------------------------------------------
    | Authenticated Application Routes
    |--------------------------------------------------------------------------
    */

    {
        path: '/',
        component: AppShell,
        children: [
            {
                path: '',
                redirect: '/login',
            },
            {
                path: 'register-document/:qrToken',
                name: 'qr-document-registration',
                component: Documents,
                meta: {
                    permission: 'documents.create',
                    title: 'Register Document',
                    navKey: 'documents',
                },
            },
            {
                path: 'dashboard',
                component: Dashboard,
                meta: {
                    authenticated: true,
                    title: 'Dashboard',
                    navKey: 'dashboard',
                },
            },
            {
                path: 'change-password',
                component: ChangePassword,
                meta: {
                    authenticated: true,
                    title: 'Change Password',
                    navKey: null,
                    passwordChange: true,
                },
            },
            {
                path: 'profile',
                component: Profile,
                meta: {
                    authenticated: true,
                    title: 'Profile',
                    navKey: null,
                },
            },
            {
                path: 'notifications',
                component: Notifications,
                meta: {
                    authenticated: true,
                    title: 'Notifications',
                    navKey: null,
                },
            },
            {
                path: 'documents',
                component: Documents,
                beforeEnter: (to) => {
                    if (['incoming', 'outgoing'].includes(to.query.view)) {
                        return true
                    }

                    return {
                        path: '/documents',
                        query: {
                            ...to.query,
                            view: 'outgoing',
                        },
                    }
                },
                meta: {
                    permission: 'documents.view',
                    title: 'Documents',
                    navKey: 'documents',
                },
            },
            {
                path: 'received-document',
                component: ReceivedDocument,
                meta: {
                    permission: 'documents.route',
                    title: 'Received Document',
                    navKey: 'received-document',
                },
            },
            {
                path: 'change-status',
                component: ChangeStatus,
                meta: {
                    permission: 'documents.process',
                    title: 'Change Status',
                    navKey: 'change-status',
                },
            },
            {
                path: 'release-document',
                component: ReleaseDocument,
                meta: {
                    permission: 'documents.route',
                    title: 'Release Document',
                    navKey: 'release-document',
                },
            },
            {
                path: 'document-inquiry',
                component: DocumentTracking,
                meta: {
                    permission: 'documents.view',
                    title: 'Document Inquiry / Status',
                    navKey: 'document-inquiry',
                },
            },
            {
                path: 'documents/:id',
                component: DocumentDetails,
                meta: {
                    permission: 'documents.view',
                    title: 'Document Details',
                    navKey: 'documents',
                },
            },
            {
                path: 'qr-codes',
                component: QrCodes,
                meta: {
                    permission: 'qr.request',
                    title: 'QR Codes',
                    navKey: 'qr-codes',
                },
            },
            {
                path: 'offices',
                component: Offices,
                meta: {
                    permission: 'master_data.view',
                    title: 'Offices',
                    navKey: 'offices',
                },
            },
            {
                path: 'document-types',
                component: DocumentTypes,
                meta: {
                    permission: 'master_data.view',
                    title: 'Document Types',
                    navKey: 'document-types',
                },
            },
            {
                path: 'users',
                component: Users,
                meta: {
                    permission: 'users.manage',
                    title: 'Users',
                    navKey: 'users',
                },
            },
            {
                path: 'reports/accomplishment',
                component: AccomplishmentReport,
                meta: {
                    permission: 'reports.view',
                    title: 'Accomplishment Report',
                    navKey: 'accomplishment-report',
                },
            },
            {
                path: 'audit',
                component: AuditLogs,
                meta: {
                    permission: 'audit.view',
                    title: 'Audit',
                    navKey: 'audit',
                },
            },
        ],
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
| QR registration preserves its safe local redirect through the existing login
| page. Other unauthenticated application routes return directly to login.
|
*/

router.beforeEach(async (to) => {
    return resolveAuthenticationNavigation(to, {
        getToken,
        ensureCurrentUser,
        can,
    })
})

export default router
