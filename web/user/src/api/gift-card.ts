import { nativeApi, nativeRequest } from './client'

export type GiftCardRewards = {
  balance?: number
  transfer_enable?: number
  expire_days?: number
  device_limit?: number
  reset_package?: boolean
  plan_id?: number
  plan_validity_days?: number
  invite_reward_rate?: number
  [key: string]: unknown
}

export type GiftCardCheckResult = {
  code_info: {
    code: string
    template: {
      name: string
      description?: string
      type: number
      type_name: string
      icon?: string
      background_image?: string
      theme_color?: string
    }
    status: number
    status_name: string
    expires_at?: number | null
    usage_count?: number
    max_usage?: number
  }
  reward_preview: GiftCardRewards
  can_redeem: boolean
  reason?: string | null
}

export type GiftCardHistoryItem = {
  id: number
  code: string
  template_name: string
  template_type: number | string
  template_type_name: string
  rewards_given: GiftCardRewards
  invite_rewards?: GiftCardRewards
  multiplier_applied?: number
  created_at?: number
}

export async function checkGiftCard(code: string) {
  return nativeRequest<GiftCardCheckResult>(nativeApi.post('/gift-cards/check', { code }))
}

export async function redeemGiftCard(code: string) {
  return nativeRequest<{
    message: string
    rewards: GiftCardRewards
    invite_rewards?: GiftCardRewards
    template_name: string
  }>(nativeApi.post('/gift-cards/redeem', { code }))
}

export type GiftCardDetail = {
  id: number
  code: string
  template: {
    name: string
    description?: string
    type: number
    type_name: string
  }
  rewards_given: GiftCardRewards
  invite_rewards?: GiftCardRewards
  invite_user?: { id: number | string; email: string } | null
  user_level_at_use?: number
  plan_id_at_use?: number
  multiplier_applied?: number
  notes?: string
  created_at?: number
}

export async function fetchGiftCardDetail(id: number) {
  return nativeRequest<GiftCardDetail>(nativeApi.get(`/gift-cards/history/${id}`))
}

export async function fetchGiftCardHistory(params: { page?: number; per_page?: number } = {}) {
  return request<{
    data: GiftCardHistoryItem[]
    pagination: {
      current_page: number
      last_page: number
      per_page: number
      total: number
    }
  }>(nativeApi.get('/gift-cards/history', { params }))
}
