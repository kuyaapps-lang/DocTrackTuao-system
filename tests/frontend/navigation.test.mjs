import test from 'node:test'
import assert from 'node:assert/strict'

import {
    navigationItems,
    resolveActiveNavigationKey,
    visibleNavigation,
} from '../../resources/js/lib/navigation.js'

const permissionSets = {
    administrator: [
        'documents.view',
        'documents.process',
        'documents.route',
        'qr.request',
        'qr.view',
        'qr.manage',
        'qr.issue',
        'qr.approve',
        'qr.void',
        'master_data.view',
        'users.manage',
        'audit.view',
        'reports.view',
    ],
    recordsOfficer: [
        'documents.view',
        'documents.process',
        'documents.route',
        'qr.request',
        'qr.view',
        'qr.manage',
        'master_data.view',
        'audit.view',
        'reports.view',
    ],
    officeUser: [
        'documents.view',
        'documents.process',
        'documents.route',
        'qr.request',
        'master_data.view',
        'reports.view',
    ],
    viewer: [
        'documents.view',
        'master_data.view',
        'reports.view',
    ],
}

const flattenKeys = (items) => {
    return items.flatMap(item => {
        return item.children
            ? [item.key, ...item.children.map(child => child.key)]
            : [item.key]
    })
}

test('administrator sees every current sidebar destination', () => {
    assert.deepEqual(
        flattenKeys(visibleNavigation(permissionSets.administrator)),
        [
            'dashboard',
            'outgoing-documents',
            'incoming-documents',
            'received-document',
            'change-status',
            'release-document',
            'document-inquiry',
            'qr-codes',
            'master-data',
            'offices',
            'document-types',
            'reports',
            'accomplishment-report',
            'users',
            'audit',
        ]
    )
})

test('records officer sees every current link except users', () => {
    assert.deepEqual(
        flattenKeys(visibleNavigation(permissionSets.recordsOfficer)),
        [
            'dashboard',
            'outgoing-documents',
            'incoming-documents',
            'received-document',
            'change-status',
            'release-document',
            'document-inquiry',
            'qr-codes',
            'master-data',
            'offices',
            'document-types',
            'reports',
            'accomplishment-report',
            'audit',
        ]
    )
})

test('office user sees QR requests but not master data links', () => {
    assert.deepEqual(
        flattenKeys(visibleNavigation(permissionSets.officeUser)),
        [
            'dashboard',
            'outgoing-documents',
            'incoming-documents',
            'received-document',
            'change-status',
            'release-document',
            'document-inquiry',
            'qr-codes',
            'reports',
            'accomplishment-report',
        ]
    )
})

test('viewer does not see master data links', () => {
    assert.deepEqual(
        flattenKeys(visibleNavigation(permissionSets.viewer)),
        [
            'dashboard',
            'outgoing-documents',
            'incoming-documents',
            'document-inquiry',
            'reports',
            'accomplishment-report',
        ]
    )
})

test('items with missing permissions are excluded', () => {
    assert.deepEqual(
        flattenKeys(visibleNavigation([])),
        ['dashboard']
    )
})

test('master data remains one group with stable child metadata', () => {
    const masterData = visibleNavigation(
        permissionSets.administrator
    ).find(item => item.key === 'master-data')

    assert.equal(masterData.path, null)
    assert.deepEqual(masterData.visibilityPermissions, ['qr.manage'])
    assert.deepEqual(
        masterData.children.map(child => ({
            key: child.key,
            group: child.group,
            permission: child.permission,
        })),
        [
            {
                key: 'offices',
                group: 'master-data',
                permission: 'master_data.view',
            },
            {
                key: 'document-types',
                group: 'master-data',
                permission: 'master_data.view',
            },
        ]
    )
})

test('document list navigation resolves filtered lists to their sidebar links', () => {
    assert.equal(
        resolveActiveNavigationKey('/documents?view=incoming'),
        'incoming-documents'
    )
    assert.equal(
        resolveActiveNavigationKey('/documents?view=outgoing'),
        'outgoing-documents'
    )
    assert.equal(
        resolveActiveNavigationKey('/documents'),
        'outgoing-documents'
    )
    assert.equal(resolveActiveNavigationKey('/documents/7'), null)
    assert.equal(
        resolveActiveNavigationKey('/register-document/ABCDE-1234567'),
        null
    )
})

test('each other sidebar destination resolves to its own key', () => {
    for (const [path, key] of [
        ['/dashboard', 'dashboard'],
        ['/document-inquiry', 'document-inquiry'],
        ['/received-document', 'received-document'],
        ['/change-status', 'change-status'],
        ['/release-document', 'release-document'],
        ['/qr-codes', 'qr-codes'],
        ['/offices', 'offices'],
        ['/document-types', 'document-types'],
        ['/users', 'users'],
        ['/audit', 'audit'],
        ['/reports/accomplishment', 'accomplishment-report'],
    ]) {
        assert.equal(resolveActiveNavigationKey(path), key)
    }
})

test('public and unknown routes have no active sidebar key', () => {
    for (const path of [
        '/',
        '/login',
        '/q/ABCDE-1234567',
        '/track/DOC-001',
        '/reports',
        '/unknown',
    ]) {
        assert.equal(resolveActiveNavigationKey(path), null)
    }
})

test('navigation definitions include the reports group and accomplishment report', () => {
    const serialized = JSON.stringify(navigationItems).toLowerCase()

    assert.equal(serialized.includes('accomplishment report'), true)
})
