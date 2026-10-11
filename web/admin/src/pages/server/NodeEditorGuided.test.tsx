import {act} from 'react'
import {createRoot,type Root} from 'react-dom/client'
import {Simulate} from 'react-dom/test-utils'
import {afterEach,beforeEach,describe,expect,it,vi} from 'vitest'
import {NodeEditorModal} from './NodeEditorModal'
import {ProtocolSchemaForm} from './ProtocolSchemaForm'
import {presetForField,selectedPresetOptions} from './nodePresetOptions'
import type {GroupItem,NodeItem,ProtocolDefinitionMeta,RouteItem} from '../../api/server'
import {toast} from 'sonner'

vi.mock('sonner',()=>({toast:{error:vi.fn(),success:vi.fn()}}))
vi.mock('../../components/ui/ConfirmDialog',()=>({requestConfirm:vi.fn()}))

const protocols:ProtocolDefinitionMeta[]=[{
  type:'shadowsocks',label:'Shadowsocks',schema_version:1,
  defaults:{cipher:'aes-128-gcm',plugin:'',obfs:''},
  form_schema:[
    {key:'cipher',label:'加密方式',type:'select',options:[
      {value:'aes-128-gcm',label:'aes-128-gcm'},
      {value:'2022-blake3-aes-256-gcm',label:'2022-blake3-aes-256-gcm'},
    ]},
    {key:'plugin',label:'插件',type:'text'},
    {key:'obfs',label:'混淆方式',type:'text'},
  ],
}]
const node:NodeItem={
  id:8,name:'Tokyo',type:'shadowsocks',host:'tokyo.example.com',
  port:443,server_port:8443,rate:1,protocol_settings:{cipher:'aes-128-gcm',plugin:'unknown-legacy-plugin'},
  group_ids:[1],
}
const groups:GroupItem[]=[{id:1,name:'admin'},{id:2,name:'Lv1'}]
const routes:RouteItem[]=[{id:3,remarks:'Direct'}]
let root:Root,host:HTMLDivElement
beforeEach(()=>{
  ;(globalThis as {IS_REACT_ACT_ENVIRONMENT?:boolean}).IS_REACT_ACT_ENVIRONMENT=true
  vi.clearAllMocks()
  host=document.createElement('div');document.body.append(host);root=createRoot(host)
})
afterEach(()=>{act(()=>root.unmount());host.remove()})
const dialog=()=>document.querySelector<HTMLElement>('.node-editor-modal')!
async function mount(data:NodeItem|null=node,onSubmit=vi.fn()){
  await act(async()=>{
    root.render(<NodeEditorModal open node={data}
      nodes={[node]} machines={[]} groups={groups} routes={routes}
      protocolDefinitions={protocols} saving={false} onClose={()=>{}} onSubmit={onSubmit}/>)
    await new Promise(resolve=>setTimeout(resolve,0))
  })
}
function setInput(placeholder:string,value:string){
  const input=dialog().querySelector<HTMLInputElement>(`input[placeholder="${placeholder}"]`)!
  act(()=>{input.value=value;Simulate.change(input)})
}
function choosePreset(label:string,text:string){
  const open=dialog().querySelector<HTMLButtonElement>(`button[aria-label="${label}"]`)!
  act(()=>open.click())
  const option=Array.from(dialog().querySelectorAll<HTMLButtonElement>('.node-preset-option'))
    .find(button=>button.textContent?.includes(text))!
  expect(option).toBeTruthy()
  act(()=>option.click())
}
describe('node editor guided settings',()=>{
  it('shows searchable choices for schema select fields and preserves backend option types',()=>{
    const field=protocols[0].form_schema[0]
    expect(presetForField('shadowsocks',field)?.allowCustom).toBe(false)
    expect(presetForField('shadowsocks',protocols[0].form_schema[1])?.allowCustom).toBe(true)
    expect(presetForField('other',{key:'plugin',label:'Plugin',type:'text'})).toBeNull()
    expect(selectedPresetOptions([{value:0,label:'关闭'},{value:1,label:'开启'}],2))
      .toEqual([{value:2,label:'当前配置：2'},{value:0,label:'关闭'},{value:1,label:'开启'}])
  })
  it('has protocol selectors, reusable presets, fixed footer, and selected group chips',async()=>{
    await mount()
    expect(dialog().textContent).toContain('基础信息')
    expect(dialog().textContent).toContain('连接信息')
    expect(dialog().textContent).toContain('访问与部署')
    expect(dialog().querySelector('.node-editor-actions .node-editor-advanced-trigger')).not.toBeNull()
    expect(dialog().querySelector('.node-multi-chip')?.textContent).toBe('admin')
    const plugin=dialog().querySelector<HTMLButtonElement>('button[aria-label="插件"]')!
    expect(plugin.textContent).toContain('unknown-legacy-plugin')
    choosePreset('加密方式','2022-blake3')
    expect(dialog().querySelector<HTMLButtonElement>('button[aria-label="加密方式"]')?.textContent)
      .toContain('2022-blake3-aes-256-gcm')
    choosePreset('插件','v2ray-plugin')
    expect(dialog().querySelector<HTMLButtonElement>('button[aria-label="插件"]')?.textContent)
      .toContain('v2ray-plugin')
  })
  it('creates a node entirely through built-in protocol choices and typed connection fields',async()=>{
    const submitted=vi.fn()
    await mount(null,submitted)
    const picker=dialog().querySelector<HTMLElement>('.node-protocol-picker')!
    act(()=>picker.querySelector<HTMLElement>('summary')!.click())
    act(()=>picker.querySelector<HTMLButtonElement>('button')!.click())
    choosePreset('加密方式','2022-blake3')
    choosePreset('插件','v2ray-plugin')
    setInput('请输入节点名称','Hong Kong 01')
    setInput('请输入节点域名或者 IP','hk.example.com')
    setInput('用户连接端口','443')
    setInput('请输入服务端口','8443')
    const submit=dialog().querySelector<HTMLButtonElement>('button[aria-label="创建节点"]')!
    act(()=>submit.click())
    expect(submitted).toHaveBeenCalledTimes(1)
    const payload=submitted.mock.calls[0][0]
    expect(payload.name).toBe('Hong Kong 01')
    expect(payload.protocol_settings).toMatchObject({cipher:'2022-blake3-aes-256-gcm',plugin:'v2ray-plugin'})
    expect(payload.port).toBe(443)
    expect(payload.server_port).toBe(8443)
  })
  it('adds dynamic rate periods without JSON input and rejects enabled empty schedules',async()=>{
    const submitted=vi.fn()
    await mount(node,submitted)
    const dynamic=dialog().querySelector<HTMLButtonElement>('.node-toggle-row button[role="switch"]')!
    act(()=>dynamic.click())
    expect(dialog().textContent).toContain('添加时间段')
    expect(dialog().querySelector('textarea')).not.toBeNull() // advanced fields remain for legacy config
    const submit=dialog().querySelector<HTMLButtonElement>('button[aria-label="保存节点"]')!
    act(()=>submit.click())
    expect(submitted).not.toHaveBeenCalled()
    expect(toast.error).toHaveBeenCalledWith(expect.stringContaining('分时倍率'))
    const add=dialog().querySelector<HTMLButtonElement>('.node-rate-add')!
    act(()=>add.click())
    expect(dialog().querySelectorAll('.node-rate-row')).toHaveLength(1)
    act(()=>submit.click())
    expect(submitted).toHaveBeenCalledTimes(1)
    expect(submitted.mock.calls[0][0].rate_time_ranges)
      .toEqual([{start:'08:00',end:'20:00',rate:1}])
    const advanced=dialog().querySelector<HTMLButtonElement>('.node-editor-advanced-trigger')!
    act(()=>advanced.click())
    expect(advanced.getAttribute('aria-expanded')).toBe('true')
  })
})
