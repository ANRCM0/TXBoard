export function featureEnabled(
  flag:number|boolean|undefined|null,
  configLoaded=true,
){
  if(!configLoaded)return false
  // Unset feature switches default to available; only an explicit backend
  // denial hides the corresponding native capability.
  if(flag===undefined||flag===null)return true
  if(typeof flag==='boolean')return flag
  return Number(flag)!==0
}
