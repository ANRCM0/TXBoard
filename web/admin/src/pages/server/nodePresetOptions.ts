import type { ProtocolFormField, ProtocolFormOption } from '../../api/server'

type PresetDefinition = { options: ProtocolFormOption[]; allowCustom: boolean }

/**
 * Client-side suggestions only for formerly free-text protocol settings.
 * Keep backend schema authoritative, and retain unknown existing custom values.
 */
const predefined: Record<string, {type?:string; options:ProtocolFormOption[]}> = {
  'shadowsocks:obfs': { options:[
    {value:'',label:'不启用混淆'}, {value:'http',label:'HTTP 混淆'}, {value:'tls',label:'TLS 混淆'},
  ]},
  'shadowsocks:plugin': { options:[
    {value:'',label:'不使用插件'}, {value:'v2ray-plugin',label:'v2ray-plugin'},
    {value:'shadow-tls',label:'shadow-tls'}, {value:'restls',label:'restls'},
  ]},
  'network': { options:[
    {value:'tcp',label:'TCP'}, {value:'ws',label:'WebSocket (WS)'},
    {value:'grpc',label:'gRPC'}, {value:'httpupgrade',label:'HTTP Upgrade'},
    {value:'xhttp',label:'XHTTP'}, {value:'quic',label:'QUIC'},
  ]},
  'flow': { options:[
    {value:'',label:'无 Flow'}, {value:'xtls-rprx-vision',label:'XTLS Vision'},
  ]},
  'multiplex.protocol': { options:[
    {value:'yamux',label:'Yamux'}, {value:'smux',label:'SMux'}, {value:'h2mux',label:'H2Mux'},
  ]},
  'utls.fingerprint': { options:[
    {value:'chrome',label:'Chrome'}, {value:'firefox',label:'Firefox'},
    {value:'safari',label:'Safari'}, {value:'edge',label:'Edge'},
    {value:'ios',label:'iOS'}, {value:'android',label:'Android'},
  ]},
}

export function presetForField(protocol: string, field: ProtocolFormField): PresetDefinition | null {
  if (field.type === 'select' && field.options?.length)
    return {options: field.options, allowCustom:false}
  if (field.type !== 'text' || field.generator) return null
  const preset = predefined[protocol+':'+field.key] ?? predefined[field.key]
  return preset ? {options:preset.options,allowCustom:true} : null
}

export function selectedPresetOptions(options:ProtocolFormOption[], current:unknown):ProtocolFormOption[] {
  const value=String(current ?? '')
  if (!value || options.some(item=>String(item.value)===value)) return options
  return [{value,label:'当前配置：'+value},...options]
}
