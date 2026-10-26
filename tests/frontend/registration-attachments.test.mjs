import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { uploadRegistrationAttachments, validateRegistrationAttachments } from '../../resources/js/lib/registration-attachments.js'

test('registration displays an add attachments button before the form options', async () => {
    const source = await readFile(new URL('../../resources/js/pages/Documents.vue', import.meta.url), 'utf8')
    assert.match(source, /@click="registrationAttachmentInput\?\.click\(\)"[\s\S]*?Add attachments/)
    assert.match(source, /:disabled="creating \|\| qrVerifying \|\| !qrVerified \|\| Boolean\(savedDocumentId\) \|\| registrationUncertain" @click="registrationAttachmentInput/)
    assert.ok(source.indexOf('Add attachments') < source.indexOf('v-if="optionsLoading"'))
})

test('checks the same file types and size limits before registration', () => {
    assert.equal(validateRegistrationAttachments([{ name: 'report.pdf', size: 10 * 1024 * 1024 }]), '')
    assert.match(validateRegistrationAttachments([{ name: 'bad.exe', size: 1 }]), /unsupported/)
    assert.match(validateRegistrationAttachments([{ name: 'large.pdf', size: 10 * 1024 * 1024 + 1 }]), /10 MB/)
})

test('uploads each selected file to the saved document and stops on failure', async () => {
    const files = [new File(['a'], 'a.pdf'), new File(['b'], 'b.pdf'), new File(['c'], 'c.pdf')]
    const calls = []
    const result = await uploadRegistrationAttachments(42, files, 'test-token', async (url, options) => {
        calls.push({ url, options })
        return calls.length === 1 ? { ok: true } : { ok: false, json: async () => ({ message: 'Rejected' }) }
    })
    assert.deepEqual(result.uploaded, ['a.pdf'])
    assert.equal(result.failed, 'b.pdf')
    assert.equal(result.message, 'Rejected')
    assert.equal(calls.length, 2)
    assert.equal(calls[0].url, '/api/documents/42/attachments')
    assert.equal(calls[0].options.body.get('file').name, 'a.pdf')
})

test('reports all files uploaded when every request succeeds', async () => {
    const files = [new File(['a'], 'a.pdf'), new File(['b'], 'b.png')]
    const result = await uploadRegistrationAttachments(42, files, 'test-token', async () => ({ ok: true }))
    assert.deepEqual(result, { uploaded: ['a.pdf', 'b.png'], failed: null })
})

test('reports an uncertain upload when the response is lost', async () => {
    const result = await uploadRegistrationAttachments(42, [new File(['a'], 'a.pdf')], 'test-token', async () => { throw new Error('offline') })
    assert.equal(result.failed, 'a.pdf')
    assert.equal(result.uncertain, true)
    assert.deepEqual(result.uploaded, [])
})
