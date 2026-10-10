<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { featureEnabled } from '../lib/feature-flags'
import { useUserCommConfig } from '../composables/useUserCommConfig'
import { useAuthStore } from '../stores/auth'
import { useI18n } from '../i18n'

type NavItem={to:string;label:string;icon:string}
type NavGroup={title?:string;items:NavItem[]}

const router=useRouter()
const route=useRoute()
const auth=useAuthStore()
const mobileOpen=ref(false)
const collapsed=ref(false)
const isMobile=ref(false)
const dark=ref(localStorage.getItem('txboard_user_theme')==='dark')
const {config,load:loadConfig,reset:resetConfig}=useUserCommConfig()
const {t,locale,setLocale}=useI18n()

const navGroups=computed<NavGroup[]>(()=>{
  const loaded=config.value!==null
  const c=config.value
  const top:NavItem[]=[{to:'/dashboard',label:t('nav.dashboard'),icon:'⌂'}]
  if(featureEnabled(c?.knowledge_enable,loaded))top.push({to:'/knowledge',label:t('nav.knowledge'),icon:'▱'})

  const billing:NavItem[]=[{to:'/order',label:t('nav.order'),icon:'▤'},
    {to:'/wallet',label:locale.value==='en-US'?'Wallet top-up':'钱包充值',icon:'¥'}]
  if(featureEnabled(c?.invite_enable,loaded))billing.push({to:'/invite',label:t('nav.invite'),icon:'♧'})
  if(featureEnabled(c?.gift_card_enable,loaded))billing.push({to:'/gift-card',label:t('nav.giftCard'),icon:'✦'})

  const subscription:NavItem[]=[
    {to:'/plan',label:t('nav.plan'),icon:'◇'},
    {to:'/node',label:t('nav.node'),icon:'◎'},
  ]

  const account:NavItem[]=[{to:'/profile',label:t('nav.profile'),icon:'○'}]
  if(featureEnabled(c?.ticket_enable,loaded))account.push({to:'/ticket',label:t('nav.ticket'),icon:'◫'})
  if(featureEnabled(c?.traffic_log_enable,loaded))account.push({to:'/traffic',label:t('nav.traffic'),icon:'↕'})

  return [
    {items:top},
    {title:t('nav.groupBilling'),items:billing},
    {title:t('nav.groupSubscription'),items:subscription},
    {title:t('nav.groupAccount'),items:account},
  ]
})

const allNav=computed(()=>navGroups.value.flatMap(group=>group.items))
const activeItem=computed(()=>allNav.value.find(item=>route.path===item.to||route.path.startsWith(item.to+'/')))
const activeLabel=computed(()=>activeItem.value?.label||t('nav.dashboard'))

function updateViewport(){
  isMobile.value=window.innerWidth<=767
  if(!isMobile.value)mobileOpen.value=false
}

onMounted(async()=>{
  document.documentElement.dataset.userTheme=dark.value?'dark':'light'
  updateViewport()
  window.addEventListener('resize',updateViewport)
  const configPromise=loadConfig().catch(()=>null)
  if(!auth.user){
    try{await auth.loadUser()}catch{
      await router.replace({path:'/login',query:{redirect:route.fullPath}})
      return
    }
  }
  await configPromise
})

onUnmounted(()=>window.removeEventListener('resize',updateViewport))

function toggleDark(){
  dark.value=!dark.value
  localStorage.setItem('txboard_user_theme',dark.value?'dark':'light')
  document.documentElement.dataset.userTheme=dark.value?'dark':'light'
}

async function logout(){
  if(!window.confirm(t('common.logout')+'?'))return
  await auth.logout()
  resetConfig()
  await router.replace('/login')
}

function changeLocale(event:Event){
  setLocale((event.target as HTMLSelectElement).value)
}

function go(path:string){
  mobileOpen.value=false
  void router.push(path)
}

function toggleNav(){
  if(isMobile.value)mobileOpen.value=!mobileOpen.value
  else collapsed.value=!collapsed.value
}

function toggleFullscreen(){
  if(!document.fullscreenElement)void document.documentElement.requestFullscreen?.()
  else void document.exitFullscreen?.()
}
</script>

<template>
  <div class="user-shell" :class="{collapsed}">
    <aside class="user-sidebar" :class="{open:mobileOpen,collapsed}">
      <div class="user-brand">
        <div class="user-brand-mark">TX</div>
        <strong v-if="!collapsed || isMobile" class="user-brand-title">TXBoard</strong>
        <button class="user-icon-btn mobile-close" @click="mobileOpen=false">×</button>
      </div>

      <nav class="user-nav">
        <section v-for="(group,index) in navGroups" :key="index" class="user-nav-group">
          <div v-if="group.title && (!collapsed || isMobile)" class="user-nav-group-title">{{ group.title }}</div>
          <button
            v-for="item in group.items"
            :key="item.to"
            class="user-nav-item"
            :class="{active:route.path===item.to||route.path.startsWith(item.to+'/')}"
            :title="collapsed&&!isMobile?item.label:undefined"
            @click="go(item.to)"
          >
            <span class="user-nav-icon">{{ item.icon }}</span>
            <span v-if="!collapsed || isMobile" class="user-nav-label">{{ item.label }}</span>
          </button>
        </section>
      </nav>
    </aside>

    <button v-if="mobileOpen" class="user-sidebar-mask" @click="mobileOpen=false"/>

    <div class="user-main">
      <header class="user-header">
        <div class="user-header-left">
          <button class="user-header-icon" @click="toggleNav">☰</button>
          <div class="user-breadcrumb">
            <span class="user-breadcrumb-home">⌂</span>
            <span>{{ activeLabel }}</span>
          </div>
        </div>

        <div class="user-header-actions">
          <button class="user-header-icon" :title="dark?'Light':'Dark'" @click="toggleDark">{{ dark?'☀':'☾' }}</button>
          <select class="user-lang-select" :value="locale" @change="changeLocale">
            <option value="zh-CN">中文</option>
            <option value="en-US">English</option>
          </select>
          <button class="user-header-icon desktop-fullscreen" title="Fullscreen" @click="toggleFullscreen">⛶</button>
          <button class="user-account-btn" :title="t('common.logout')" @click="logout">
            <span class="avatar">{{ (auth.user?.email||'U').slice(0,1).toUpperCase() }}</span>
            <span class="account-email">{{ auth.user?.email||'User' }}</span>
          </button>
        </div>
      </header>

      <main class="user-content"><router-view/></main>
    </div>
  </div>
</template>
