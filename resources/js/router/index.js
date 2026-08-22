import { createRouter, createWebHistory } from 'vue-router'

import Login from '../pages/Login.vue'
import Dashboard from '../pages/Dashboard.vue'
import Documents from '../pages/Documents.vue'
import DocumentDetails from '../pages/DocumentDetails.vue'
import DocumentTracking from '../pages/DocumentTracking.vue'
import Offices from '../pages/Offices.vue'
import DocumentTypes from '../pages/DocumentTypes.vue'

const routes = [

    {
        path: '/',
        redirect: '/login',
    },

    {
        path: '/login',
        component: Login,
    },

    /*
    |--------------------------------------------------------------------------
    | Public Document Tracking
    |--------------------------------------------------------------------------
    */

    {
        path: '/track/:trackingNo',
        component: DocumentTracking,
    },

    /*
    |--------------------------------------------------------------------------
    | Internal Application
    |--------------------------------------------------------------------------
    */

    {
        path: '/dashboard',
        component: Dashboard,
    },

    {
        path: '/documents',
        component: Documents,
    },

    {
        path: '/documents/:id',
        component: DocumentDetails,
    },

    {
        path: '/offices',
        component: Offices,
    },

    {
        path: '/document-types',
        component: DocumentTypes,
    },

]

const router = createRouter({
    history: createWebHistory(),
    routes,
})

export default router