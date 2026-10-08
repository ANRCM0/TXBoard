import { apiClient } from './client'
import { unwrap } from '../lib/api'

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
  const { data } = await apiClient.get('/stat/queue/snapshot')
  return unwrap<QueueSnapshot>(data)
}
export async function getQueueFailures(): Promise<QueueFailure[]> {
  const { data } = await apiClient.get('/stat/queue/failures', { params: { limit: 10 } })
  return unwrap<QueueFailure[]>(data) || []
}
export async function getQueueFailure(id: number): Promise<QueueFailureDetail> {
  const { data } = await apiClient.get('/stat/queue/failure', { params: { id } })
  return unwrap<QueueFailureDetail>(data)
}
