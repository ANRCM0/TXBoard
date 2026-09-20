<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { telegramLogin } from '../api/auth'
import { errorMessage } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { useI18n } from '../i18n'

const props=defineProps<{botUsername:string;authUrl?:string;endpoint?:string}>()
const emit=defineEmits<{error:[message:string]}>()
const auth=useAuthStore()
const route=useRoute()
const router=useRouter()
const {t}=useI18n()
const containerId='telegram-login-'+Math.random().toString(36).slice(2)

async function handleAuth(user:Record<string,unknown>){
  if(!props.endpoint)return
  try{
    await telegramLogin(user, props.endpoint)
    await auth.loadUser()
    const redirect=typeof route.query.redirect==='string'?route.query.redirect:'/dashboard'
    await router.replace(redirect)
  }catch(e){emit('error',errorMessage(e))}
}

onMounted(()=>{
  const w=window as unknown as {handleTxTelegramAuth?:(user:Record<string,unknown>)=>void}
  w.handleTxTelegramAuth=handleAuth
  const container=document.getElementById(containerId)
  if(!container)return
  const script=document.createElement('script')
  script.async=true
  script.src='https://telegram.org/js/telegram-widget.js?22'
  script.setAttribute('data-telegram-login',props.botUsername)
  script.setAttribute('data-size','large')
  script.setAttribute('data-radius','8')
  script.setAttribute('data-request-access','write')
  if(props.authUrl)script.setAttribute('data-auth-url',props.authUrl)
  else script.setAttribute('data-onauth','handleTxTelegramAuth(user)')
  container.appendChild(script)
})

onBeforeUnmount(()=>{
  const w=window as unknown as {handleTxTelegramAuth?:(user:Record<string,unknown>)=>void}
  if(w.handleTxTelegramAuth===handleAuth)delete w.handleTxTelegramAuth
  const container=document.getElementById(containerId)
  if(container)container.innerHTML=''
})
</script>

<template>
  <div class="telegram-login-block">
    <div class="auth-or"><span/>{{ t('auth.orTelegram') }}<span/></div>
    <div :id="containerId" class="telegram-widget"/>
  </div>
</template>
