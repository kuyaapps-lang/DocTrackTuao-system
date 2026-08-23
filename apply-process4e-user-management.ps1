$ErrorActionPreference = 'Stop'

$timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'

function Write-Utf8NoBom([string]$Path, [string]$Content) {
    $encoding = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($Path, $Content, $encoding)
}

$required = @('routes\api.php','resources\js\router\index.js')
foreach ($path in $required) { if (-not (Test-Path $path)) { throw "Run this script from the DocTrackTuao-system project root. Missing: $path" } }

$backupTargets = @('routes\api.php','resources\js\router\index.js','app\Http\Controllers\UserManagementController.php','resources\js\pages\Users.vue')
foreach ($path in $backupTargets) { if (Test-Path $path) { Copy-Item $path "$path.process4e-$timestamp.bak" -Force } }

New-Item -ItemType Directory -Force -Path 'app\Http\Controllers' | Out-Null
New-Item -ItemType Directory -Force -Path 'resources\js\pages' | Out-Null

$content_app_Http_Controllers_UserManagementController_php = @'
<?php

namespace App\Http\Controllers;

use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserManagementController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::query()
            ->with([
                'role',
                'office',
                'department',
            ])
            ->orderBy('name')
            ->get();

        return response()->json($users);
    }

    public function formOptions(): JsonResponse
    {
        return response()->json([
            'roles' => Role::query()
                ->orderBy('name')
                ->get([
                    'id',
                    'name',
                    'description',
                ]),

            'offices' => Office::query()
                ->orderBy('office_name')
                ->get([
                    'id',
                    'office_name',
                    'office_code',
                    'department_id',
                ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'email' => [
                'required',
                'email',
                'max:255',
                'unique:users,email',
            ],
            'role_id' => [
                'required',
                'integer',
                'exists:roles,id',
            ],
            'office_id' => [
                'required',
                'integer',
                'exists:offices,id',
            ],
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
            ],
        ]);

        $office = Office::query()->findOrFail(
            $validated['office_id']
        );

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make(
                $validated['password']
            ),
            'role_id' => $validated['role_id'],
            'department_id' => $office->department_id,
            'office_id' => $office->id,
        ]);

        return response()->json([
            'message' => 'User created successfully.',
            'user' => $user->load([
                'role',
                'office',
                'department',
            ]),
        ], 201);
    }

    public function update(
        Request $request,
        User $user
    ): JsonResponse {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')
                    ->ignore($user->id),
            ],
            'role_id' => [
                'required',
                'integer',
                'exists:roles,id',
            ],
            'office_id' => [
                'required',
                'integer',
                'exists:offices,id',
            ],
            'password' => [
                'nullable',
                'string',
                'min:8',
                'confirmed',
            ],
        ]);

        if (
            $request->user()->is($user) &&
            (int) $validated['role_id'] !==
                (int) $user->role_id
        ) {
            return response()->json([
                'message' => 'You cannot change your own role.',
            ], 422);
        }

        $office = Office::query()->findOrFail(
            $validated['office_id']
        );

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->role_id = $validated['role_id'];
        $user->department_id = $office->department_id;
        $user->office_id = $office->id;

        if (!empty($validated['password'])) {
            $user->password = Hash::make(
                $validated['password']
            );
        }

        $user->save();

        return response()->json([
            'message' => 'User updated successfully.',
            'user' => $user->load([
                'role',
                'office',
                'department',
            ]),
        ]);
    }
}

'@
Write-Utf8NoBom 'app\Http\Controllers\UserManagementController.php' $content_app_Http_Controllers_UserManagementController_php

$content_resources_js_pages_Users_vue = @'
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

                                <Input
                                    v-model="form.password"
                                    :disabled="saving"
                                    type="password"
                                    :placeholder="isEditing ? 'Leave blank to keep current password' : 'Minimum 8 characters'"
                                />
                            </div>

                            <div>
                                <label class="mb-2 block text-sm font-semibold text-gray-700">
                                    Confirm Password
                                </label>

                                <Input
                                    v-model="form.password_confirmation"
                                    :disabled="saving"
                                    type="password"
                                    placeholder="Repeat password"
                                />
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

'@
Write-Utf8NoBom 'resources\js\pages\Users.vue' $content_resources_js_pages_Users_vue

$content_routes_api_php = @'
<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DocumentAttachmentController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentProcessingController;
use App\Http\Controllers\DocumentQrCodeController;
use App\Http\Controllers\DocumentRoutingController;
use App\Http\Controllers\DocumentTrackingController;
use App\Http\Controllers\DocumentTypeController;
use App\Http\Controllers\OfficeController;
use App\Http\Controllers\UserManagementController;

/*
|--------------------------------------------------------------------------
| AUTH ROUTES
|--------------------------------------------------------------------------
*/

Route::post(
    '/login',
    [AuthController::class, 'login']
);

Route::post(
    '/logout',
    [AuthController::class, 'logout']
);

/*
|--------------------------------------------------------------------------
| PUBLIC DOCUMENT TRACKING
|--------------------------------------------------------------------------
|
| No authentication is required.
| Only safe tracking information is exposed.
|
*/

Route::get(
    '/track/{trackingNo}',
    [DocumentTrackingController::class, 'show']
);

/*
|--------------------------------------------------------------------------
| PUBLIC QR RESOLUTION
|--------------------------------------------------------------------------
|
| Scanning an issued QR calls this endpoint.
|
| unused      -> document registration
| registered  -> document tracking
| void        -> invalid/void message
|
*/

Route::get(
    '/q/{token}',
    [DocumentQrCodeController::class, 'resolve']
);

/*
|--------------------------------------------------------------------------
| PROTECTED ROUTES
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | AUTHENTICATED USER
    |--------------------------------------------------------------------------
    |
    | Return role/office information and the permission names that the
    | frontend may use for menus and action visibility.
    |
    */

    Route::get('/user', function (Request $request) {
        $user = $request->user()->load([
            'role',
            'department',
            'office',
        ]);

        $data = $user->toArray();
        $data['permissions'] = $user->permissionNames();

        return response()->json($data);
    });

    /*
    |--------------------------------------------------------------------------
    | USER MANAGEMENT
    |--------------------------------------------------------------------------
    |
    | Administrator-only. Role assignment controls capabilities while office
    | assignment controls document scope. Department is synchronized from the
    | selected office by UserManagementController.
    |
    */

    Route::get(
        'users/form-options',
        [UserManagementController::class, 'formOptions']
    )->middleware('can:users.manage');

    Route::get(
        'users',
        [UserManagementController::class, 'index']
    )->middleware('can:users.manage');

    Route::post(
        'users',
        [UserManagementController::class, 'store']
    )->middleware('can:users.manage');

    Route::match(
        ['put', 'patch'],
        'users/{user}',
        [UserManagementController::class, 'update']
    )->middleware('can:users.manage');

    /*
    |--------------------------------------------------------------------------
    | DOCUMENT PROCESSING
    |--------------------------------------------------------------------------
    */

    Route::get(
        'documents/{document}/processing',
        [DocumentProcessingController::class, 'show']
    )->middleware('can:documents.view');

    Route::put(
        'documents/{document}/processing',
        [DocumentProcessingController::class, 'update']
    )->middleware('can:documents.process');

    /*
    |--------------------------------------------------------------------------
    | QR CODE REQUEST / ISSUANCE
    |--------------------------------------------------------------------------
    */

    Route::get(
        'qr-codes',
        [DocumentQrCodeController::class, 'index']
    )->middleware('can:qr.view');

    Route::post(
        'qr-codes',
        [DocumentQrCodeController::class, 'store']
    )->middleware('can:qr.manage');

    Route::get(
        'qr-codes/{qrCode}',
        [DocumentQrCodeController::class, 'show']
    )->middleware('can:qr.view');

    Route::post(
        'qr-codes/{qrCode}/void',
        [DocumentQrCodeController::class, 'void']
    )->middleware('can:qr.manage');

    /*
    |--------------------------------------------------------------------------
    | DOCUMENT FORM OPTIONS
    |--------------------------------------------------------------------------
    */

    Route::get(
        'document-form-options',
        [DocumentController::class, 'formOptions']
    )->middleware('can:documents.create');

    /*
    |--------------------------------------------------------------------------
    | DOCUMENT LIST VIEWS
    |--------------------------------------------------------------------------
    */

    Route::get(
        'documents/incoming',
        [DocumentController::class, 'incoming']
    )->middleware('can:documents.view');

    Route::get(
        'documents/outgoing',
        [DocumentController::class, 'outgoing']
    )->middleware('can:documents.view');

    /*
    |--------------------------------------------------------------------------
    | DOCUMENT ROUTING
    |--------------------------------------------------------------------------
    */

    Route::get(
        'documents/{document}/routing-options',
        [DocumentRoutingController::class, 'options']
    )->middleware('can:documents.view');

    Route::post(
        'documents/{document}/forward',
        [DocumentRoutingController::class, 'forward']
    )->middleware('can:documents.route');

    Route::post(
        'documents/{document}/receive',
        [DocumentRoutingController::class, 'receive']
    )->middleware('can:documents.route');

    Route::get(
        'documents/{document}/history',
        [DocumentRoutingController::class, 'history']
    )->middleware('can:documents.view');

    /*
    |--------------------------------------------------------------------------
    | DOCUMENT ATTACHMENTS
    |--------------------------------------------------------------------------
    */

    Route::get(
        'documents/{document}/attachments',
        [DocumentAttachmentController::class, 'index']
    )->middleware('can:attachments.view');

    Route::post(
        'documents/{document}/attachments',
        [DocumentAttachmentController::class, 'store']
    )->middleware('can:attachments.manage');

    Route::get(
        'attachments/{attachment}/download',
        [DocumentAttachmentController::class, 'download']
    )->middleware('can:attachments.view');

    Route::delete(
        'attachments/{attachment}',
        [DocumentAttachmentController::class, 'destroy']
    )->middleware('can:attachments.manage');

    /*
    |--------------------------------------------------------------------------
    | DOCUMENT CRUD
    |--------------------------------------------------------------------------
    */

    Route::get(
        'documents',
        [DocumentController::class, 'index']
    )->middleware('can:documents.view');

    Route::post(
        'documents',
        [DocumentController::class, 'store']
    )->middleware('can:documents.create');

    Route::get(
        'documents/{document}',
        [DocumentController::class, 'show']
    )->middleware('can:documents.view');

    Route::match(
        ['put', 'patch'],
        'documents/{document}',
        [DocumentController::class, 'update']
    )->middleware('can:documents.edit');

    Route::delete(
        'documents/{document}',
        [DocumentController::class, 'destroy']
    )->middleware('can:documents.delete');

    /*
    |--------------------------------------------------------------------------
    | OFFICE ROUTES
    |--------------------------------------------------------------------------
    */

    Route::get(
        'offices',
        [OfficeController::class, 'index']
    )->middleware('can:master_data.view');

    Route::post(
        'offices',
        [OfficeController::class, 'store']
    )->middleware('can:master_data.manage');

    Route::get(
        'offices/{office}',
        [OfficeController::class, 'show']
    )->middleware('can:master_data.view');

    Route::match(
        ['put', 'patch'],
        'offices/{office}',
        [OfficeController::class, 'update']
    )->middleware('can:master_data.manage');

    Route::delete(
        'offices/{office}',
        [OfficeController::class, 'destroy']
    )->middleware('can:master_data.manage');

    /*
    |--------------------------------------------------------------------------
    | DOCUMENT TYPE ROUTES
    |--------------------------------------------------------------------------
    */

    Route::get(
        'document-types',
        [DocumentTypeController::class, 'index']
    )->middleware('can:master_data.view');

    Route::post(
        'document-types',
        [DocumentTypeController::class, 'store']
    )->middleware('can:master_data.manage');

    Route::get(
        'document-types/{documentType}',
        [DocumentTypeController::class, 'show']
    )->middleware('can:master_data.view');

    Route::match(
        ['put', 'patch'],
        'document-types/{documentType}',
        [DocumentTypeController::class, 'update']
    )->middleware('can:master_data.manage');

    Route::delete(
        'document-types/{documentType}',
        [DocumentTypeController::class, 'destroy']
    )->middleware('can:master_data.manage');
});

'@
Write-Utf8NoBom 'routes\api.php' $content_routes_api_php

$content_resources_js_router_index_js = @'
import {
    createRouter,
    createWebHistory,
} from 'vue-router'

import Login from '../pages/Login.vue'
import Dashboard from '../pages/Dashboard.vue'
import Documents from '../pages/Documents.vue'
import DocumentDetails from '../pages/DocumentDetails.vue'
import DocumentTracking from '../pages/DocumentTracking.vue'
import QrResolver from '../pages/QrResolver.vue'
import QrCodes from '../pages/QrCodes.vue'
import Offices from '../pages/Offices.vue'
import DocumentTypes from '../pages/DocumentTypes.vue'
import Users from '../pages/Users.vue'

import {
    can,
    ensureCurrentUser,
    getToken,
} from '../lib/auth'

const routes = [
    {
        path: '/',
        redirect: '/login',
    },

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
        },
    },

    {
        path: '/q/:token',
        name: 'qr-resolver',
        component: QrResolver,
        meta: {
            public: true,
        },
    },

    {
        path: '/track/:trackingNo',
        component: DocumentTracking,
        meta: {
            public: true,
        },
    },

    /*
    |--------------------------------------------------------------------------
    | Authenticated Application Routes
    |--------------------------------------------------------------------------
    */

    {
        path: '/register-document/:qrToken',
        name: 'qr-document-registration',
        component: Documents,
        meta: {
            permission: 'documents.create',
        },
    },

    {
        path: '/dashboard',
        component: Dashboard,
        meta: {
            authenticated: true,
        },
    },

    {
        path: '/documents',
        component: Documents,
        meta: {
            permission: 'documents.view',
        },
    },

    {
        path: '/documents/:id',
        component: DocumentDetails,
        meta: {
            permission: 'documents.view',
        },
    },

    {
        path: '/qr-codes',
        component: QrCodes,
        meta: {
            permission: 'qr.view',
        },
    },

    {
        path: '/offices',
        component: Offices,
        meta: {
            permission: 'master_data.view',
        },
    },

    {
        path: '/document-types',
        component: DocumentTypes,
        meta: {
            permission: 'master_data.view',
        },
    },

    {
        path: '/users',
        component: Users,
        meta: {
            permission: 'users.manage',
        },
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
| If no token is present, the guard deliberately leaves the existing login / QR
| redirect behavior untouched. We will integrate the login page more tightly in
| the next frontend step after inspecting its current QR redirect logic.
|
*/

router.beforeEach(async (to) => {
    const permission = to.meta?.permission
    const authenticated =
        to.meta?.authenticated === true ||
        Boolean(permission)

    if (!authenticated || to.meta?.public) {
        return true
    }

    if (!getToken()) {
        return true
    }

    try {
        await ensureCurrentUser()
    } catch {
        // API authentication/error handling on the destination page remains
        // the fallback. Do not disturb the existing login/QR workflow here.
        return true
    }

    if (permission && !can(permission)) {
        return {
            path: '/dashboard',
            query: {
                forbidden: '1',
            },
        }
    }

    return true
})

export default router

'@
Write-Utf8NoBom 'resources\js\router\index.js' $content_resources_js_router_index_js

Write-Host ''
Write-Host 'Process 4E User Management files applied.' -ForegroundColor Green
Write-Host "Backups created with suffix: .process4e-$timestamp.bak" -ForegroundColor Yellow
Write-Host ''
Write-Host 'Next run:' -ForegroundColor Cyan
Write-Host 'php -l app\Http\Controllers\UserManagementController.php'
Write-Host 'php -l routes\api.php'
Write-Host 'php artisan optimize:clear'
Write-Host 'php artisan route:list | findstr users'
Write-Host 'npm run build'
