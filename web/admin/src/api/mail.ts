import { apiClient } from './client'
import { unwrap } from '../lib/api'

export type MailTemplateSummary = {
  name: string
  label: string
  customized: boolean
  subject?: string | null
  updated_at?: number | null
}

export type MailTemplateDetail = {
  name: string
  label: string
  required_vars: string[]
  optional_vars: string[]
  customized: boolean
  subject: string
  content: string
}

export async function listMailTemplates() {
  const { data } = await apiClient.get('/mail/template/list')
  return unwrap<MailTemplateSummary[]>(data) || []
}

export async function getMailTemplate(name: string) {
  const { data } = await apiClient.get('/mail/template/get', { params: { name } })
  return unwrap<MailTemplateDetail>(data)
}

export async function saveMailTemplate(payload: { name: string; subject: string; content: string }) {
  const { data } = await apiClient.post('/mail/template/save', payload)
  return unwrap(data)
}

export async function resetMailTemplate(name: string) {
  const { data } = await apiClient.post('/mail/template/reset', { name })
  return unwrap(data)
}

export async function testMailTemplate(name: string, email?: string) {
  const { data } = await apiClient.post('/mail/template/test', { name, ...(email ? { email } : {}) })
  return unwrap(data)
}
