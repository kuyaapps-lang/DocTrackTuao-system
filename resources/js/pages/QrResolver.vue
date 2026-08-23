<script setup>
import { onMounted, ref } from 'vue'
import {
    useRoute,
    useRouter,
} from 'vue-router'

import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card'

import { Button } from '@/components/ui/button'

const route = useRoute()
const router = useRouter()

const loading = ref(true)
const error = ref('')
const state = ref('')

const qrToken = ref(
    String(route.params.token || '')
)

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

const getToken = () => {
    return localStorage.getItem('auth_token')
}

/*
|--------------------------------------------------------------------------
| Resolve QR
|--------------------------------------------------------------------------
*/

const resolveQr = async () => {
    loading.value = true
    error.value = ''
    state.value = ''

    if (!qrToken.value) {
        error.value = 'QR token is missing.'
        loading.value = false
        return
    }

    try {
        const response = await fetch(
            `/api/q/${encodeURIComponent(qrToken.value)}`,
            {
                headers: {
                    Accept: 'application/json',
                },
            }
        )

        let data = {}

        try {
            data = await response.json()
        } catch {
            data = {}
        }

        /*
        |--------------------------------------------------------------------------
        | Unused QR
        |--------------------------------------------------------------------------
        |
        | Registration requires authentication.
        |
        */

        if (
            response.ok &&
            data.state === 'unused'
        ) {
            state.value = 'unused'

            const registrationPath =
                data.registration_path ||
                `/register-document/${qrToken.value}`

            /*
            |--------------------------------------------------------------------------
            | Already logged in
            |--------------------------------------------------------------------------
            */

            if (getToken()) {
                router.replace(
                    registrationPath
                )

                return
            }

            /*
            |--------------------------------------------------------------------------
            | Not logged in
            |--------------------------------------------------------------------------
            |
            | Send to login but preserve where the user needs to return.
            |
            */

            router.replace({
                path: '/login',

                query: {
                    redirect:
                        registrationPath,
                },
            })

            return
        }

        /*
        |--------------------------------------------------------------------------
        | Registered QR
        |--------------------------------------------------------------------------
        |
        | Public tracking does not require login.
        |
        */

        if (
            response.ok &&
            data.state === 'registered'
        ) {
            state.value = 'registered'

            if (data.tracking_path) {
                router.replace(
                    data.tracking_path
                )

                return
            }

            throw new Error(
                'The QR code is registered but its tracking record could not be opened.'
            )
        }

        /*
        |--------------------------------------------------------------------------
        | Voided QR
        |--------------------------------------------------------------------------
        */

        if (
            data.state === 'void' ||
            response.status === 410
        ) {
            state.value = 'void'

            error.value =
                data.message ||
                'This QR code has been voided and is no longer valid.'

            return
        }

        /*
        |--------------------------------------------------------------------------
        | Invalid QR
        |--------------------------------------------------------------------------
        */

        state.value = 'invalid'

        error.value =
            data.message ||
            'This QR code is invalid or does not exist.'

    } catch (err) {
        error.value =
            err.message ||
            'Unable to resolve this QR code.'
    } finally {
        loading.value = false
    }
}

/*
|--------------------------------------------------------------------------
| Retry
|--------------------------------------------------------------------------
*/

const retry = () => {
    resolveQr()
}

/*
|--------------------------------------------------------------------------
| Page Load
|--------------------------------------------------------------------------
*/

onMounted(() => {
    resolveQr()
})
</script>

<template>
    <div
        class="min-h-screen bg-gray-100
               flex items-center justify-center
               px-4 py-10"
    >
        <Card
            class="w-full max-w-lg bg-white"
        >
            <CardHeader
                class="text-center"
            >
                <CardTitle
                    class="text-2xl"
                >
                    Document QR Code
                </CardTitle>

                <p
                    class="mt-2 text-sm text-gray-500"
                >
                    LGU Tuao Document Tracking System
                </p>
            </CardHeader>

            <CardContent>

                <!-- Loading -->
                <div
                    v-if="loading"
                    class="py-10 text-center"
                >
                    <div
                        class="mx-auto mb-4 h-10 w-10
                               animate-spin rounded-full
                               border-4 border-gray-200
                               border-t-blue-600"
                    ></div>

                    <p
                        class="font-semibold text-gray-700"
                    >
                        Checking QR code...
                    </p>

                    <p
                        class="mt-2 font-mono
                               text-sm text-gray-500"
                    >
                        {{ qrToken }}
                    </p>
                </div>

                <!-- Error -->
                <div
                    v-else-if="error"
                    class="space-y-5"
                >
                    <div
                        class="rounded-lg border
                               border-red-200
                               bg-red-50 p-5"
                    >
                        <p
                            class="font-semibold
                                   text-red-700"
                        >
                            {{
                                state === 'void'
                                    ? 'QR Code Voided'
                                    : 'Unable to Open QR Code'
                            }}
                        </p>

                        <p
                            class="mt-2 text-sm
                                   text-red-600"
                        >
                            {{ error }}
                        </p>

                        <p
                            class="mt-3 font-mono
                                   text-xs text-red-500"
                        >
                            {{ qrToken }}
                        </p>
                    </div>

                    <div
                        class="flex justify-center"
                    >
                        <Button
                            v-if="state !== 'void'"
                            type="button"
                            variant="outline"
                            @click="retry"
                        >
                            Try Again
                        </Button>
                    </div>
                </div>

            </CardContent>
        </Card>
    </div>
</template>