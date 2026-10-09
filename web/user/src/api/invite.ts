import { nativeApi, nativeRequest, type NativeEnvelope } from './client'

export type InviteCode = {
  code: string
  pv: number
  status: number
  created_at?: number
}

export type InviteStat = {
  codes: InviteCode[]
  stat: number[]
}

export async function fetchInvite() {
  return nativeRequest<InviteStat>(nativeApi.get('/invites'))
}

export async function generateInviteCode() {
  return nativeRequest<boolean>(nativeApi.post('/invites'))
}

export async function transferCommission(transfer_amount: number) {
  return nativeRequest<boolean>(nativeApi.post('/billing/commission-transfer', { transfer_amount }))
}

export async function withdrawCommission(payload:{withdraw_method:string;withdraw_account:string}) {
  return nativeRequest<{ ok: boolean }>(nativeApi.post('/billing/withdrawals', payload))
}

export async function fetchInviteDetails(current = 1, pageSize = 10) {
  const { data: result } = await nativeApi.get<NativeEnvelope<Array<{
    id: number; trade_no: string; earned_minor: number; created_at: string
  }>>>('/billing/commissions', {
    params: { page: current, per_page: pageSize },
  })
  if (!result?.request_id || !Array.isArray(result.data) ||
      !result.meta || !Number.isInteger(result.meta.total)) {
    throw new Error('Invalid TXAPI commission history')
  }
  return {
    data: result.data.map(item => {
      const timestamp = Date.parse(item.created_at)
      if (!Number.isFinite(timestamp)) throw new Error('Invalid TXAPI commission date')
      return { id: item.id, trade_no: item.trade_no, get_amount: item.earned_minor,
        created_at: Math.floor(timestamp / 1000) }
    }),
    total: result.meta.total,
    current_page: result.meta.page,
    page_size: result.meta.per_page,
  }
}
