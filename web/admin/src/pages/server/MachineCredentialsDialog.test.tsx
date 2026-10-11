import {act} from 'react'
import {createRoot,type Root} from 'react-dom/client'
import {QueryClient,QueryClientProvider} from '@tanstack/react-query'
import {afterEach,beforeEach,describe,expect,it,vi} from 'vitest'
import {getMachineCredentials,resetMachineToken} from '../../api/server'
import {requestConfirm} from '../../components/ui/ConfirmDialog'
import {MachineCredentialsDialog} from './MachineCredentialsDialog'
vi.mock('../../api/server',()=>({
  getMachineCredentials:vi.fn(),resetMachineToken:vi.fn().mockResolvedValue({token:'NEW_TOKEN',install_command:'new install'}),
}))
vi.mock('../../components/ui/ConfirmDialog',()=>({requestConfirm:vi.fn()}))
vi.mock('sonner',()=>({toast:{success:vi.fn(),error:vi.fn()}}))
vi.mock('../../components/ui/Modal',()=>({
  Modal:({open,children,title,onClose}:{open:boolean;children:React.ReactNode;title:string;onClose:()=>void})=>
    open ? <div role="dialog" aria-label={title}>{children}<button onClick={onClose}>关闭凭据</button></div>:null,
}))
let client:QueryClient,root:Root,host:HTMLDivElement
const pending: Array<(value:{token:string;install_command:string})=>void>=[]
function build(id=7,onClose=vi.fn()){
  return <QueryClientProvider client={client}>
    <MachineCredentialsDialog key={id} machineId={id} machineName={'Machine '+id} onClose={onClose}/>
  </QueryClientProvider>
}
beforeEach(()=>{
  ;(globalThis as {IS_REACT_ACT_ENVIRONMENT?:boolean}).IS_REACT_ACT_ENVIRONMENT=true
  vi.clearAllMocks();vi.useRealTimers();pending.length=0
  vi.mocked(getMachineCredentials).mockImplementation(()=>new Promise(resolve=>pending.push(resolve)))
  client=new QueryClient({defaultOptions:{queries:{retry:false}}})
  host=document.createElement('div');document.body.append(host);root=createRoot(host)
})
afterEach(()=>{
  act(()=>root.unmount());vi.useRealTimers();client.clear();host.remove()
})
describe('isolated machine credential display',()=>{
  it('does not mount credentials before fetch resolves, and discards late response after switching machines',async()=>{
    const close=vi.fn()
    await act(async()=>root.render(build(7,close)))
    expect(host.textContent).not.toContain('TOKEN_A')
    await act(async()=>root.render(build(8,close)))
    await act(async()=>pending[0]({token:'TOKEN_A',install_command:'A-command'}))
    expect(host.textContent).not.toContain('TOKEN_A')
    await act(async()=>pending[1]({token:'TOKEN_B',install_command:'B-command'}))
    expect(host.textContent).toContain('TOKEN_B')
    expect(host.textContent).not.toContain('TOKEN_A')
  })
  it('unmounts sensitive text after eighteen seconds',async()=>{
    const close=vi.fn()
    await act(async()=>root.render(build(7,close)))
    vi.useFakeTimers()
    await act(async()=>pending[0]({token:'SHORT_LIVED',install_command:'install -x'}))
    expect(host.textContent).toContain('SHORT_LIVED')
    act(()=>vi.advanceTimersByTime(18_010))
    expect(close).toHaveBeenCalledOnce()
    expect(host.textContent).not.toContain('SHORT_LIVED')
    expect(host.querySelector('[data-testid="machine-secret-token"]')).toBeNull()
  })
  it('requires confirmation for resetting the token and never resets without click',async()=>{
    await act(async()=>root.render(build()))
    await act(async()=>pending[0]({token:'CURRENT_TOKEN',install_command:'install'}))
    expect(resetMachineToken).not.toHaveBeenCalled()
    const button=Array.from(host.querySelectorAll<HTMLButtonElement>('button')).find(b=>b.textContent?.includes('重置 Token'))!
    act(()=>button.click())
    expect(requestConfirm).toHaveBeenCalledWith(expect.objectContaining({
      title:'重置 Machine Token',danger:true,confirmLabel:'重置 Token',
    }))
  })
})
