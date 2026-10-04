<script setup>
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import {
    Building2,
    KeyRound,
    Mail,
    ShieldCheck,
    UserRound,
} from 'lucide-vue-next'

import { Button } from '@/components/ui/button'
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card'
import { useAuth } from '@/lib/auth'

const { currentUser } = useAuth()

const userName = computed(() => currentUser.value?.name || 'Signed-in user')
const userInitials = computed(() => userName.value
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map(name => name[0])
    .join('')
    .toUpperCase() || 'U')

const profileFields = computed(() => [
    {
        label: 'Username',
        value: currentUser.value?.username || 'Not available',
        icon: UserRound,
    },
    {
        label: 'Email address',
        value: currentUser.value?.email || 'Not available',
        icon: Mail,
    },
    {
        label: 'Role',
        value: currentUser.value?.role?.name || 'Not assigned',
        icon: ShieldCheck,
    },
    {
        label: 'Office',
        value: currentUser.value?.office?.office_name || currentUser.value?.office?.name || 'Not assigned',
        icon: Building2,
    },
])
</script>

<template>
    <section class="min-h-screen bg-slate-100 p-4 sm:p-6" aria-labelledby="profile-heading">
        <div class="mx-auto max-w-3xl">
            <Card class="overflow-hidden border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <CardHeader class="border-b border-slate-200 dark:border-slate-700">
                    <div class="flex items-center gap-4">
                        <div class="flex size-14 shrink-0 items-center justify-center rounded-full bg-blue-900 text-lg font-bold text-white" aria-hidden="true">
                            {{ userInitials }}
                        </div>
                        <div class="min-w-0">
                            <CardTitle id="profile-heading" class="truncate text-xl text-blue-950 dark:text-slate-100">
                                {{ userName }}
                            </CardTitle>
                            <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">Your account details</p>
                        </div>
                    </div>
                </CardHeader>

                <CardContent class="p-0">
                    <dl class="divide-y divide-slate-200 dark:divide-slate-700">
                        <div v-for="field in profileFields" :key="field.label" class="flex items-center gap-3 px-5 py-4">
                            <component :is="field.icon" class="size-5 shrink-0 text-blue-800 dark:text-blue-300" aria-hidden="true" />
                            <div class="min-w-0">
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ field.label }}</dt>
                                <dd class="truncate text-sm font-medium text-slate-800 dark:text-slate-100">{{ field.value }}</dd>
                            </div>
                        </div>
                    </dl>

                    <div class="border-t border-slate-200 px-5 py-4 dark:border-slate-700">
                        <Button as-child size="sm" class="bg-blue-700 text-white hover:bg-blue-800">
                            <RouterLink to="/change-password">
                                <KeyRound class="size-4" aria-hidden="true" />
                                Change Password
                            </RouterLink>
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>
    </section>
</template>
