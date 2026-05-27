<script setup>
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card'

import { Button } from '@/components/ui/button'

import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table'
</script>

<template>

    <div class="min-h-screen bg-gray-100">

        <!-- Top Navbar -->
        <div class="bg-white border-b px-6 py-4 flex justify-between items-center">

            <h1 class="text-2xl font-bold">
                Document Tracking System
            </h1>

            <Button>
                Logout
            </Button>

        </div>

        <!-- Main Content -->
        <div class="p-6">

            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">

                <Card>
                    <CardHeader>
                        <CardTitle>Total Documents</CardTitle>
                    </CardHeader>

                    <CardContent>
                        <p class="text-3xl font-bold">
                            120
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Pending</CardTitle>
                    </CardHeader>

                    <CardContent>
                        <p class="text-3xl font-bold text-yellow-500">
                            30
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Approved</CardTitle>
                    </CardHeader>

                    <CardContent>
                        <p class="text-3xl font-bold text-green-500">
                            70
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Released</CardTitle>
                    </CardHeader>

                    <CardContent>
                        <p class="text-3xl font-bold text-blue-500">
                            20
                        </p>
                    </CardContent>
                </Card>

            </div>

            <!-- Recent Documents -->
            <Card>

                <CardHeader>
                    <CardTitle>
                        Recent Documents
                    </CardTitle>
                </CardHeader>

                <CardContent>

                    <Table>

                        <TableHeader>

                            <TableRow>
                                <TableHead>Tracking No</TableHead>
                                <TableHead>Title</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Date</TableHead>
                            </TableRow>

                        </TableHeader>

                        <TableBody>

                            <TableRow>
                                <TableCell>DOC-001</TableCell>
                                <TableCell>Budget Proposal</TableCell>
                                <TableCell>Pending</TableCell>
                                <TableCell>May 26, 2026</TableCell>
                            </TableRow>

                            <TableRow>
                                <TableCell>DOC-002</TableCell>
                                <TableCell>Office Memo</TableCell>
                                <TableCell>Approved</TableCell>
                                <TableCell>May 26, 2026</TableCell>
                            </TableRow>

                        </TableBody>

                    </Table>

                </CardContent>

            </Card>

        </div>

    </div>

</template>