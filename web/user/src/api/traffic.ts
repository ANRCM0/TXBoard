import { nativeApi, type NativeEnvelope } from './client'

export type TrafficLogItem = {
  id: number
  u: number
  d: number
  record_at: number
  server_rate: number | null
}
type NativeTrafficLog = {
  id: number
  upload_bytes: number
  download_bytes: number
  record_at: number
  server_rate: number | null
}
export type TrafficPage = {
  data: TrafficLogItem[]
  total: number
  page: number
  per_page: number
}

export async function fetchTrafficLog(page = 1, pageSize = 20): Promise<TrafficPage> {
  const { data: response } = await nativeApi.get<NativeEnvelope<NativeTrafficLog[]>>('/traffic/logs', {
    params: { page, per_page: pageSize },
  })
  if (!response?.request_id || !Array.isArray(response.data) || !response.meta ||
      !Number.isSafeInteger(response.meta.total) ||
      !Number.isSafeInteger(response.meta.page) ||
      !Number.isSafeInteger(response.meta.per_page)) {
    throw new Error('Invalid TXAPI traffic pagination')
  }
  return {
    data: response.data.map(item => {
      if (!Number.isSafeInteger(item.id) || !Number.isSafeInteger(item.record_at) ||
          !Number.isSafeInteger(item.upload_bytes) || item.upload_bytes < 0 ||
          !Number.isSafeInteger(item.download_bytes) || item.download_bytes < 0 ||
          (item.server_rate !== null && (!Number.isFinite(item.server_rate) || item.server_rate < 0))) {
        throw new Error('Invalid TXAPI traffic record')
      }
      return { id: item.id, u: item.upload_bytes, d: item.download_bytes,
        record_at: item.record_at, server_rate: item.server_rate }
    }),
    total: response.meta.total, page: response.meta.page, per_page: response.meta.per_page,
  }
}
