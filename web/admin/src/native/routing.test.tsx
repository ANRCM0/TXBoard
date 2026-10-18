import { act } from 'react'
import { createRoot } from 'react-dom/client'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { afterEach, expect, it, vi } from 'vitest'
import { AuthGuard } from '../components/layout/AuthGuard'
import { loginRedirect, resolveBasePath, withBasePath } from '../lib/basePath'

vi.mock('./session', () => ({ useAdminSession: () => false }))
afterEach(() => { vi.unstubAllEnvs(); localStorage.clear(); window.history.replaceState({}, '', '/') })
it('uses the runtime browser mount independently from the compiled asset namespace', () => {
  vi.stubEnv('BASE_URL', '/.txboard-admin/')
  window.history.replaceState({}, '', '/console/server/manage?page=2')
  expect(resolveBasePath()).toBe('/console')
  expect(withBasePath('/server/manage')).toBe('/console/server/manage')
  expect(loginRedirect('/console/server/manage?page=2')).toBe('/server/manage?page=2')
  for (const target of ['https://evil.test', '//evil.test', '/\\evil.test', '/sign-in?redirect=anything', '']) {
    expect(loginRedirect(target)).toBe('/')
  }
  window.history.replaceState({}, '', '/rotated_path/sign-in')
  expect(resolveBasePath()).toBe('/rotated_path')
  expect(withBasePath('/finance/order')).toBe('/rotated_path/finance/order')
})
it('requires a live memory session even with a legacy stored token and preview flag', async () => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  vi.stubEnv('VITE_STATIC_PREVIEW', '1')
  localStorage.setItem('access_token', 'legacy-token')
  const host = document.createElement('div'); document.body.append(host); const root = createRoot(host)
  function SignIn() { return <div>{useLocation().search}</div> }
  await act(async () => root.render(<MemoryRouter initialEntries={['/server/manage?page=2']}>
    <Routes><Route path="/sign-in" element={<SignIn />} /><Route element={<AuthGuard />}>
      <Route path="/server/manage" element={<div>private-inventory</div>} />
    </Route></Routes>
  </MemoryRouter>))
  expect(host.textContent).not.toContain('private-inventory')
  expect(host.textContent).toContain('redirect=%2Fserver%2Fmanage%3Fpage%3D2')
  act(() => root.unmount()); host.remove()
})
