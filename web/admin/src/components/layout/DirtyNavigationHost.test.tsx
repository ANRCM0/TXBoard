import { act, useState } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { createMemoryRouter, Link, MemoryRouter, Outlet, RouterProvider } from 'react-router-dom'
import { afterEach, beforeEach, expect, it } from 'vitest'
import { DirtyNavigationHost } from './DirtyNavigationHost'
import { dirtyDrafts, useDirtyDraft } from '../../lib/useDirtyDraft'
import { Modal } from '../ui/Modal'

let root: Root, host: HTMLDivElement
let router: ReturnType<typeof createMemoryRouter> | undefined
let clearDraft: (() => void) | undefined
function Draft({ nested = false }: { nested?: boolean }) {
  const [dirty, setDirty] = useState(false)
  useDirtyDraft(dirty)
  clearDraft = () => setDirty(false)
  return <><button onClick={() => setDirty(true)}>Edit draft</button><Link to="/next">Next page</Link>
    {nested ? <Modal open title="Nested editor" onClose={() => {}}><p>Editor draft</p></Modal> : null}</>
}
function Layout() { return <><Outlet /><DirtyNavigationHost /></> }
async function render(nested = false) {
  router = createMemoryRouter([{ element: <Layout />, children: [
    { path: '/', element: <Draft nested={nested} /> }, { path: '/next', element: <h1>Next page content</h1> },
  ] }])
  await act(async () => root.render(<RouterProvider router={router!} />))
}
async function click(label: string) {
  await act(async () => {
    const control = [...document.querySelectorAll('button, a')].find(node => node.textContent === label) as HTMLElement | undefined
    expect(control).toBeDefined(); control!.click()
  })
}
beforeEach(() => {
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
  host = document.createElement('div'); document.body.append(host); root = createRoot(host)
})
afterEach(() => {
  act(() => root.unmount()); router?.dispose(); router = undefined; clearDraft = undefined; host.remove()
  expect(dirtyDrafts.snapshot()).toBe(false)
})
it('allows clean navigation and unregisters a discarded draft after one confirmation', async () => {
  await render(); await click('Next page')
  expect(router!.state.location.pathname).toBe('/next')
  await act(async () => { await router!.navigate('/') })
  await click('Edit draft'); await click('Next page')
  expect(router!.state.location.pathname).toBe('/')
  expect(document.body.textContent).toContain('放弃未保存的修改？')
  await click('继续编辑')
  expect(router!.state.location.pathname).toBe('/')
  expect(dirtyDrafts.snapshot()).toBe(true)
  await click('Next page'); await click('放弃修改')
  expect(router!.state.location.pathname).toBe('/next')
  expect(dirtyDrafts.snapshot()).toBe(false)
})
it('guards history navigation and releases it when permission clearing removes the draft', async () => {
  await render(); await click('Next page')
  await act(async () => { await router!.navigate('/') })
  await click('Edit draft')
  await act(async () => { await router!.navigate(-1) })
  expect(router!.state.location.pathname).toBe('/')
  await act(async () => clearDraft!())
  expect(router!.state.location.pathname).toBe('/next')
  expect(document.body.textContent).not.toContain('放弃未保存的修改？')
})
it('keeps the nested editor mounted and restores its layer when navigation is cancelled', async () => {
  await render(true)
  // History navigation remains possible while the editor makes the sidebar inert.
  await click('Edit draft')
  await act(async () => { await router!.navigate('/next') })
  expect(document.querySelectorAll('[role="dialog"]')).toHaveLength(2)
  await click('继续编辑')
  expect(document.querySelectorAll('[role="dialog"]')).toHaveLength(1)
  expect(document.body.textContent).toContain('Editor draft')
  expect(router!.state.location.pathname).toBe('/')
})
it('registers multiple drafts without requiring a data router in each page', async () => {
  function Registered({ dirty }: { dirty: boolean }) { useDirtyDraft(dirty); return null }
  await act(async () => root.render(<MemoryRouter><Registered dirty /><Registered dirty /></MemoryRouter>))
  expect(dirtyDrafts.snapshot()).toBe(true)
  await act(async () => root.render(<MemoryRouter><Registered dirty={false} /><Registered dirty /></MemoryRouter>))
  expect(dirtyDrafts.snapshot()).toBe(true)
  await act(async () => root.render(<MemoryRouter><Registered dirty={false} /></MemoryRouter>))
  expect(dirtyDrafts.snapshot()).toBe(false)
})
