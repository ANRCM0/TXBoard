import { api, request } from './client'

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
  return request<InviteStat>(api.get('/user/invite/fetch'))
}

export async function generateInviteCode() {
  return request<boolean>(api.get('/user/invite/save'))
}

export async function transferCommission(transfer_amount: number) {
  return request<null>(api.post('/user/transfer', { transfer_amount }))
}

export async function withdrawCommission(payload:{withdraw_method:string;withdraw_account:string}) {
  return request<null>(api.post('/user/ticket/withdraw', payload))
}

export async function fetchInviteDetails(current = 1, pageSize = 10) {
  return request<{
    data: Array<{ created_at?: number; get_amount?: number }>
    total: number
    current_page?: number
    page_size?: number
  }>(api.get('/user/invite/details', { params: { current, page_size: pageSize } }))
}
