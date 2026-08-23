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

import {
    Eye,
    EyeOff,
} from 'lucide-vue-next'

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'

import {
    useAuth,
} from '@/lib/auth'

const {
    currentUser,
    ensureCurrentUser,
    getToken,
} = useAuth()

const users = ref([])
const roles = ref([])
const offices = ref([])

const loading = ref(true)
const error = ref('')
const successMessage = ref('')

const showForm = ref(false)
const editingUser = ref(null)
const saving = ref(false)
const formError = ref('')

const showPassword = ref(false)
const showPasswordConfirmation = ref(false)

const form = ref({
    name: '',
    email: '',
    role_id: '',
    office_id: '',
    password: '',
    password_confirmation: '',
})

const isEditing = computed(() => {
    return Boolean(editingUser.value)
})

const isEditingSelf = computed(() => {
    if (!editingUser.value || !currentUser.value) {
        return false
    }

    return Number(editingUser.value.id) ===
        Number(currentUser.value.id)
})

const requestHeaders = (json = false) => {
    const headers = {
        Accept: 'application/json',
        Authorization: `Bearer ${getToken()}`,
    }

    if (json) {
        headers['Content-Type'] = 'application/json'
    }

    return headers
}

const fetchUsers = async () => {
    const response = await fetch('/api/users', {
        headers: requestHeaders(),
    })

    const data = await response.json()

    if (!response.ok) {
        throw new Error(
            data.message ||
            'Unable to load users.'
        )
    }

    users.value = Array.isArray(data)
        ? data
        : []
}

const fetchFormOptions = async () => {
    const response = await fetch(
        '/api/users/form-options',
        {
            headers: requestHeaders(),
        }
    )

    const data = await response.json()

    if (!response.ok) {
        throw new Error(
            data.message ||
            'Unable to load user form options.'
        )
    }

    roles.value = data.roles || []
    offices.value = data.offices || []
}

const loadPage = async () => {
    loading.value = true
    error.value = ''

    try {
        await ensureCurrentUser()

        await Promise.all([
            fetchUsers(),
            fetchFormOptions(),
        ])
    } catch (err) {
        error.value =
            err.message ||
            'Unable to load user management.'
    } finally {
        loading.value = false
    }
}

const resetForm = () => {
    form.value = {
        name: '',
        email: '',
        role_id: '',
        office_id: '',
        password: '',
        password_confirmation: '',
    }

    showPassword.value = false
    showPasswordConfirmation.value = false
    
    formError.value = ''
}

const openAddForm = () => {
    editingUser.value = null
    resetForm()
    successMessage.value = ''
    showForm.value = true
}

const openEditForm = (user) => {
    editingUser.value = user

    form.value = {
        name: user.name || '',
        email: user.email || '',
        role_id: user.role_id || '',
        office_id: user.office_id || '',
        password: '',
        password_confirmation: '',
    }

    formError.value = ''
    successMessage.value = ''
    showForm.value = true
}

const closeForm = () => {
    if (saving.value) {
        return
    }

    showForm.value = false
    editingUser.value = null
    resetForm()
}

const firstValidationError = (data) => {
    if (!data?.errors) {
        return null
    }

    const first = Object.values(data.errors)[0]

    if (Array.isArray(first)) {
        return first[0]
    }

    return first || null
}

const saveUser = async () => {
    formError.value = ''
    successMessage.value = ''

    if (!form.value.name.trim()) {
        formError.value = 'Name is required.'
        return
    }

    if (!form.value.email.trim()) {
        formError.value = 'Email is required.'
        return
    }

    if (!form.value.role_id) {
        formError.value = 'Role is required.'
        return
    }

    if (!form.value.office_id) {
        formError.value = 'Office is required.'
        return
    }

    if (
        !isEditing.value &&
        !form.value.password
    ) {
        formError.value =
            'Password is required for a new user.'
        return
    }

    if (
        form.value.password !==
        form.value.password_confirmation
    ) {
        formError.value =
            'Password confirmation does not match.'
        return
    }

    saving.value = true

    try {
        const url = isEditing.value
            ? `/api/users/${editingUser.value.id}`
            : '/api/users'

        const method = isEditing.value
            ? 'PUT'
            : 'POST'

        const response = await fetch(url, {
            method,
            headers: requestHeaders(true),
            body: JSON.stringify({
                name: form.value.name.trim(),
                email: form.value.email.trim(),
                role_id: Number(form.value.role_id),
                office_id: Number(form.value.office_id),
                password:
                    form.value.password || null,
                password_confirmation:
                    form.value.password_confirmation || null,
            }),
        })

        const data = await response.json()

        if (!response.ok) {
            throw new Error(
                firstValidationError(data) ||
                data.message ||
                'Unable to save user.'
            )
        }

        successMessage.value =
            data.message ||
            'User saved successfully.'

        const editedCurrentUser =
            isEditingSelf.value

        showForm.value = false
        editingUser.value = null
        resetForm()

        await fetchUsers()

        if (editedCurrentUser) {
            await ensureCurrentUser(true)
        }
    } catch (err) {
        formError.value =
            err.message ||
            'Unable to save user.'
    } finally {
        saving.value = false
    }
}

const roleClass = (roleName) => {
    switch (roleName) {
        case 'Administrator':
            return 'bg-purple-100 text-purple-700'

        case 'Records Officer':
            return 'bg-blue-100 text-blue-700'

        case 'Office User':
            return 'bg-emerald-100 text-emerald-700'

        case 'Viewer':
            return 'bg-gray-100 text-gray-700'

        default:
            return 'bg-gray-100 text-gray-700'
    }
}

onMounted(() => {
    loadPage()
})
</script>

<template>
    <div class="min-h-screen bg-gray-100">
        <div
            class="flex items-center justify-between border-b bg-white px-6 py-4"
        >
            <div>
                <h1 class="text-2xl font-bold text-gray-800">
                    User Management
                </h1>

                <p class="mt-1 text-sm text-gray-500">
                    Create users and assign their role and office.
                </p>
            </div>

            <Button
                class="bg-blue-600 text-white hover:bg-blue-700"
                @click="openAddForm"
            >
                + Add User
            </Button>
        </div>

        <div class="p-6">
            <div
                v-if="successMessage"
                class="mb-4 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm font-semibold text-green-700"
            >
                {{ successMessage }}
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>
                        System Users
                    </CardTitle>

                    <p class="mt-1 text-sm text-gray-500">
                        Role controls what a user may do. Office controls which documents the user may act on.
                    </p>
                </CardHeader>

                <CardContent>
                    <div
                        v-if="loading"
                        class="py-10 text-center text-gray-500"
                    >
                        Loading users...
                    </div>

                    <div
                        v-else-if="error"
                        class="rounded-md border border-red-200 bg-red-50 p-4 text-center text-red-600"
                    >
                        {{ error }}
                    </div>

                    <div
                        v-else-if="users.length === 0"
                        class="py-10 text-center text-gray-500"
                    >
                        No users found.
                    </div>

                    <div
                        v-else
                        class="overflow-x-auto"
                    >
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>
                                        User
                                    </TableHead>

                                    <TableHead>
                                        Role
                                    </TableHead>

                                    <TableHead>
                                        Office
                                    </TableHead>

                                    <TableHead class="text-right">
                                        Action
                                    </TableHead>
                                </TableRow>
                            </TableHeader>

                            <TableBody>
                                <TableRow
                                    v-for="user in users"
                                    :key="user.id"
                                >
                                    <TableCell>
                                        <div class="font-semibold text-gray-900">
                                            {{ user.name }}

                                            <span
                                                v-if="Number(user.id) === Number(currentUser?.id)"
                                                class="ml-2 rounded-full bg-blue-50 px-2 py-0.5 text-xs font-semibold text-blue-700"
                                            >
                                                You
                                            </span>
                                        </div>

                                        <div class="mt-1 text-sm text-gray-500">
                                            {{ user.email }}
                                        </div>
                                    </TableCell>

                                    <TableCell>
                                        <span
                                            class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                            :class="roleClass(user.role?.name)"
                                        >
                                            {{ user.role?.name || 'N/A' }}
                                        </span>
                                    </TableCell>

                                    <TableCell>
                                        {{ user.office?.office_name || 'N/A' }}
                                    </TableCell>

                                    <TableCell class="text-right">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            @click="openEditForm(user)"
                                        >
                                            Edit
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>
                </CardContent>
            </Card>
        </div>

        <div
            v-if="showForm"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4 py-6"
        >
            <Card class="max-h-[90vh] w-full max-w-2xl overflow-y-auto bg-white">
                <CardHeader>
                    <CardTitle>
                        {{ isEditing ? 'Edit User' : 'Add User' }}
                    </CardTitle>

                    <p class="mt-1 text-sm text-gray-500">
                        Assign one system role and one office to the user.
                    </p>
                </CardHeader>

                <CardContent>
                    <form
                        class="space-y-5"
                        @submit.prevent="saveUser"
                    >
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div>
                                <label class="mb-2 block text-sm font-semibold text-gray-700">
                                    Full Name *
                                </label>

                                <Input
                                    v-model="form.name"
                                    :disabled="saving"
                                    placeholder="Enter full name"
                                />
                            </div>

                            <div>
                                <label class="mb-2 block text-sm font-semibold text-gray-700">
                                    Email *
                                </label>

                                <Input
                                    v-model="form.email"
                                    :disabled="saving"
                                    type="email"
                                    placeholder="user@example.com"
                                />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div>
                                <label class="mb-2 block text-sm font-semibold text-gray-700">
                                    Role *
                                </label>

                                <select
                                    v-model="form.role_id"
                                    :disabled="saving || isEditingSelf"
                                    class="h-11 w-full rounded-md border border-gray-300 bg-white px-3 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 disabled:bg-gray-100 disabled:text-gray-500"
                                >
                                    <option value="">
                                        Select Role
                                    </option>

                                    <option
                                        v-for="role in roles"
                                        :key="role.id"
                                        :value="role.id"
                                    >
                                        {{ role.name }}
                                    </option>
                                </select>

                                <p
                                    v-if="isEditingSelf"
                                    class="mt-2 text-xs font-medium text-amber-700"
                                >
                                    Your own role is locked to prevent accidental loss of administrator access.
                                </p>
                            </div>

                            <div>
                                <label class="mb-2 block text-sm font-semibold text-gray-700">
                                    Office *
                                </label>

                                <select
                                    v-model="form.office_id"
                                    :disabled="saving"
                                    class="h-11 w-full rounded-md border border-gray-300 bg-white px-3 text-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                                >
                                    <option value="">
                                        Select Office
                                    </option>

                                    <option
                                        v-for="office in offices"
                                        :key="office.id"
                                        :value="office.id"
                                    >
                                        {{ office.office_name }}
                                        {{ office.office_code ? `(${office.office_code})` : '' }}
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div>
                                <label class="mb-2 block text-sm font-semibold text-gray-700">
                                    {{ isEditing ? 'New Password' : 'Password *' }}
                                </label>

                                <div class="relative">
                                    <Input
                                        v-model="form.password"
                                        :disabled="saving"
                                        :type="showPassword ? 'text' : 'password'"
                                        :placeholder="isEditing ? 'Leave blank to keep current password' : 'Minimum 8 characters'"
                                        class="pr-11"
                                    />

                                    <button
                                        type="button"
                                        class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-800"
                                        :disabled="saving"
                                        @click="showPassword = !showPassword"
                                    >
                                        <EyeOff
                                            v-if="showPassword"
                                            class="h-4 w-4"
                                        />

                                        <Eye
                                            v-else
                                            class="h-4 w-4"
                                        />
                                    </button>
                                </div>
                            </div>

                            <div>
                                <label class="mb-2 block text-sm font-semibold text-gray-700">
                                    Confirm Password
                                </label>

                                <div class="relative">
                                    <div class="relative">
                                            <Input
                                                v-model="form.password_confirmation"
                                                :disabled="saving"
                                                :type="showPasswordConfirmation ? 'text' : 'password'"
                                                placeholder="Repeat password"
                                                class="pr-11"
                                            />

                                            <button
                                                type="button"
                                                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-800"
                                                :disabled="saving"
                                                @click="
                                                    showPasswordConfirmation =
                                                        !showPasswordConfirmation
                                                "
                                            >
                                                <EyeOff
                                                    v-if="showPasswordConfirmation"
                                                    class="h-4 w-4"
                                                />

                                                <Eye
                                                    v-else
                                                    class="h-4 w-4"
                                                />
                                            </button>
                                        </div>
                                    <button
                                        type="button"
                                        class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-800"
                                        :disabled="saving"
                                        @click="showPasswordConfirmation = !showPasswordConfirmation"
                                    >
                                        <EyeOff
                                            v-if="showPasswordConfirmation"
                                            class="h-4 w-4"
                                        />

                                        <Eye
                                            v-else
                                            class="h-4 w-4"
                                        />
                                    </button>

                                </div>

                            </div>
                            
                        </div>

                        <div
                            v-if="formError"
                            class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-600"
                        >
                            {{ formError }}
                        </div>

                        <div class="flex justify-end gap-3 pt-2">
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="saving"
                                @click="closeForm"
                            >
                                Cancel
                            </Button>

                            <Button
                                type="submit"
                                class="bg-blue-600 text-white hover:bg-blue-700"
                                :disabled="saving"
                            >
                                {{ saving ? 'Saving...' : 'Save User' }}
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
