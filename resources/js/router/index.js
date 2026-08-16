import { createRouter, createWebHistory } from 'vue-router'

import Login from '../pages/Login.vue'
import Dashboard from '../pages/Dashboard.vue'
import Documents from '../pages/Documents.vue'
import Offices from '../pages/Offices.vue'

const routes = [

    {
        path: '/',
        redirect: '/login',
    },

    {
        path: '/login',
        component: Login,
    },

    {
        path: '/dashboard',
        component: Dashboard,
    },

    {
        path: '/documents',
        component: Documents,
    },

    {
        path: '/offices',
        component: Offices,
    },

]

const router = createRouter({
    history: createWebHistory(),
    routes,
})

export default router