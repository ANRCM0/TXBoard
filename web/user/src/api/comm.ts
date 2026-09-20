import { api, request } from './client'

export type GuestConfig = {
  app_name?: string
  app_description?: string
  app_url?: string
  logo?: string
  stop_register?: number
  register_enable?: number
  is_email_verify?: number
  is_invite_force?: number
  email_whitelist_suffix?: string[] | 0
  tos_url?: string
  is_captcha?: number
  captcha_type?: string
  recaptcha_site_key?: string
  recaptcha_v3_site_key?: string
  recaptcha_v3_score_threshold?: number
  turnstile_site_key?: string
  telegram_login_enable?: number
  telegram_bot_username?: string
  telegram_login_domain?: string
  telegram_login_endpoint?: string
  login_with_mail_link_enable?: number
  invite_enable?: number
  commission_enable?: number
  gift_card_enable?: number
  coupon_enable?: number
  ticket_enable?: number
  knowledge_enable?: number
  traffic_log_enable?: number
  announcement_enable?: number
  try_out_plan_id?: number
  try_out_enable?: number
  traffic_warn_rate?: number
}

export type UserCommConfig = {
  is_telegram?: number
  telegram_discuss_link?: string
  stripe_pk?: string
  withdraw_methods?: string[]
  withdraw_close?: number
  currency?: string
  currency_symbol?: string
  commission_distribution_enable?: number
  commission_distribution_l1?: number | string
  commission_distribution_l2?: number | string
  commission_distribution_l3?: number | string
  commission_withdraw_limit?: number | string
  try_out_plan_id?: number
  try_out_enable?: number
  traffic_warn_rate?: number
  ticket_must_wait_reply?: number
  plan_change_enable?: number
  invite_enable?: number
  commission_enable?: number
  gift_card_enable?: number
  coupon_enable?: number
  register_enable?: number
  ticket_enable?: number
  knowledge_enable?: number
  traffic_log_enable?: number
  announcement_enable?: number
}

export type CaptchaPayload = {
  recaptcha_data?: string
  recaptcha_v3_token?: string
  turnstile_token?: string
  skip_recaptcha_v3?: boolean
  skip_recaptcha_v3_error?: boolean
}

export async function fetchGuestConfig() {
  return request<GuestConfig>(api.get('/guest/comm/config'))
}

export async function fetchUserCommConfig() {
  return request<UserCommConfig>(api.get('/user/comm/config'))
}

export async function fetchStripePublicKey(paymentId: number) {
  return request<string>(api.post('/user/comm/getStripePublicKey', { id: paymentId }))
}
