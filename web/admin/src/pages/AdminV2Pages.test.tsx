import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, type ReactNode } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { getModuleRegistry } from '../api/module'
import { getPlugins, enablePlugin } from '../api/plugin'
import { getThemes } from '../api/theme'
import { saveSettings } from '../api/config'
import { ModuleCenterPage } from './system/ModuleCenterPage'
import { PluginsPage } from './plugins/PluginsPage'
import { ThemeSettingsPage } from './config/ThemeSettingsPage'

vi.mock('../api/module', () => ({ getModuleRegistry: vi.fn() }))
vi.mock('../api/plugin', () => ({
  getPlugins: vi.fn(),
  enablePlugin: vi.fn().mockResolvedValue({}),
  disablePlugin: vi.fn().mockResolvedValue({}),
  installPlugin: vi.fn().mockResolvedValue({}),
  uninstallPlugin: vi.fn().mockResolvedValue({}),
  upgradePlugin: vi.fn().mockResolvedValue({}),
  deletePlugin: vi.fn().mockResolvedValue({}),
  uploadPlugin: vi.fn().mockResolvedValue({}),
}))
vi.mock('../api/theme', () => ({
  getThemes: vi.fn(),
  getThemeConfig: vi.fn().mockResolvedValue({}),
  deleteTheme: vi.fn().mockResolvedValue({}),
  saveThemeConfig: vi.fn().mockResolvedValue({}),
  uploadTheme: vi.fn().mockResolvedValue({}),
  uploadThemeV2: vi.fn().mockResolvedValue({theme:'example'}),
  rollbackThemeV2: vi.fn().mockResolvedValue({}),
}))
vi.mock('../api/config', () => ({ saveSettings: vi.fn().mockResolvedValue({}) }))
vi.mock('../components/ui/ConfirmDialog', () => ({ requestConfirm: vi.fn() }))
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }))

let root: Root
let host: HTMLDivElement
let client: QueryClient

async function mount(child: ReactNode) {
  await act(async () => {
    root.render(
      <QueryClientProvider client={client}>
        <MemoryRouter>{child}</MemoryRouter>
      </QueryClientProvider>,
    )
  })
  await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)) })
}

beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  vi.clearAllMocks()
  host = document.createElement('div')
  document.body.append(host)
  root = createRoot(host)
  client = new QueryClient({defaultOptions:{queries:{retry:false}}})
})

afterEach(() => {
  act(() => root.unmount())
  client.clear()
  host.remove()
})

it('module center shows a compact five-item summary with diagnostics available via details', async () => {
  vi.mocked(getModuleRegistry).mockResolvedValue({
    modules: [{
      id:'agent.ops', name:'Agent Ops', version:'1.0.0', type:'agent', source:'system',
      description:'Runtime operations', author:'TXBoard', installed:true, enabled:true, active:true,
      health:'healthy', capabilities:['agent.api','agent.admin'],
      compatibility:{txboard:'*'},
      health_details:{checks:{schedule:true,websocket_server:true},observed_at:1720000000},
    }],
    errors:[],
    summary:{ total:1, health:{healthy:1,degraded:0,disabled:0,failed:0,incompatible:0,missing_dependency:0}, discovery_errors:0 },
  })
  await mount(<ModuleCenterPage/>)
  expect(host.querySelectorAll('.admin-v2-summary-item')).toHaveLength(5)
  expect(host.querySelector('.dashboard-stats')).toBeNull()
  expect(host.querySelector('.admin-v2-module-table')).not.toBeNull()
  const details = host.querySelector('details.admin-v2-detail')
  expect(details?.textContent).toContain('agent.api')
  expect(details?.textContent).toContain('Scheduler：正常')
  expect(details?.textContent).toContain('所有版本')
  expect(host.querySelectorAll('.admin-v2-module-table .badge')).toHaveLength(0)
})

it('plugins render compact management rows while retaining enabled actions', async () => {
  vi.mocked(getPlugins).mockResolvedValue([
    {code:'e-pay',name:'EPay',version:'1.0.0',type:'payment',description:'易支付',is_installed:true,is_enabled:false},
    {code:'telegram',name:'Telegram',version:'1.0.1',type:'feature',is_installed:true,is_enabled:true},
  ])
  await mount(<PluginsPage/>)
  expect(host.querySelectorAll('.admin-v2-plugin-row')).toHaveLength(2)
  expect(host.querySelector('.plugin-grid')).toBeNull()
  expect(host.querySelector('.plugin-card')).toBeNull()
  expect(host.textContent).toContain('易支付')
  const epay = host.querySelectorAll('.admin-v2-plugin-row')[0]
  expect(epay.textContent).toContain('未启用')
  const enable = Array.from(epay.querySelectorAll<HTMLButtonElement>('button')).find(b => b.textContent?.trim()==='启用')
  expect(enable).toBeDefined()
  await act(async () => { enable!.click(); await Promise.resolve() })
  expect(enablePlugin).toHaveBeenCalledWith('e-pay')
})

it('themes distinguish preview gallery from compact image-less list without losing actions', async () => {
  vi.mocked(getThemes).mockResolvedValue({
    themes:[
      {name:'with-preview',title:'Modern',version:'1.2.0',images:['https://example.test/cover.webp'],is_active:true,is_system:false},
      {name:'without-preview',title:'Default',version:'1.0.0',images:[' ', ''],is_active:false,is_system:false},
    ],
  })
  await mount(<ThemeSettingsPage/>)
  expect(host.querySelectorAll('.admin-v2-theme-card')).toHaveLength(1)
  expect(host.querySelectorAll('.admin-v2-theme-row')).toHaveLength(1)
  expect(host.querySelector('.theme-cover')).toBeNull()
  expect(host.querySelector('.admin-v2-theme-row .admin-v2-theme-cover')).toBeNull()
  expect(host.querySelector('.admin-v2-theme-row')?.textContent).toContain('Default')
  const previewActiveDelete = host.querySelector<HTMLButtonElement>('button[aria-label="删除主题 with-preview"]')
  expect(previewActiveDelete?.disabled).toBe(true)
  const row = host.querySelector('.admin-v2-theme-row')!
  const switchButton = Array.from(row.querySelectorAll<HTMLButtonElement>('button')).find(b => b.textContent?.trim()==='切换')
  expect(switchButton).toBeDefined()
  await act(async () => { switchButton!.click(); await Promise.resolve() })
  expect(saveSettings).toHaveBeenCalledWith({ frontend_theme:'without-preview' })
})
