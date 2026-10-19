export type AttachmentMetadata = {
  id: string; ticket_id: string; message_id: string | null; filename: string; media_type: string
  size_bytes: string; sha256: string; status: 'uploading' | 'ready' | 'attached' | 'cancelled' | 'expired'
  received_bytes: string; next_chunk_index: number; created_at: string; expires_at: string
}
type MediaType = 'image/png' | 'image/jpeg' | 'application/pdf' | 'text/plain'
type Inputs = {
  begin: { ticket_id: string; filename: string; media_type: MediaType; size_bytes: string; sha256: string }
  append: { attachment_id: string; chunk_index: number; content_base64: string }
  finish: { attachment_id: string }; cancel: { attachment_id: string }
}
export type AttachmentCommand = { [K in keyof Inputs]: { verb: K; input: Inputs[K] } }[keyof Inputs]
export type AttachmentChunk = { attachment_id: string; chunk_index: number; content_base64: string; sha256: string; last: boolean }
type Adapter = {
  authorize(ticketId: string, signal: AbortSignal): Promise<void>
  write(command: AttachmentCommand, key: string, signal: AbortSignal): Promise<AttachmentMetadata>
  get(id: string, signal: AbortSignal): Promise<AttachmentMetadata>
  chunk(id: string, index: number, signal: AbortSignal): Promise<AttachmentChunk>
  classify(error: unknown): 'unknown' | 'denied-unknown' | 'denied' | 'rejected'
}
type Entry = { localId: string; ticketId: string; file?: File; hash?: string; metadata?: AttachmentMetadata }
export type AttachmentTransferState = Readonly<{
  busy: boolean; pending: boolean; denied: boolean; error?: string
  entries: ReadonlyArray<{ localId: string; ticketId: string; filename: string; metadata?: AttachmentMetadata }>
}>
const chunkSize = 8192, maxSize = 5 * 1024 * 1024
const types = new Set(['image/png', 'image/jpeg', 'application/pdf', 'text/plain'])
function supportedType(value: string): value is MediaType { return types.has(value) }
function assert(valid: unknown): asserts valid { if (!valid) throw new Error('ATTACHMENT_REFUSED') }
const hash = async (bytes: ArrayBuffer) => Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', bytes)), value => value.toString(16).padStart(2, '0')).join('')
const base64 = (bytes: Uint8Array) => btoa(Array.from(bytes, value => String.fromCharCode(value)).join(''))
function decode(value: string) {
  assert(typeof value === 'string' && value.length <= 10924 && /^[A-Za-z0-9+/]*={0,2}$/.test(value))
  const raw = atob(value), bytes = Uint8Array.from(raw, character => character.charCodeAt(0))
  assert(bytes.length > 0 && bytes.length <= chunkSize && base64(bytes) === value)
  return bytes
}
function safeFilename(value: string) {
  assert(value.length > 0 && new TextEncoder().encode(value).length <= 255
    && !/[\x00-\x1f\x7f/\\]/.test(value) && value !== '.' && value !== '..')
  return value
}

/** Private files, chunk bodies and idempotency keys live only in this session-owned instance. */
export function createAttachmentTransfer(adapter: Adapter) {
  let epoch = 0, scope = new AbortController()
  let state: AttachmentTransferState = Object.freeze({ busy: false, pending: false, denied: false, entries: [] })
  const entries = new Map<string, Entry>(), listeners = new Set<() => void>(), urls = new Set<string>()
  let pending: { entry: Entry; command: AttachmentCommand; key: string; unknown?: boolean } | undefined
  function publish(patch: Partial<AttachmentTransferState> = {}) {
    const denied = patch.denied ?? state.denied
    state = Object.freeze({ ...state, ...patch, pending: !!pending,
      entries: denied ? [] : Array.from(entries.values(), entry => ({ localId: entry.localId,
        ticketId: entry.ticketId, filename: entry.file?.name ?? entry.metadata?.filename ?? '', metadata: entry.metadata })) })
    for (const listener of listeners) listener()
  }
  function current(expected: number) { assert(expected === epoch && !scope.signal.aborted) }
  function accept(entry: Entry, metadata: AttachmentMetadata) {
    assert(metadata.ticket_id === entry.ticketId && (!entry.metadata || metadata.id === entry.metadata.id))
    if (entry.file) assert(metadata.filename === entry.file.name && metadata.media_type === entry.file.type
      && metadata.size_bytes === String(entry.file.size) && metadata.sha256 === entry.hash)
    entry.metadata = Object.freeze({ ...metadata })
    if (['attached', 'cancelled', 'expired'].includes(metadata.status)) entry.file = undefined
  }
  async function write(entry: Entry, command: AttachmentCommand, expected: number) {
    current(expected)
    assert(!pending)
    pending = { entry, command: structuredClone(command), key: crypto.randomUUID() }
    publish()
    return sendPending(expected)
  }
  async function sendPending(expected: number) {
    const action = pending
    assert(action)
    const metadata = await adapter.write(action.command, action.key, scope.signal)
    current(expected)
    accept(action.entry, metadata)
    pending = undefined
    publish({ denied: false })
  }
  async function read(entry: Entry, expected: number) {
    assert(entry.metadata)
    const metadata = await adapter.get(entry.metadata.id, scope.signal)
    current(expected)
    accept(entry, metadata)
    publish({ denied: false })
  }
  async function continueUpload(entry: Entry, expected: number) {
    await read(entry, expected)
    while (entry.metadata?.status === 'uploading') {
      current(expected)
      const file = entry.file, metadata = entry.metadata
      assert(file && entry.hash && Number.isSafeInteger(metadata.next_chunk_index) && metadata.next_chunk_index >= 0)
      const offset = Number(metadata.received_bytes)
      assert(Number.isSafeInteger(offset) && offset >= 0 && offset <= file.size
        && metadata.next_chunk_index === Math.ceil(offset / chunkSize)
        && (offset === file.size || offset % chunkSize === 0))
      if (offset === file.size) {
        await write(entry, { verb: 'finish', input: { attachment_id: metadata.id } }, expected)
        assert(entries.get(entry.localId)?.metadata?.status === 'ready')
        break
      }
      const bytes = new Uint8Array(await file.slice(offset, offset + chunkSize).arrayBuffer())
      current(expected)
      await write(entry, { verb: 'append', input: { attachment_id: metadata.id,
        chunk_index: metadata.next_chunk_index, content_base64: base64(bytes) } }, expected)
      assert(Number(entry.metadata.received_bytes) === offset + bytes.length
        && entry.metadata.next_chunk_index === metadata.next_chunk_index + 1)
    }
  }
  async function perform(run: (expected: number) => Promise<void>, recovery = false) {
    if (state.busy || (pending && !recovery)) return
    const expected = epoch
    publish({ busy: true, error: undefined })
    try { await run(expected) } catch (error) {
      if (expected !== epoch) return
      const kind = adapter.classify(error)
      const denied = kind === 'denied' || kind === 'denied-unknown'
      if (pending && (pending.unknown || kind === 'unknown' || kind === 'denied-unknown')) pending.unknown = true
      else pending = undefined
      publish({ denied: denied || state.denied,
        error: denied ? '无法确认附件访问权限，已隐藏附件内容。' : pending ? '附件操作结果未知，请使用原操作确认。' : '附件操作未完成，请重新读取后重试。' })
    } finally { if (expected === epoch) publish({ busy: false }) }
  }
  return {
    snapshot: () => state,
    subscribe(listener: () => void) { listeners.add(listener); return () => { listeners.delete(listener) } },
    reset() {
      epoch++; scope.abort(); scope = new AbortController(); pending = undefined; entries.clear()
      for (const url of urls) URL.revokeObjectURL(url)
      urls.clear(); publish({ busy: false, error: undefined, denied: false })
    },
    readyIds(ticketId: string) { return state.denied || pending || state.busy ? [] : Array.from(entries.values())
      .filter(entry => entry.ticketId === ticketId && entry.metadata?.status === 'ready').map(entry => entry.metadata!.id) },
    markAttached(ids: readonly string[]) {
      for (const [key, entry] of entries) if (entry.metadata && ids.includes(entry.metadata.id)) entries.delete(key)
      publish()
    },
    upload(ticketId: string, file: File) {
      return perform(async expected => {
        assert(!state.denied && file instanceof File && file.size > 0 && file.size <= maxSize && types.has(file.type))
        const mediaType = file.type
        assert(supportedType(mediaType))
        safeFilename(file.name)
        const active = Array.from(entries.values()).filter(entry => entry.ticketId === ticketId
          && (!entry.metadata || ['uploading', 'ready'].includes(entry.metadata.status)))
        assert(active.length < 4)
        const entry: Entry = { localId: crypto.randomUUID(), ticketId, file }
        entries.set(entry.localId, entry); publish()
        const content = await file.arrayBuffer()
        current(expected)
        const sha256 = await hash(content)
        current(expected)
        entry.hash = sha256
        await write(entry, { verb: 'begin', input: { ticket_id: ticketId, filename: file.name,
          media_type: mediaType, size_bytes: String(file.size), sha256: entry.hash } }, expected)
        await continueUpload(entry, expected)
      })
    },
    resume(localId: string) {
      return perform(async expected => {
        const entry = entries.get(localId); assert(entry?.metadata)
        await continueUpload(entry, expected)
      })
    },
    cancel(localId: string) {
      return perform(async expected => {
        const entry = entries.get(localId); assert(entry)
        if (!entry.metadata) { entries.delete(localId); publish(); return }
        await write(entry, { verb: 'cancel', input: { attachment_id: entry.metadata.id } }, expected)
      })
    },
    recover() {
      if (state.denied) return Promise.resolve()
      return perform(async expected => {
        const action = pending; assert(action)
        await sendPending(expected)
        if (action.command.verb === 'begin' || action.command.verb === 'append') await continueUpload(action.entry, expected)
      }, true)
    },
    refresh(ticketId: string) {
      if (pending && pending.entry.ticketId !== ticketId) return Promise.resolve()
      return perform(async expected => {
        await adapter.authorize(ticketId, scope.signal)
        current(expected)
        for (const entry of entries.values()) if (entry.ticketId === ticketId && entry.metadata) await read(entry, expected)
        current(expected)
        publish({ denied: false })
      }, true)
    },
    download(metadata: AttachmentMetadata) {
      return perform(async expected => {
        const fresh = await adapter.get(metadata.id, scope.signal)
        current(expected)
        assert(fresh.id === metadata.id && fresh.ticket_id === metadata.ticket_id && fresh.status === 'attached'
          && types.has(fresh.media_type))
        const filename = safeFilename(fresh.filename), size = Number(fresh.size_bytes)
        assert(Number.isSafeInteger(size) && size > 0 && size <= maxSize && /^[0-9a-f]{64}$/.test(fresh.sha256))
        const output = new Uint8Array(size)
        for (let index = 0, offset = 0; offset < size; index++) {
          const part = await adapter.chunk(fresh.id, index, scope.signal)
          current(expected)
          const bytes = decode(part.content_base64), end = Math.min(size, offset + chunkSize)
          assert(part.attachment_id === fresh.id && part.chunk_index === index && bytes.length === end - offset
            && part.last === (end === size) && await hash(bytes.buffer) === part.sha256)
          current(expected); output.set(bytes, offset); offset = end
        }
        assert(await hash(output.buffer) === fresh.sha256)
        current(expected)
        const url = URL.createObjectURL(new Blob([output], { type: 'application/octet-stream' }))
        urls.add(url)
        const anchor = document.createElement('a'); anchor.href = url; anchor.download = filename; anchor.rel = 'noopener'
        document.body.append(anchor)
        try { anchor.click() } finally { anchor.remove(); setTimeout(() => { URL.revokeObjectURL(url); urls.delete(url) }, 1000) }
      })
    },
  }
}
