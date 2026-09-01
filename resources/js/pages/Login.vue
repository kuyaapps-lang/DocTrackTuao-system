<script setup>
import { ref } from 'vue'
import {
    useRoute,
    useRouter,
} from 'vue-router'

import {
    Card,
    CardContent,
} from '@/components/ui/card'

import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'

import {
    Eye,
    EyeOff,
} from 'lucide-vue-next'

import logo from '@/assets/tuao-logo.png'
import { loginErrorMessage } from '@/lib/login'

const email = ref('')
const password = ref('')
const showPassword = ref(false)

const route = useRoute()
const router = useRouter()

const loading = ref(false)
const error = ref('')
const success = ref('')

/*
|--------------------------------------------------------------------------
| Safe Redirect
|--------------------------------------------------------------------------
|
| Used when an unauthenticated user scans an UNUSED QR.
|
| Example:
|
| /login?redirect=/register-document/ABCDE-1234567
|
*/

const getRedirectPath = () => {
    const redirect =
        typeof route.query.redirect === 'string'
            ? route.query.redirect
            : ''

    /*
    |--------------------------------------------------------------------------
    | Only permit local application paths
    |--------------------------------------------------------------------------
    */

    if (
        redirect.startsWith('/') &&
        !redirect.startsWith('//')
    ) {
        return redirect
    }

    return '/dashboard'
}

/*
|--------------------------------------------------------------------------
| Login
|--------------------------------------------------------------------------
*/

const login = async () => {
    error.value = ''
    success.value = ''

    if (!email.value || !password.value) {
        error.value =
            'Please enter your email and password.'

        return
    }

    loading.value = true

    try {
        const response = await fetch(
            '/api/login',
            {
                method: 'POST',

                headers: {
                    'Content-Type':
                        'application/json',

                    Accept:
                        'application/json',
                },

                body: JSON.stringify({
                    email:
                        email.value,

                    password:
                        password.value,
                }),
            }
        )

        const data =
            await response.json()

        if (!response.ok) {
            throw new Error(loginErrorMessage(
                response.status,
                data.message
            ))
        }

        /*
        |--------------------------------------------------------------------------
        | Save Authentication
        |--------------------------------------------------------------------------
        */

        localStorage.setItem(
            'auth_token',
            data.token
        )

        localStorage.setItem(
            'auth_user',
            JSON.stringify(
                data.user
            )
        )

        success.value =
            'Login successful!'

        /*
        |--------------------------------------------------------------------------
        | Continue Previous Action
        |--------------------------------------------------------------------------
        |
        | QR registration:
        |
        | QR
        | → Login
        | → Return to registration form
        |
        | Normal login:
        |
        | Login
        | → Dashboard
        |
        */

        const destination =
            getRedirectPath()

        router.replace(
            destination
        )

    } catch (err) {
        error.value =
            err.message ||
            'Unable to login.'
    } finally {
        loading.value = false
    }
}
</script>

<template>

<div
    class="relative min-h-screen overflow-hidden
    bg-gradient-to-br from-blue-950 via-blue-900 to-cyan-800
    flex flex-col items-center justify-center
    px-6 py-10"
>

    <!-- Animated Background -->
    <div class="absolute inset-0 overflow-hidden">

        <div
            class="absolute -top-48 -left-48 w-[600px] h-[600px]
            bg-cyan-400/20 blur-3xl
            animate-[spin_35s_linear_infinite]"
            style="border-radius:42% 58% 70% 30% / 45% 45% 55% 55%;"
        ></div>

        <div
            class="absolute bottom-[-250px] right-[-180px]
            w-[650px] h-[650px]
            bg-blue-400/20 blur-3xl
            animate-[spin_45s_linear_infinite_reverse]"
            style="border-radius:61% 39% 33% 67% / 70% 50% 50% 30%;"
        ></div>

        <div
            class="absolute top-1/3 left-1/2
            w-[300px] h-[300px]
            bg-white/10 blur-3xl
            animate-pulse
            rounded-full"
        ></div>

    </div>

    <!-- Watermark -->
    <img
        :src="logo"
        class="absolute w-[780px] opacity-[0.04] pointer-events-none"
        alt="Watermark"
    />

    <!-- Header -->
    <div
        class="relative z-10
               flex flex-col items-center
               text-center"
    >

        <!-- Logo -->
        <div
            class="backdrop-blur-md bg-white/10
            border border-white/30
            rounded-full
            p-2
            shadow-2xl"
        >
            <img
                :src="logo"
                alt="Municipality Logo"
                class="w-40 h-40 object-contain"
            />
        </div>

        <!-- Title -->
        <h1
            class="mt-3 text-5xl md:text-5xl
            font-black text-white tracking-wide"
        >
            Document Tracking System
        </h1>

        <!-- Subtitle -->
        <p
            class="mt-3 text-2xl md:text-3xl
            text-cyan-100 font-semibold"
        >
            Local Government Unit of Tuao
        </p>

    </div>

    <!-- Login Card -->
    <Card
        class="relative z-10
        mt-3
        w-full max-w-md
        rounded-4xl
        border border-white/30
        bg-white/85
        backdrop-blur-xl
        shadow-2xl"
    >

        <CardContent class="p-6">

            <!-- Login Header -->
            <div class="text-center mb-5">

                <h2
                    class="text-3xl font-black text-gray-800"
                >
                    Welcome Back
                </h2>

                <p class="mt-2 text-gray-500">
                    Please login to continue
                </p>

            </div>

            <!-- Login Form -->
            <form
                class="space-y-2"
                @submit.prevent="login"
            >

                <!-- Email -->
                <div>

                    <label
                        class="block mb-1
                               text-sm font-semibold
                               text-gray-700"
                    >
                        Email Address
                    </label>

                    <Input
                        v-model="email"
                        type="email"
                        placeholder="Enter your email"
                        class="h-12 rounded-xl"
                    />

                </div>

                <!-- Password -->
                <div>

                    <label
                        class="block mb-1
                               text-sm font-semibold
                               text-gray-800"
                    >
                        Password
                    </label>

                        <div class="relative">

                            <Input
                                v-model="password"
                                :type="showPassword ? 'text' : 'password'"
                                placeholder="Enter your password"
                                class="h-12 rounded-xl pr-12"
                            />

                            <button
                                type="button"
                                class="absolute right-4 top-1/2
                                    -translate-y-1/2
                                    text-gray-500
                                    hover:text-gray-800
                                    focus:outline-none"
                                @click="showPassword = !showPassword"
                                :aria-label="
                                    showPassword
                                        ? 'Hide password'
                                        : 'Show password'
                                "
                            >

                                <EyeOff
                                    v-if="showPassword"
                                    class="h-5 w-5"
                                />

                                <Eye
                                    v-else
                                    class="h-5 w-5"
                                />

                            </button>

                        </div>

                </div>

                <!-- Error -->
                <div
                    v-if="error"
                    class="text-sm text-red-600
                           font-semibold"
                >
                    {{ error }}
                </div>

                <!-- Success -->
                <div
                    v-if="success"
                    class="text-sm text-green-600
                           font-semibold"
                >
                    {{ success }}
                </div>

                <!-- Login Button -->
                <Button
                    type="submit"
                    :disabled="loading"
                    class="w-full h-12 rounded-xl
                    text-base font-bold
                    bg-gradient-to-r
                    from-cyan-600 to-blue-700
                    hover:from-cyan-700
                    hover:to-blue-800"
                >
                    {{
                        loading
                            ? 'Logging in...'
                            : 'Login'
                    }}
                </Button>

                <!-- Forgot Password -->
                <div class="flex justify-end">

                    <a
                        href="#"
                        class="text-sm text-cyan-700
                        hover:text-cyan-900
                        hover:underline"
                    >
                        Forgot Password?
                    </a>

                </div>

            </form>

        </CardContent>

    </Card>

    <!-- Footer -->
    <div
        class="relative z-10
        mt-5
        text-center
        text-sm
        text-blue-100/70"
    >
        © 2026 Municipality of Tuao
        • Document Tracking System
    </div>

</div>

</template>