import { nativeApiClient, nativeAdminPath, unwrapNative, type NativeApiEnvelope } from './client'

export type QueueSnapshot = {
  status: 'running' | 'paused' | 'inactive' | 'unavailable' | 'not_applicable'
  connection: string
  processes: number | null
  recent_jobs: number | null
  pending_jobs: number | null
  jobs_per_minute: number | null
  wait_seconds: number | null
  longest_wait_queue: string | null
  failed_last_7_days: number | null
  failed_jobs_available: boolean
  observed_at: string
  message?: string | null
}

export type QueueFailure = {
  id: number
  connection: string
  queue: string
  job: string
  message: string
  failed_at: string
}
export type QueueFailureDetail = Omit<QueueFailure, 'message'> & { exception: string }

export async function getQueueSnapshot(): Promise<QueueSnapshot> {
  return unwrapNative(nativeApiClient.get<NativeApiEnvelope<QueueSnapshot>>(nativeAdminPath('queue') + '/snapshot'))
}
export async function getQueueFailures(): Promise<QueueFailure[]> {
  return (await unwrapNative(nativeApiClient.get<NativeApiEnvelope<QueueFailure[]>>(
    nativeAdminPath('queue') + '/failures', { params: { limit: 10 } },
  ))) || []
}
export async function getQueueFailure(id: number): Promise<QueueFailureDetail> {
  if (!Number.isSafeInteger(id) || id < 1) throw new Error('Invalid queue failure ID')
  return unwrapNative(nativeApiClient.get<NativeApiEnvelope<QueueFailureDetail>>(
    nativeAdminPath('queue') + '/failures/' + id,
  ))
}
