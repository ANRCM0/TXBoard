import { ref } from 'vue'
import { fetchUserCommConfig, type UserCommConfig } from '../api/comm'

const CACHE_KEY='txboard_user_comm_config'
function readCache():UserCommConfig|null{
  try{const raw=localStorage.getItem(CACHE_KEY);return raw?JSON.parse(raw) as UserCommConfig:null}catch{return null}
}
function writeCache(value:UserCommConfig){
  try{localStorage.setItem(CACHE_KEY,JSON.stringify(value))}catch{}
}

const config=ref<UserCommConfig|null>(readCache())
let loading:Promise<UserCommConfig>|null=null

export function useUserCommConfig(){
  async function load(options?:{force?:boolean}){
    if(options?.force)loading=null
    else if(config.value)return config.value
    if(!loading){
      loading=fetchUserCommConfig()
        .then(data=>{config.value=data;writeCache(data);return data})
        .catch(error=>{loading=null;throw error})
    }
    return loading
  }
  function reset(){
    config.value=null
    loading=null
    try{localStorage.removeItem(CACHE_KEY)}catch{}
  }
  return {config,load,reset}
}
