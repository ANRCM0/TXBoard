export type ApiEnvelope<T> = {
  data?: T
  message?: string
  code?: number
}

export function unwrap<T>(payload: T | ApiEnvelope<T>): T {
  if (payload && typeof payload === 'object' && 'data' in (payload as object)) {
    return ((payload as ApiEnvelope<T>).data ?? payload) as T
  }
  return payload as T
}
