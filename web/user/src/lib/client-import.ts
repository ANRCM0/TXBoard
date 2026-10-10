export type ClientPlatform='windows'|'mac'|'ios'|'android'|'unknown'
export type ImportClient={
  name:string
  platforms:ClientPlatform[]
  action:'copy'|'open'
  url?:string
}

export const PROTOCOL_TYPES=[
  {label:'Auto',value:'auto'},
  {label:'AnyTLS',value:'anytls'},
  {label:'VLESS',value:'vless'},
  {label:'Hysteria',value:'hysteria'},
  {label:'Hysteria2',value:'hysteria2'},
  {label:'Shadowsocks',value:'shadowsocks'},
  {label:'VMess',value:'vmess'},
  {label:'Trojan',value:'trojan'},
]

export function detectPlatform():ClientPlatform{
  const ua=navigator.userAgent.toLowerCase()
  if(/iphone|ipad|ipod/.test(ua))return 'ios'
  if(/android/.test(ua))return 'android'
  if(/macintosh|mac os/.test(ua))return 'mac'
  if(/windows/.test(ua))return 'windows'
  return 'unknown'
}

function base64(input:string){
  return btoa(unescape(encodeURIComponent(input)))
}

export function appendSubscribeTypes(url:string,types:string[]){
  if(!url||types.length===0||types.includes('auto'))return url
  try{
    const parsed=new URL(url)
    parsed.searchParams.set('types',types.join(','))
    return parsed.toString()
  }catch{
    return url
  }
}

export function buildImportClients(subscribeUrl:string,siteTitle='TXBoard'):ImportClient[]{
  if(!subscribeUrl)return []
  const encoded=encodeURIComponent(subscribeUrl)
  const name=encodeURIComponent(siteTitle)
  const b64=base64(subscribeUrl)
  return [
    {name:'__copy__',platforms:['windows','mac','ios','android','unknown'],action:'copy'},
    {name:'Clash',platforms:['windows'],action:'open',url:'clash://install-config?url='+encoded+'&name='+name},
    {name:'Clash Meta',platforms:['mac','android'],action:'open',url:'clash://install-config?url='+encoded+'&name='+name},
    {name:'Hiddify',platforms:['windows','mac','ios','android'],action:'open',url:'hiddify://import/'+encoded+'#'+name},
    {name:'Sing-box',platforms:['mac','ios','android'],action:'open',url:'sing-box://import-remote-profile?url='+encoded+'#'+name},
    {name:'Shadowrocket',platforms:['ios','mac'],action:'open',url:'shadowrocket://add/sub://'+b64+'?remark='+name},
    {name:'Quantumult X',platforms:['ios','mac'],action:'open',url:'quantumult-x:///update-configuration?remote-resource='+encodeURIComponent(JSON.stringify({server_remote:[subscribeUrl+', tag='+siteTitle]}))},
    {name:'Surge',platforms:['ios','mac'],action:'open',url:'surge:///install-config?url='+encoded+'&name='+name},
    {name:'Stash',platforms:['ios','mac'],action:'open',url:'stash://install-config?url='+encoded+'&name='+name},
    {name:'NekoBox',platforms:['android'],action:'open',url:'clash://install-config?url='+encoded+'&name='+name},
    {name:'Surfboard',platforms:['android'],action:'open',url:'surfboard:///install-config?url='+encoded+'&name='+name},
  ]
}

export function filterClientsByPlatform(clients:ImportClient[],platform:ClientPlatform){
  return clients.filter(client=>platform==='unknown'||client.platforms.includes(platform))
}
