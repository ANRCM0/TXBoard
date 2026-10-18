import {
  createCoreAdminClient, CoreAdminApiError, type CoreAdminOperation,
} from './admin.js'
import type { CoreInput, CoreOutput } from './contracts.generated.js'
import type { CoreResult } from './client.js'

export type CoreAdminSessionOptions = Readonly<{
  baseURL?: string
  fetchImpl?: typeof fetch
  onSessionEnded?: () => void
}>
export class StaleAdminContext extends Error {
  constructor() { super('ADMIN_CONTEXT_CHANGED'); this.name = 'StaleAdminContext' }
}
type AdminReadOperation = Exclude<CoreAdminOperation,
  'identity.admin-session.create' | 'identity.sessions.revoke' | 'identity.session.end'>

/** Memory-only administrator context; a change aborts all requests from the old identity. */
export function createCoreAdminSession(options: CoreAdminSessionOptions = {}) {
  let session: { bearer: string; adminPath: string } | undefined
  let generation = 0
  let scope = new AbortController()
  const send = options.fetchImpl ?? fetch
  const client = createCoreAdminClient({ baseURL: options.baseURL,
    getToken: () => session?.bearer, getAdminPath: () => session?.adminPath,
    fetchImpl: (url, init) => send(url, init) })

  function reset(notify = true) {
    generation++
    scope.abort()
    scope = new AbortController()
    session = undefined
    if (notify) options.onSessionEnded?.()
  }
  async function current<T>(run: (signal: AbortSignal) => Promise<T>, signal?: AbortSignal): Promise<T> {
    const expected = generation
    const combined = signal ? AbortSignal.any([scope.signal, signal]) : scope.signal
    try {
      const result = await run(combined)
      if (generation !== expected) throw new StaleAdminContext()
      return result
    } catch (error) {
      if (generation !== expected) throw new StaleAdminContext()
      if (error instanceof CoreAdminApiError && error.status === 401) reset()
      throw error
    }
  }

  return {
    hasSession: () => session !== undefined,
    async login(input: CoreInput<'identity.admin-session.create'>, actionKey: string) {
      reset(false)
      const result = await current(signal => client.call('identity.admin-session.create', input,
        { signal, idempotencyKey: actionKey }))
      session = { bearer: result.auth_data, adminPath: result.secure_path }
      // Credentials remain private to this closure. UI receives only a role confirmation.
      return { isAdmin: true as const }
    },
    capabilities() { return current(signal => client.capabilities({ signal })) },
    read<K extends AdminReadOperation>(operation: K, input: CoreInput<K>, signal?: AbortSignal): Promise<CoreResult<K>> {
      return current(signal => client.callResult(operation, input, { signal }), signal)
    },
    revokeSession(sessionId: string, actionKey: string, signal?: AbortSignal): Promise<CoreOutput<'identity.sessions.revoke'>> {
      return current(signal => client.call('identity.sessions.revoke', { session_id: sessionId },
        { signal, idempotencyKey: actionKey }), signal)
    },
    async logout(actionKey: string): Promise<{ serverConfirmed: boolean }> {
      const expected = generation
      try {
        await current(signal => client.call('identity.session.end', {}, { signal, idempotencyKey: actionKey }))
        return { serverConfirmed: true }
      } catch (error) {
        if (error instanceof StaleAdminContext) throw error
        return { serverConfirmed: error instanceof CoreAdminApiError && error.status === 401 }
      } finally { if (generation === expected) reset() }
    },
    forgetSession() { reset() },
  }
}

export type CoreAdminSession = ReturnType<typeof createCoreAdminSession>
