import { api } from './client'

const KEY='txboard_invite_code'

export function storeInviteCode(code:string){
  const value=code.trim()
  if(value)sessionStorage.setItem(KEY,value)
}

export function resolveInviteCode(){
  const query=new URLSearchParams(window.location.search)
  let code=query.get('code')?.trim()||''
  if(!code&&window.location.hash.includes('?')){
    const hashQuery=window.location.hash.split('?')[1]||''
    code=new URLSearchParams(hashQuery).get('code')?.trim()||''
  }
  if(code)storeInviteCode(code)
  return code||sessionStorage.getItem(KEY)||''
}

export function recordPageView(){
  const invite=resolveInviteCode()
  if(invite)api.post('/passport/comm/pv',{invite_code:invite}).catch(()=>{})
}
