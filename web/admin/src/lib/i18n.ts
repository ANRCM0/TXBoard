import { useSyncExternalStore } from 'react'

export const LOCALES = ['zh-CN', 'en-US', 'ru-RU'] as const
export type Locale = typeof LOCALES[number]
export type TranslationVariables = Record<string, string | number>
type Messages = Record<string, string>
const storageKey = 'i18nextLng'
const listeners = new Set<() => void>()
const resources = new Map<Locale, Messages>([['zh-CN', {}]])
const pending = new Map<Locale, Promise<Messages>>()
let snapshot: { locale: Locale; loading: boolean } = { locale: 'zh-CN', loading: false }
let sequence = 0

export function resolveLocale(value: string | null | undefined): Locale | undefined {
  const language = value?.trim().replace('_', '-').toLowerCase().split('-')[0]
  return language === 'zh' ? 'zh-CN' : language === 'en' ? 'en-US' : language === 'ru' ? 'ru-RU' : undefined
}

/** Matches the reference: explicit query, saved local choice, then browser. */
export function detectLocale(): Locale {
  if (typeof window === 'undefined') return 'zh-CN'
  const explicit = resolveLocale(new URLSearchParams(window.location.search).get('lang'))
  if (explicit) return explicit
  try { const saved = resolveLocale(window.localStorage.getItem(storageKey)); if (saved) return saved } catch { /* A blocked storage area must not block the UI. */ }
  for (const language of navigator.languages?.length ? navigator.languages : [navigator.language]) {
    const resolved = resolveLocale(language)
    if (resolved) return resolved
  }
  return 'zh-CN'
}

function publish(locale: Locale, loading: boolean) {
  snapshot = { locale, loading }
  if (typeof document !== 'undefined') document.documentElement.lang = locale
  for (const listener of listeners) listener()
}
function loadMessages(locale: Locale): Promise<Messages> {
  const existing = resources.get(locale)
  if (existing) return Promise.resolve(existing)
  let loading = pending.get(locale)
  if (!loading) {
    loading = (locale === 'en-US' ? import('../locales/en-US.json') : import('../locales/ru-RU.json'))
      .then(module => { resources.set(locale, module.default); return module.default })
      .catch(error => { pending.delete(locale); throw error })
    pending.set(locale, loading)
  }
  return loading
}

export function getLocale(): Locale { return snapshot.locale }
export async function setLocale(locale: Locale): Promise<void> {
  if (!LOCALES.includes(locale)) throw new RangeError('Unsupported locale')
  const owner = ++sequence
  publish(snapshot.locale, true)
  try {
    await loadMessages(locale)
    if (owner !== sequence) return
    try { window.localStorage.setItem(storageKey, locale) } catch { /* Selection remains usable without persistence. */ }
    publish(locale, false)
  } catch (error) { if (owner === sequence) publish(snapshot.locale, false); throw error }
}

/** Source messages are explicit catalog keys; missing entries retain readable Chinese. */
export function t(source: string, variables?: TranslationVariables): string {
  const catalog = resources.get(snapshot.locale)
  const message = catalog && Object.prototype.hasOwnProperty.call(catalog, source) ? catalog[source] : source
  return variables ? message.replace(/\{([A-Za-z0-9_]+)\}/g, (match, key: string) => String(variables[key] ?? match)) : message
}
export const translate = t
export class LocalizedError extends Error {
  constructor(source: string, readonly variables?: TranslationVariables) { super(source) }
  localizedMessage() {
    const variables = this.variables && Object.fromEntries(Object.entries(this.variables).map(([key, value]) => [key, typeof value === 'string' ? t(value) : value]))
    return t(this.message, variables)
  }
}
export function formatNumber(value: number | bigint, options?: Intl.NumberFormatOptions): string {
  return new Intl.NumberFormat(snapshot.locale, options).format(value)
}
export function formatDate(value: Date | string | number, options: Intl.DateTimeFormatOptions = { year: 'numeric', month: '2-digit', day: '2-digit' }): string {
  const date = value instanceof Date ? value : new Date(value)
  return Number.isFinite(date.getTime()) ? new Intl.DateTimeFormat(snapshot.locale, options).format(date) : '—'
}
export function formatTimestamp(seconds: number | null | undefined, options?: Intl.DateTimeFormatOptions): string {
  return seconds == null ? '—' : formatDate(seconds * 1000, options ?? { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' })
}
const subscribe = (listener: () => void) => { listeners.add(listener); return () => { listeners.delete(listener) } }
const read = () => snapshot
export function useLocale() {
  const current = useSyncExternalStore(subscribe, read, read)
  return { ...current, t, setLocale, formatDate, formatNumber, formatTimestamp }
}

if (typeof window !== 'undefined') {
  document.documentElement.lang = snapshot.locale
  const detected = detectLocale()
  if (detected !== snapshot.locale) void setLocale(detected).catch(() => { /* The readable source language remains available offline. */ })
  window.addEventListener('storage', event => {
    if (event.key === storageKey) { const locale = resolveLocale(event.newValue); if (locale) void setLocale(locale).catch(() => {}) }
  })
}
