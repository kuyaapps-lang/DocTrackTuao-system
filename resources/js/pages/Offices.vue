<script setup>
import { computed, onMounted, ref } from 'vue'

import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card'

import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table'

import { Button } from '@/components/ui/button'
import { can } from '@/lib/auth'

const offices = ref([])
const loading = ref(true)
const error = ref('')

const canManageMasterData = computed(() => {
    return can('master_data.manage')
})

const fetchOffices = async () => {
    loading.value = true
    error.value = ''

    try {
        const token = localStorage.getItem('auth_token')

        const response = await fetch('/api/offices', {
            headers: {
                Accept: 'application/json',
                Authorization: `Bearer ${token}`,
            },
        })

        if (!response.ok) {
            throw new Error('Unable to load offices.')
        }

        offices.value = await response.json()
    } catch (err) {
        error.value = err.message || 'Unable to load offices.'
    } finally {
        loading.value = false
    }
}

onMounted(() => {
    fetchOffices()
})
</script>

<template>
    <div class="min-h-screen bg-gray-100">

        <!-- Header -->
        <div class="bg-white border-b px-6 py-4">
            <div class="flex items-center justify-between">

                <div>
                    <h1 class="text-2xl font-bold text-gray-800">
                        Office Management
                    </h1>

                    <p class="text-sm text-gray-500 mt-1">
                        Manage offices and their departments
                    </p>
                </div>

                <Button
                    v-if="canManageMasterData"
                >
                    Add Office
                </Button>

            </div>
        </div>

        <!-- Main Content -->
        <div class="p-6">

            <Card>

                <CardHeader>
                    <CardTitle>
                        Offices
                    </CardTitle>
                </CardHeader>

                <CardContent>

                    <!-- Loading -->
                    <div
                        v-if="loading"
                        class="py-8 text-center text-gray-500"
                    >
                        Loading offices...
                    </div>

                    <!-- Error -->
                    <div
                        v-else-if="error"
                        class="py-8 text-center text-red-500"
                    >
                        {{ error }}
                    </div>

                    <!-- No offices -->
                    <div
                        v-else-if="offices.length === 0"
                        class="py-8 text-center text-gray-500"
                    >
                        No offices found.
                    </div>

                    <!-- Office Table -->
                    <Table v-else>

                        <TableHeader>
                            <TableRow>

                                <TableHead>
                                    Office Code
                                </TableHead>

                                <TableHead>
                                    Office Name
                                </TableHead>

                                <TableHead>
                                    Department
                                </TableHead>

                                <TableHead>
                                    Description
                                </TableHead>

                                <TableHead
                                    v-if="canManageMasterData"
                                >
                                    Actions
                                </TableHead>

                            </TableRow>
                        </TableHeader>

                        <TableBody>

                            <TableRow
                                v-for="office in offices"
                                :key="office.id"
                            >

                                <TableCell class="font-medium">
                                    {{ office.office_code }}
                                </TableCell>

                                <TableCell>
                                    {{ office.office_name }}
                                </TableCell>

                                <TableCell>
                                    {{ office.department?.department_name || 'N/A' }}
                                </TableCell>

                                <TableCell>
                                    {{ office.description || 'N/A' }}
                                </TableCell>

                                <TableCell
                                    v-if="canManageMasterData"
                                >
                                    <div class="flex gap-2">

                                        <Button
                                            variant="outline"
                                            size="sm"
                                        >
                                            Edit
                                        </Button>

                                        <Button
                                            variant="destructive"
                                            size="sm"
                                        >
                                            Delete
                                        </Button>

                                    </div>
                                </TableCell>

                            </TableRow>

                        </TableBody>

                    </Table>

                </CardContent>

            </Card>

        </div>

    </div>
</template>