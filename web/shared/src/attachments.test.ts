import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createAttachmentTransfer, type AttachmentChunk, type AttachmentMetadata } from './attachments'

type Adapter = Parameters<typeof createAttachmentTransfer>[0]
const abc = new Uint8Array([97, 98, 99])
const abcHash = 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad'
const unknown = new Error('lost response'), denied = new Error('forbidden')
const deniedUnknown = new Error('unconfirmed forbidden'), rejected = new Error('rejected')
const fixture: AttachmentMetadata = {
  id: '99999999-9999-4999-8999-999999999999', ticket_id: '7', message_id: null, filename: 'evidence.txt', media_type: 'text/plain',
  size_bytes: '3', sha256: abcHash, status: 'uploading', received_bytes: '0', next_chunk_index: 0,
  created_at: '2026-10-19T00:00:00Z', expires_at: '2026-10-20T00:00:00Z',
}
const completeChunk: AttachmentChunk = { attachment_id: '99999999-9999-4999-8999-999999999999', chunk_index: 0, content_base64: 'YWJj', sha256: abcHash, last: true }
const createURL = vi.fn((_object: Blob | MediaSource) => 'blob:attachment-fixture'), revokeURL = vi.fn((_url: string) => {})

function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>(done => { resolve = done })
  return { promise, resolve }
}

// jsdom File/Blob versions need not implement arrayBuffer; keep real File identity
// while providing deterministic bytes without file, browser or network I/O.
function file() {
  const value = new File([abc], fixture.filename, { type: fixture.media_type })
  Object.defineProperty(value, 'arrayBuffer', { value: async () => abc.slice().buffer })
  Object.defineProperty(value, 'slice', { value: (start: number, end: number) => ({
    arrayBuffer: async () => abc.slice(start, end).buffer,
  }) })
  return value
}

function harness() {
  let remote = { ...fixture }
  const commit: Adapter['write'] = async command => {
    switch (command.verb) {
      case 'begin': remote = { ...fixture, ...command.input }; break
      case 'append': remote = { ...remote, received_bytes: '3', next_chunk_index: 1 }; break
      case 'finish': remote = { ...remote, status: 'ready' }; break
      case 'cancel': remote = { ...remote, status: 'cancelled' }; break
    }
    return { ...remote }
  }
  const adapter = {
    authorize: vi.fn<Adapter['authorize']>(async () => {}),
    write: vi.fn<Adapter['write']>(commit),
    get: vi.fn<Adapter['get']>(async () => ({ ...remote })),
    chunk: vi.fn<Adapter['chunk']>(async () => ({ ...completeChunk })),
    classify: vi.fn<Adapter['classify']>(error => error === deniedUnknown ? 'denied-unknown'
      : error === denied ? 'denied' : error === rejected ? 'rejected' : 'unknown'),
  }
  return { adapter, commit, manager: createAttachmentTransfer(adapter) }
}

beforeEach(() => {
  let sequence = 0
  vi.stubGlobal('crypto', {
    randomUUID: vi.fn(() => `00000000-0000-4000-8000-${String(++sequence).padStart(12, '0')}`),
    subtle: { digest: vi.fn(async (algorithm: string, bytes: ArrayBuffer) => {
      expect(algorithm).toBe('SHA-256')
      expect(Array.from(new Uint8Array(bytes))).toEqual(Array.from(abc))
      return Uint8Array.from(abcHash.match(/../g)!, value => parseInt(value, 16)).buffer
    }) },
  })
  const NativeURL = URL
  vi.stubGlobal('URL', class extends NativeURL {
    static createObjectURL = createURL
    static revokeObjectURL = revokeURL
  })
  vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
})

afterEach(() => {
  vi.clearAllTimers()
  vi.useRealTimers()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  createURL.mockClear(); revokeURL.mockClear()
  document.body.innerHTML = ''
})

describe('attachment transfer recovery', () => {
  it('retains an unknown begin and explicitly confirms only the original input and key', async () => {
    const { manager, adapter } = harness()
    adapter.write.mockRejectedValueOnce(unknown)
    await manager.upload('7', file())
    const first = adapter.write.mock.calls[0]
    expect(first[0].verb).toBe('begin')
    expect(manager.snapshot().pending).toBe(true)
    expect(manager.readyIds('7')).toEqual([])
    const localId = manager.snapshot().entries[0].localId
    await manager.upload('7', file())
    await manager.resume(localId)
    await manager.cancel(localId)
    expect(adapter.write).toHaveBeenCalledTimes(1)
    await manager.recover()
    expect(adapter.write.mock.calls[1].slice(0, 2)).toEqual(first.slice(0, 2))
    expect(adapter.write.mock.calls.map(([command]) => command.verb)).toEqual(['begin', 'begin', 'append', 'finish'])
    expect(manager.snapshot().pending).toBe(false)
    expect(manager.readyIds('7')).toEqual(['99999999-9999-4999-8999-999999999999'])
  })

  it('keeps an unknown append at its original index and key before reading owner progress', async () => {
    const { manager, adapter, commit } = harness()
    let lost = true
    adapter.write.mockImplementation(async (command, key, signal) => {
      if (command.verb === 'append' && lost) { lost = false; throw unknown }
      return commit(command, key, signal)
    })
    await manager.upload('7', file())
    expect(manager.snapshot().pending).toBe(true)
    const original = adapter.write.mock.calls.find(([command]) => command.verb === 'append')!
    expect(original[0]).toMatchObject({ verb: 'append', input: { attachment_id: '99999999-9999-4999-8999-999999999999', chunk_index: 0, content_base64: 'YWJj' } })
    await manager.upload('7', file())
    expect(adapter.write).toHaveBeenCalledTimes(2)
    await manager.recover()
    const appends = adapter.write.mock.calls.filter(([command]) => command.verb === 'append')
    expect(appends).toHaveLength(2)
    expect(appends[1].slice(0, 2)).toEqual(original.slice(0, 2))
    expect(adapter.get).toHaveBeenCalledTimes(2)
    expect(manager.readyIds('7')).toEqual(['99999999-9999-4999-8999-999999999999'])
    expect(adapter.write.mock.calls.map(([command]) => command.verb)).toEqual(['begin', 'append', 'append', 'finish'])
  })

  it('hides unconfirmed 403 content and retains the original recovery through a later rejection', async () => {
    const { manager, adapter } = harness()
    adapter.write.mockRejectedValueOnce(deniedUnknown).mockRejectedValueOnce(rejected)
    await manager.upload('7', file())
    const original = adapter.write.mock.calls[0]
    expect(manager.snapshot()).toMatchObject({ denied: true, pending: true, entries: [] })
    await manager.recover()
    expect(adapter.write).toHaveBeenCalledTimes(1)
    await manager.refresh('8')
    expect(adapter.authorize).not.toHaveBeenCalled()
    await manager.refresh('7')
    expect(adapter.authorize).toHaveBeenCalledWith('7', expect.any(AbortSignal))
    expect(manager.snapshot()).toMatchObject({ denied: false, pending: true })
    await manager.recover()
    expect(manager.snapshot()).toMatchObject({ denied: false, pending: true })
    await manager.recover()
    expect(adapter.write.mock.calls[1].slice(0, 2)).toEqual(original.slice(0, 2))
    expect(adapter.write.mock.calls[2].slice(0, 2)).toEqual(original.slice(0, 2))
    expect(manager.snapshot()).toMatchObject({ denied: false, pending: false })
    expect(manager.readyIds('7')).toEqual(['99999999-9999-4999-8999-999999999999'])
  })

  it.each(['download', 'begin'] as const)('reauthorizes an empty-metadata %s failure before clearing denied', async source => {
    const { manager, adapter } = harness()
    if (source === 'download') {
      adapter.get.mockRejectedValueOnce(denied)
      await manager.download({ ...fixture, status: 'attached', message_id: '10' })
    } else {
      adapter.write.mockRejectedValueOnce(denied)
      await manager.upload('7', file())
    }
    expect(manager.snapshot()).toMatchObject({ denied: true, pending: false, entries: [] })
    await manager.refresh('7')
    expect(adapter.authorize).toHaveBeenCalledWith('7', expect.any(AbortSignal))
    expect(manager.snapshot().denied).toBe(false)
    if (source === 'begin') {
      const entry = manager.snapshot().entries[0]
      expect(entry.metadata).toBeUndefined()
      await manager.cancel(entry.localId)
      expect(manager.snapshot().entries).toEqual([])
      expect(adapter.write).toHaveBeenCalledTimes(1)
    }
  })
})

describe('attachment session and download boundaries', () => {
  it('aborts logout scope and ignores a late begin result without restoring the file or sending chunks', async () => {
    const { manager, adapter } = harness()
    const started = deferred<void>(), response = deferred<AttachmentMetadata>()
    adapter.write.mockImplementationOnce(async () => { started.resolve(); return response.promise })
    const task = manager.upload('7', file())
    await started.promise
    const signal = adapter.write.mock.calls[0][2]
    manager.reset()
    expect(signal.aborted).toBe(true)
    response.resolve({ ...fixture })
    await task
    expect(manager.snapshot()).toMatchObject({ busy: false, pending: false, denied: false, entries: [] })
    expect(adapter.write).toHaveBeenCalledTimes(1)
    expect(adapter.get).not.toHaveBeenCalled()
    expect(createURL).not.toHaveBeenCalled()
  })

  it('does not create a Blob URL or click after logout while a download chunk is in flight', async () => {
    const { manager, adapter } = harness()
    const metadata = { ...fixture, status: 'attached' as const, message_id: '10' }
    adapter.get.mockResolvedValue(metadata)
    const started = deferred<void>(), response = deferred<AttachmentChunk>()
    adapter.chunk.mockImplementationOnce(async () => { started.resolve(); return response.promise })
    const task = manager.download(metadata)
    await started.promise
    const signal = adapter.chunk.mock.calls[0][2]
    manager.reset()
    response.resolve(completeChunk)
    await task
    expect(signal.aborted).toBe(true)
    expect(createURL).not.toHaveBeenCalled()
    expect(HTMLAnchorElement.prototype.click).not.toHaveBeenCalled()
    expect(manager.snapshot()).toMatchObject({ busy: false, entries: [] })
  })

  it.each([
    ['incomplete bytes', { content_base64: 'YWI=' }],
    ['wrong chunk hash', { sha256: '0'.repeat(64) }],
    ['missing final flag', { last: false }],
    ['wrong chunk index', { chunk_index: 1 }],
  ] as const)('refuses %s before creating any download', async (_name, patch) => {
    const { manager, adapter } = harness()
    const metadata = { ...fixture, status: 'attached' as const, message_id: '10' }
    adapter.get.mockResolvedValue(metadata)
    adapter.chunk.mockResolvedValue({ ...completeChunk, ...patch })
    await manager.download(metadata)
    expect(manager.snapshot().error).toBeDefined()
    expect(createURL).not.toHaveBeenCalled()
    expect(HTMLAnchorElement.prototype.click).not.toHaveBeenCalled()
  })

  it('checks the whole-file hash separately from a valid chunk hash', async () => {
    const { manager, adapter } = harness()
    const metadata = { ...fixture, status: 'attached' as const, message_id: '10', sha256: '0'.repeat(64) }
    adapter.get.mockResolvedValue(metadata)
    await manager.download(metadata)
    expect(manager.snapshot().error).toBeDefined()
    expect(createURL).not.toHaveBeenCalled()
    expect(HTMLAnchorElement.prototype.click).not.toHaveBeenCalled()
  })

  it('downloads verified bytes as an attachment and revokes its URL on logout', async () => {
    vi.useFakeTimers()
    const { manager, adapter } = harness()
    const metadata = { ...fixture, status: 'attached' as const, message_id: '10' }
    adapter.get.mockResolvedValue(metadata)
    let clicked: HTMLAnchorElement | undefined
    vi.mocked(HTMLAnchorElement.prototype.click).mockImplementation(function (this: HTMLAnchorElement) { clicked = this })
    await manager.download(metadata)
    expect(createURL).toHaveBeenCalledTimes(1)
    expect(createURL.mock.calls[0][0]).toBeInstanceOf(Blob)
    expect((createURL.mock.calls[0][0] as Blob).type).toBe('application/octet-stream')
    expect(clicked?.download).toBe(fixture.filename)
    expect(clicked?.href).toBe('blob:attachment-fixture')
    expect(document.querySelector('a')).toBeNull()
    expect(manager.snapshot().error).toBeUndefined()
    manager.reset()
    expect(revokeURL).toHaveBeenCalledWith('blob:attachment-fixture')
    expect(manager.snapshot().entries).toEqual([])
  })
})
