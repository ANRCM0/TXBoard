import { act, useState } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, expect, it } from 'vitest'
import { detectLocale, formatDate, formatNumber, getLocale, LocalizedError, resolveLocale, setLocale, t, useLocale } from '../lib/i18n'
import { ManagementFeedback, managementError } from './Feedback'
import { configurationDescriptions, configurationHints, configurationChoices, configurationSection, mailSample, mailVariableHelp } from './configurationPresentation'
import metadata from '../../../../contracts/core-api/admin-settings.v1.json'
import english from '../locales/en-US.json'
import russian from '../locales/ru-RU.json'

let host: HTMLDivElement, root: Root
beforeEach(async () => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  await setLocale('zh-CN')
  window.history.replaceState({}, '', '/')
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
})
afterEach(async () => {
  act(() => root.unmount()); host.remove()
  await setLocale('zh-CN')
  window.history.replaceState({}, '', '/')
})

it('normalizes supported variants and applies query then storage then browser detection', () => {
  expect(resolveLocale('ru_RU')).toBe('ru-RU')
  expect(resolveLocale('EN-gb')).toBe('en-US')
  expect(resolveLocale('fr-FR')).toBeUndefined()
  window.localStorage.setItem('i18nextLng', 'ru-RU')
  expect(detectLocale()).toBe('ru-RU')
  window.history.replaceState({}, '', '/?lang=en-GB')
  expect(detectLocale()).toBe('en-US')
  window.history.replaceState({}, '', '/?lang=unsupported')
  expect(detectLocale()).toBe('ru-RU')
  window.localStorage.removeItem('i18nextLng')
  expect(detectLocale()).toBe(resolveLocale(navigator.languages[0]) ?? 'zh-CN')
})

it('persists the last selection, updates document language and keeps interpolation inert', async () => {
  await setLocale('en-US')
  expect(document.documentElement.lang).toBe('en-US')
  expect(window.localStorage.getItem('i18nextLng')).toBe('en-US')
  expect(t('站点名称')).toBe('Site Name')
  expect(t('未收录的 {name}', { name: '<script>' })).toBe('未收录的 <script>')
  await Promise.all([setLocale('ru-RU'), setLocale('zh-CN')])
  expect(getLocale()).toBe('zh-CN')
  expect(t('站点名称')).toBe('站点名称')
})

it('updates cached errors and synthetic mail examples without discarding component state', async () => {
  function State() {
    useLocale()
    const [error] = useState(() => managementError(new Error('offline'), false))
    const [draft] = useState('unsaved {{name}}')
    return <><p>{t(error)}</p><input value={draft} readOnly /><ManagementFeedback error={new Error('offline')} onRetry={() => {}} /><span>{mailSample('{{name}}: {{content}}')}</span></>
  }
  await act(async () => root.render(<State />))
  expect(host.textContent).toContain('请求失败，请检查网络后重试。')
  await act(async () => { await setLocale('en-US') })
  expect(host.textContent).toContain(english['请求失败，请检查网络后重试。'])
  expect(host.textContent).toContain('Example Site: This is a sample notification email.')
  expect(host.querySelector('input')!.value).toBe('unsaved {{name}}')
  await act(async () => { await setLocale('ru-RU') })
  expect(host.textContent).toContain(russian['请求失败，请检查网络后重试。'])
  expect(host.textContent).toContain('Пример сайта')
  expect(host.querySelector('input')!.value).toBe('unsaved {{name}}')
})

it('uses the selected locale for number and date formatting', async () => {
  const value = new Date('2026-10-21T12:34:00Z')
  for (const locale of ['en-US', 'ru-RU'] as const) {
    await setLocale(locale)
    expect(formatNumber(12345.67)).toBe(new Intl.NumberFormat(locale).format(12345.67))
    expect(formatDate(value, { timeZone: 'UTC', dateStyle: 'long' })).toBe(new Intl.DateTimeFormat(locale, { timeZone: 'UTC', dateStyle: 'long' }).format(value))
  }
  expect(formatDate('invalid')).toBe('—')
})

it('retains validation source and field labels when language changes after the failure', async () => {
  const error = new LocalizedError('{label}须为非负整数', { label: '流量' })
  expect(error.localizedMessage()).toBe('流量须为非负整数')
  await setLocale('en-US')
  expect(error.localizedMessage()).toBe('Traffic must be a non-negative integer')
  await setLocale('ru-RU')
  expect(error.localizedMessage()).toBe('Трафик: требуется неотрицательное целое число')
  expect(error.message).toBe('{label}须为非负整数')
})

it('covers all configuration labels, descriptions, help and bounded choice labels in both catalogs', () => {
  const keys = [
    ...Object.values(metadata.fields).map(field => field.label),
    ...Object.values(configurationDescriptions), ...Object.values(configurationHints),
    ...Object.values(mailVariableHelp).flatMap(value => [value.description, value.example]),
    ...Object.entries(metadata.fields).map(([name, field]) => configurationSection(name, field.group as Parameters<typeof configurationSection>[1])),
    ...['reset_traffic_method', 'new_order_event_id'].flatMap(name => configurationChoices(name)?.map(([, label]) => label) ?? []),
  ].filter(key => /[\u3400-\u9fff]/.test(key))
  for (const catalog of [english, russian]) {
    for (const key of keys) expect((catalog as Record<string, string>)[key], key).toBeTruthy()
    for (const [key, translated] of Object.entries(catalog)) {
      expect(translated.match(/\{[A-Za-z0-9_]+\}/g)?.sort() ?? [], key).toEqual(key.match(/\{[A-Za-z0-9_]+\}/g)?.sort() ?? [])
    }
  }
})
