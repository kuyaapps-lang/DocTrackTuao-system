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
        'qr.view',
        'master_data.view',
        'users.manage',
        'audit.view',
    ],
    recordsOfficer: [
        'documents.view',
        'qr.view',
        'master_data.view',
        'audit.view',
    ],
    officeUser: [
        'documents.view',
        'master_data.view',
    ],
    viewer: [
        'documents.view',
        'master_data.view',
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
            'documents',
            'qr-codes',
            'master-data',
            'offices',
            'document-types',
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
            'documents',
            'qr-codes',
            'master-data',
            'offices',
            'document-types',
            'audit',
        ]
    )
})

test('office user sees dashboard documents and master data links', () => {
    assert.deepEqual(
        flattenKeys(visibleNavigation(permissionSets.officeUser)),
        [
            'dashboard',
            'documents',
            'master-data',
            'offices',
            'document-types',
        ]
    )
})

test('viewer sees dashboard documents and master data links', () => {
    assert.deepEqual(
        flattenKeys(visibleNavigation(permissionSets.viewer)),
        [
            'dashboard',
            'documents',
            'master-data',
            'offices',
            'document-types',
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

test('document list detail and QR registration resolve to documents', () => {
    assert.equal(resolveActiveNavigationKey('/documents'), 'documents')
    assert.equal(resolveActiveNavigationKey('/documents/7'), 'documents')
    assert.equal(
        resolveActiveNavigationKey('/register-document/ABCDE-1234567'),
        'documents'
    )
})

test('each other sidebar destination resolves to its own key', () => {
    for (const [path, key] of [
        ['/dashboard', 'dashboard'],
        ['/qr-codes', 'qr-codes'],
        ['/offices', 'offices'],
        ['/document-types', 'document-types'],
        ['/users', 'users'],
        ['/audit', 'audit'],
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

test('navigation definitions contain no Reports destination', () => {
    const serialized = JSON.stringify(navigationItems).toLowerCase()

    assert.equal(serialized.includes('report'), false)
})
