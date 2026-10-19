import { readonly, shallowRef } from 'vue'
import { createCoreUserClient, CoreUserApiError, type CoreInput, type CoreOperation, type CoreOutput, type CoreResult } from '@txboard/core-sdk'

export class StaleUserSession extends Error {}
export function createUserSession(fetchImpl?: typeof fetch) {
  let bearer: string | undefined, generation = 0, scope = new AbortController()
  const authenticated = shallowRef(false)
  const client = createCoreUserClient({ getToken: () => bearer, fetchImpl })
  const changes = new Set<() => void>()
  function clear() {
    generation++; bearer = undefined; scope.abort(); scope = new AbortController()
    authenticated.value = false
    for (const notify of changes) notify()
  }
  async function call<K extends CoreOperation>(operation: K, input: CoreInput<K>, key?: string, signal?: AbortSignal): Promise<CoreResult<K>> {
    const expected = generation
    try {
      const result = await client.callResult(operation, input, { idempotencyKey: key,
        signal: signal ? AbortSignal.any([scope.signal, signal]) : scope.signal })
      if (expected !== generation) throw new StaleUserSession()
      return result
    } catch (error) {
      if (expected !== generation) throw new StaleUserSession()
      if (error instanceof CoreUserApiError && error.status === 401) clear()
      throw error
    }
  }
  return { authenticated: readonly(authenticated), clear, call,
    onClear(notify: () => void) { changes.add(notify); return () => { changes.delete(notify) } },
    async login(input: CoreInput<'identity.session.create'>, key: string) {
      clear()
      const result = await call('identity.session.create', input, key)
      bearer = result.data.auth_data; authenticated.value = true
    },
    async logout() { try { await call('identity.session.end', {}, crypto.randomUUID()) } finally { clear() } },
  }
}
export const user = createUserSession()

export function userError(error: unknown): string {
  if (error instanceof StaleUserSession) return '登录状态已改变，请重新读取。'
  if (error instanceof CoreUserApiError) {
    if (error.status === 401) return '登录已失效，请重新登录。'
    if (error.status === 403) return '当前账号没有此操作的权限。'
    if (error.code === 'CAPTCHA_REQUIRED') return '当前登录要求验证码，此验证码配置暂不支持，请联系管理员。'
    if (error.needsStateConfirmation) return '操作结果未知。请使用原操作确认结果，勿重复发起新操作。'
    if (error.status === 404) return '未找到该记录。'
    if (error.status === 422) return '输入未通过验证，请检查后重试。'
    if (error.status === 429) return '请求过于频繁，请稍后重试。'
  }
  return '请求暂不可用，请稍后重试。'
}

type Mutation = 'commerce.orders.create' | 'commerce.orders.checkout' | 'commerce.orders.cancel' | 'billing.payment-attempts.query'
export type PendingAction = { [K in Mutation]: { operation: K; input: CoreInput<K>; key: string; unknown: boolean } }[Mutation]
/** One immutable input and UUID survive lost responses and route changes, in memory only. */
export function createActions(session: ReturnType<typeof createUserSession>) {
  const pending = shallowRef<PendingAction>()
  let busy = false
  session.onClear(() => { pending.value = undefined })
  return { pending: readonly(pending),
    async run<K extends Mutation>(operation: K, input: CoreInput<K>): Promise<CoreOutput<K>> {
      if (busy) throw new Error('ACTION_BUSY')
      const old = pending.value
      if (old && (old.operation !== operation || JSON.stringify(old.input) !== JSON.stringify(input))) throw new Error('ACTION_RECOVERY_REQUIRED')
      const action: PendingAction = old ?? { operation, input: structuredClone(input), key: crypto.randomUUID(), unknown: false } as PendingAction
      pending.value = action; busy = true
      try {
        const result = await session.call(operation, action.input as CoreInput<K>, action.key)
        pending.value = undefined
        return result.data
      } catch (error) {
        if (error instanceof CoreUserApiError && error.needsStateConfirmation) {
          pending.value = { ...action, unknown: true }
        } else if (!(error instanceof StaleUserSession)) pending.value = undefined
        throw error
      } finally { busy = false }
    },
  }
}
export const actions = createActions(user)
export function money(value: string, currency: string) {
  const padded = value.padStart(3, '0')
  return `${padded.slice(0, -2)}.${padded.slice(-2)} ${currency}`
}
export const orderStatus = (value: number) => ['待支付', '支付已确认，等待履约', '已取消', '履约已完成', '已关闭'][value] ?? '状态未知'
export function paymentURL(value: string): string | undefined {
  try { const url = new URL(value); return url.protocol === 'https:' && !url.username && !url.password ? url.href : undefined } catch { return undefined }
}
