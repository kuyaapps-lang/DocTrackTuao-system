<script setup>
import { ref } from 'vue'

import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'

import logo from '@/assets/tuao-logo.png'

const email = ref('')
const password = ref('')
</script>

<template>

<div class="min-h-screen flex flex-col md:grid md:grid-cols-2">

    <!-- LEFT SIDE -->
    <div
        class="relative overflow-hidden flex flex-col justify-center items-center
        bg-gradient-to-br from-blue-950 via-blue-900 to-cyan-800
        text-white p-10 min-h-[40vh] md:min-h-screen"
    >

        <!-- Animated Gradient Overlay -->
        <div
            class="absolute inset-0 bg-gradient-to-tr from-cyan-500/10 via-transparent to-blue-300/10 animate-pulse"
        ></div>

        <!-- Wavy Blob Background -->
        <div class="absolute inset-0 overflow-hidden">

            <!-- Blob 1 -->
            <div
                class="absolute -top-40 -left-40 w-[500px] h-[500px]
                bg-cyan-400/20 blur-3xl
                animate-[spin_30s_linear_infinite]"
                style="border-radius: 42% 58% 70% 30% / 45% 45% 55% 55%;"
            ></div>

            <!-- Blob 2 -->
            <div
                class="absolute bottom-[-200px] right-[-150px] w-[550px] h-[550px]
                bg-blue-300/20 blur-3xl
                animate-[spin_40s_linear_infinite_reverse]"
                style="border-radius: 61% 39% 33% 67% / 70% 50% 50% 30%;"
            ></div>

            <!-- Blob 3 -->
            <div
                class="absolute top-[35%] left-[60%] w-[250px] h-[250px]
                bg-white/10 blur-2xl animate-pulse"
                style="border-radius: 57% 43% 34% 66% / 55% 48% 52% 45%;"
            ></div>

        </div>

        <!-- Watermark -->
        <img
            :src="logo"
            alt="Watermark"
            class="absolute w-[500px] opacity-[0.04]"
        />

        <!-- Content -->
        <div class="relative z-10 flex flex-col items-center">

            <!-- Logo -->
            <div
                class="backdrop-blur-md bg-white/10 p-5 rounded-full
                border border-white/20 shadow-2xl mb-6"
            >
                <img
                    :src="logo"
                    alt="Municipality Logo"
                    class="w-28 h-28 object-cover"
                />
            </div>

            <!-- Title -->
            <h1
                class="text-5xl md:text-6xl font-black tracking-wide mb-3 text-center"
            >
                Document Tracking System
            </h1>

            <!-- Subtitle -->
            <p
                class="text-xl md:text-2xl font-semibold text-cyan-100 mb-5 text-center"
            >
                Local Government Unit of Tuao
            </p>

            <!-- Description -->
            <p
                class="text-center text-blue-100/90
                max-w-md leading-relaxed text-base md:text-lg"
            >
                Secure and efficient document tracking system
                for monitoring, routing, and managing official documents.
            </p>

        </div>

    </div>

    <!-- RIGHT SIDE -->
    <div
        class="relative flex items-center justify-center
        bg-gray-100 p-6 min-h-[60vh] md:min-h-screen overflow-hidden"
    >

        <!-- Background Glow -->
        <div
            class="absolute w-[400px] h-[400px]
            bg-cyan-200/40 blur-3xl rounded-full"
        ></div>

        <!-- Login Card -->
        <Card
            class="relative z-10 w-full max-w-md
            backdrop-blur-xl bg-white/80
            shadow-2xl border border-white/40
            rounded-3xl"
        >

            <CardContent class="p-8">

                <!-- Header -->
                <div class="mb-8 text-center">

                    <h2
                        class="text-3xl md:text-4xl font-black text-gray-800"
                    >
                        Welcome Back
                    </h2>

                    <p
                        class="text-gray-500 mt-3"
                    >
                        Please Login to continue 
                
                    </p>

                </div>

                <!-- Form -->
                <form class="space-y-5">

                    <!-- Email -->
                    <div>

                        <label
                            class="text-sm font-semibold text-gray-700 mb-2 block"
                        >
                            Email Address
                        </label>

                        <Input
                            v-model="email"
                            type="email"
                            placeholder="Enter your email"
                            class="h-12 rounded-xl border-gray-300"
                        />

                    </div>

                    <!-- Password -->
                    <div>

                        <label
                            class="text-sm font-semibold text-gray-700 mb-2 block"
                        >
                            Password
                        </label>

                        <Input
                            v-model="password"
                            type="password"
                            placeholder="Enter your password"
                            class="h-12 rounded-xl border-gray-300"
                        />

                    </div>

                    <!-- Forgot Password -->
                    <div class="flex justify-end">

                        <a
                            href="#"
                            class="text-sm text-cyan-700 hover:text-cyan-900 transition"
                        >
                            Forgot password?
                        </a>

                    </div>

                    <!-- Button -->
                    <Button
                        class="w-full h-12 rounded-xl text-base font-bold
                        bg-gradient-to-r from-cyan-600 to-blue-700
                        hover:from-cyan-700 hover:to-blue-800"
                    >
                        Login
                    </Button>

                </form>

            </CardContent>

        </Card>

    </div>

</div>

</template>