import { nativeApiClient, nativeAdminPath, unwrapNative, type NativeApiEnvelope } from './client'

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
  return (await unwrapNative(nativeApiClient.get<NativeApiEnvelope<MailTemplateSummary[]>>(nativeAdminPath('mail-templates')))) || []
}

export async function getMailTemplate(name: string) {
  return unwrapNative(nativeApiClient.get<NativeApiEnvelope<MailTemplateDetail>>(
    nativeAdminPath('mail-templates') + '/' + encodeURIComponent(name),
  ))
}

export async function saveMailTemplate(payload: { name: string; subject: string; content: string }) {
  return unwrapNative(nativeApiClient.put<NativeApiEnvelope<{ ok: boolean }>>(
    nativeAdminPath('mail-templates') + '/' + encodeURIComponent(payload.name),
    { subject: payload.subject, content: payload.content },
  ))
}

export async function resetMailTemplate(name: string) {
  return unwrapNative(nativeApiClient.delete<NativeApiEnvelope<{ ok: boolean }>>(
    nativeAdminPath('mail-templates') + '/' + encodeURIComponent(name),
  ))
}

export async function testMailTemplate(name: string, email?: string) {
  return unwrapNative(nativeApiClient.post<NativeApiEnvelope<{ ok: boolean }>>(
    nativeAdminPath('mail-templates') + '/' + encodeURIComponent(name) + '/test',
    email ? { email } : {},
  ))
}
