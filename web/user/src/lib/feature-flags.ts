export function featureEnabled(
  flag:number|boolean|undefined|null,
  configLoaded=true,
){
  if(!configLoaded)return false
  // Current Xboard core does not expose feature switches for several built-in
  // user routes in /user/comm/config. Treat missing flags as "available" and
  // only hide a feature when a backend/fork explicitly disables it.
  if(flag===undefined||flag===null)return true
  if(typeof flag==='boolean')return flag
  return Number(flag)!==0
}
