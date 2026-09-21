import assert from 'node:assert/strict'
import test from 'node:test'
import { readFile } from 'node:fs/promises'
import { runInNewContext } from 'node:vm'
import { computed, ref } from 'vue'

import { resolveAuthenticationNavigation } from '../../resources/js/lib/auth-guard.js'
import {
    canResetUserPassword,
    changePasswordRequest,
    listPasswordResetRequests,
    rejectPasswordResetRequest,
    resolvePasswordResetRequest,
    resetPasswordRequest,
    submitPasswordResetRequest,
    validatePasswordChangeForm,
} from '../../resources/js/lib/password-reset.js'

const response = (status, data) => ({
    ok: status >= 200 && status < 300,
    status,
    json: async () => data,
})

const loadSetup = async (path, bindings, exposed) => {
    const source = await readFile(new URL(path, import.meta.url), 'utf8')
    const setup = source.match(/<script setup>([\s\S]*?)<\/script>/)[1]
        .replace(/^import\s[\s\S]*?from\s+['"][^'"]+['"];?\r?$/gm, '')

    return runInNewContext(`${setup}\n;({ ${exposed.join(', ')} })`, {
        ref,
        computed,
        onMounted: () => {},
        onBeforeUnmount: () => {},
        watch: () => {},
        canResetUserPassword,
        listPasswordResetRequests,
        rejectPasswordResetRequest,
        resolvePasswordResetRequest,
        resetPasswordRequest,
        submitPasswordResetRequest,
        changePasswordRequest,
        validatePasswordChangeForm,
        ...bindings,
    })
}

test('admin reset password helper allows only users.manage against other users', () => {
    assert.equal(
        canResetUserPassword(
            { id: 1, permissions: ['users.manage'] },
            { id: 2 }
        ),
        true
    )
    assert.equal(
        canResetUserPassword(
            { id: 1, permissions: ['users.manage'] },
            { id: 1 }
        ),
        false
    )
    assert.equal(
        canResetUserPassword(
            { id: 1, permissions: ['documents.view'] },
            { id: 2 }
        ),
        false
    )
})

test('reset password request posts only temporary password fields to the narrow endpoint', async () => {
    const calls = []
    const result = await resetPasswordRequest({
        token: 'test-token',
        userId: 42,
        password: 'temporary-secret-18d',
        passwordConfirmation: 'temporary-secret-18d',
        fetcher: async (...args) => {
            calls.push(args)
            return response(200, {
                message: 'Temporary password set successfully.',
            })
        },
    })

    assert.equal(result.message, 'Temporary password set successfully.')
    assert.equal(calls[0][0], '/api/users/42/reset-password')
    assert.equal(calls[0][1].method, 'POST')
    assert.equal(calls[0][1].headers.Authorization, 'Bearer test-token')
    assert.deepEqual(JSON.parse(calls[0][1].body), {
        password: 'temporary-secret-18d',
        password_confirmation: 'temporary-secret-18d',
    })
})

test('reset password request surfaces validation errors without echoing password values', async () => {
    await assert.rejects(
        resetPasswordRequest({
            token: 'test-token',
            userId: 42,
            password: 'short',
            passwordConfirmation: 'short',
            fetcher: async () => response(422, {
                errors: {
                    password: ['The password field must be at least 8 characters.'],
                },
            }),
        }),
        { message: 'The password field must be at least 8 characters.' }
    )
})

test('public password reset request helper posts request fields without authentication', async () => {
    const calls = []
    const result = await submitPasswordResetRequest({
        email: 'requester@example.test',
        name: 'Requester',
        message: 'Cannot log in.',
        fetcher: async (...args) => {
            calls.push(args)
            return response(202, {
                message: 'If the account exists, an administrator will review the password reset request.',
            })
        },
    })

    assert.equal(
        result.message,
        'If the account exists, an administrator will review the password reset request.'
    )
    assert.equal(calls[0][0], '/api/password-reset-requests')
    assert.equal(calls[0][1].method, 'POST')
    assert.equal(calls[0][1].headers.Authorization, undefined)
    assert.deepEqual(JSON.parse(calls[0][1].body), {
        email: 'requester@example.test',
        name: 'Requester',
        message: 'Cannot log in.',
    })
})

test('admin password reset request helpers list resolve and reject safely', async () => {
    const calls = []
    const pending = await listPasswordResetRequests({
        token: 'test-token',
        fetcher: async (...args) => {
            calls.push(args)
            return response(200, {
                data: [{
                    id: 9,
                    email: 'target@example.test',
                    status: 'pending',
                }],
            })
        },
    })

    await resolvePasswordResetRequest({
        token: 'test-token',
        requestId: 9,
        password: 'temporary-secret-20c',
        passwordConfirmation: 'temporary-secret-20c',
        resolutionNote: 'Verified in person.',
        fetcher: async (...args) => {
            calls.push(args)
            return response(200, {
                message: 'Password reset request resolved successfully.',
            })
        },
    })

    await rejectPasswordResetRequest({
        token: 'test-token',
        requestId: 10,
        resolutionNote: 'Could not verify requester.',
        fetcher: async (...args) => {
            calls.push(args)
            return response(200, {
                message: 'Password reset request rejected.',
            })
        },
    })

    assert.equal(pending.length, 1)
    assert.equal(calls[0][0], '/api/password-reset-requests?status=pending')
    assert.equal(calls[0][1].headers.Authorization, 'Bearer test-token')
    assert.equal(calls[1][0], '/api/password-reset-requests/9/resolve')
    assert.deepEqual(JSON.parse(calls[1][1].body), {
        password: 'temporary-secret-20c',
        password_confirmation: 'temporary-secret-20c',
        resolution_note: 'Verified in person.',
    })
    assert.equal(calls[2][0], '/api/password-reset-requests/10/reject')
    assert.deepEqual(JSON.parse(calls[2][1].body), {
        resolution_note: 'Could not verify requester.',
    })
})

test('route guard sends must-change users to change-password before protected pages', async () => {
    const decision = await resolveAuthenticationNavigation({
        path: '/documents',
        fullPath: '/documents',
        meta: { permission: 'documents.view' },
    }, {
        getToken: () => 'test-token',
        ensureCurrentUser: async () => ({
            id: 7,
            must_change_password: true,
            permissions: ['documents.view'],
        }),
        can: () => true,
    })

    assert.deepEqual(decision, { path: '/change-password' })

    const allowed = await resolveAuthenticationNavigation({
        path: '/change-password',
        fullPath: '/change-password',
        meta: { authenticated: true, passwordChange: true },
    }, {
        getToken: () => 'test-token',
        ensureCurrentUser: async () => ({
            id: 7,
            must_change_password: true,
            permissions: [],
        }),
        can: () => false,
    })

    assert.equal(allowed, true)
})

test('user management page has a separate reset password action and secure warning text', async () => {
    const source = await readFile(new URL('../../resources/js/pages/Users.vue', import.meta.url), 'utf8')

    assert.match(source, /Reset Password/)
    assert.match(source, /Set Temporary Password/)
    assert.match(source, /Password Reset Requests/)
    assert.match(source, /No pending password reset requests\./)
    assert.match(source, /secure channel/)
    assert.match(source, /do not store it after saving/)
    assert.match(source, /resetPasswordRequest\(/)
    assert.match(source, /resolvePasswordResetRequest\(/)
    assert.match(source, /rejectPasswordResetRequest\(/)
    assert.match(source, /openResetPasswordForm\(user\)/)
    assert.match(source, /v-if="canManageUsers"/)
})

test('user management pending reset requests are admin only and render through setup state', async () => {
    const currentUser = ref({
        id: 1,
        permissions: ['users.manage'],
    })
    const page = await loadSetup('../../resources/js/pages/Users.vue', {
        useAuth: () => ({
            currentUser,
            ensureCurrentUser: async () => currentUser.value,
            getToken: () => 'test-token',
        }),
        listPasswordResetRequests: async () => ([{
            id: 7,
            email: 'target@example.test',
            name: 'Target User',
            message: 'Locked out.',
            user: {
                id: 3,
                name: 'Target User',
                email: 'target@example.test',
            },
        }]),
    }, [
        'canManageUsers',
        'pendingResetRequests',
        'fetchPendingResetRequests',
        'openResetRequestResolveForm',
        'resetTargetRequest',
        'resetTargetUser',
    ])

    await page.fetchPendingResetRequests()
    assert.equal(page.canManageUsers.value, true)
    assert.equal(page.pendingResetRequests.value.length, 1)

    page.openResetRequestResolveForm(page.pendingResetRequests.value[0])
    assert.equal(page.resetTargetRequest.value.id, 7)
    assert.equal(page.resetTargetUser.value.email, 'target@example.test')

    currentUser.value = {
        id: 2,
        permissions: ['documents.view'],
    }
    await page.fetchPendingResetRequests()
    assert.equal(page.canManageUsers.value, false)
    assert.equal(page.pendingResetRequests.value.length, 0)
})

test('change password validation and request payload use current temporary password contract', async () => {
    assert.equal(
        validatePasswordChangeForm({
            currentPassword: '',
            password: 'new-secret-18d',
            passwordConfirmation: 'new-secret-18d',
        }),
        'Current temporary password is required.'
    )
    assert.equal(
        validatePasswordChangeForm({
            currentPassword: 'temporary-secret-18d',
            password: 'temporary-secret-18d',
            passwordConfirmation: 'temporary-secret-18d',
        }),
        'New password must be different from the temporary password.'
    )

    const calls = []
    await changePasswordRequest({
        token: 'test-token',
        currentPassword: 'temporary-secret-18d',
        password: 'new-secret-18d',
        passwordConfirmation: 'new-secret-18d',
        fetcher: async (...args) => {
            calls.push(args)
            return response(200, {
                message: 'Password changed successfully. Please log in again.',
            })
        },
    })

    assert.equal(calls[0][0], '/api/me/password')
    assert.deepEqual(JSON.parse(calls[0][1].body), {
        current_password: 'temporary-secret-18d',
        password: 'new-secret-18d',
        password_confirmation: 'new-secret-18d',
    })
})

test('change password page clears local auth and redirects to login after success', async () => {
    const values = new Map([
        ['auth_token', 'test-token'],
        ['auth_user', '{"legacy":true}'],
    ])
    const replacements = []
    let cleared = false
    const page = await loadSetup('../../resources/js/pages/ChangePassword.vue', {
        localStorage: {
            getItem: key => values.get(key) ?? null,
            removeItem: key => values.delete(key),
            setItem: (key, value) => values.set(key, String(value)),
        },
        useRouter: () => ({
            replace: value => replacements.push(value),
        }),
        useAuth: () => ({
            getToken: () => 'test-token',
            clearCurrentUser: () => {
                cleared = true
            },
        }),
        changePasswordRequest: async () => ({
            message: 'Password changed successfully. Please log in again.',
        }),
    }, [
        'currentPassword',
        'password',
        'passwordConfirmation',
        'error',
        'saving',
        'submitPasswordChange',
    ])

    page.currentPassword.value = 'temporary-secret-18d'
    page.password.value = 'new-secret-18d'
    page.passwordConfirmation.value = 'new-secret-18d'

    await page.submitPasswordChange()

    assert.equal(values.has('auth_token'), false)
    assert.equal(values.has('auth_user'), false)
    assert.equal(cleared, true)
    assert.equal(replacements.length, 1)
    assert.equal(replacements[0].path, '/login')
    assert.equal(replacements[0].query.password_changed, '1')
    assert.equal(page.error.value, '')
    assert.equal(page.saving.value, false)
})
