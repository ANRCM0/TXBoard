<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { fetchNotices, type NoticeItem } from '../api/notice'
import { fetchSubscribe, type SubscribeInfo } from '../api/subscribe'
import { fetchUserStat } from '../api/user'
import { errorMessage } from '../api/client'
import ClientImportModal from '../components/ClientImportModal.vue'
import { fetchGuestConfig } from '../api/comm'
import { useI18n } from '../i18n'

const {t,locale}=useI18n()
const subscribe=ref<SubscribeInfo|null>(null)
const stats=ref<number[]>([])
const notices=ref<NoticeItem[]>([])
const loading=ref(true)
const error=ref('')
const copied=ref(false)
const importOpen=ref(false)
const siteTitle=ref('TXBoard')
const noticeIndex=ref(0)
let noticeTimer:number|null=null
let copyTimer:number|null=null

const used=computed(()=>Number(subscribe.value?.u||0)+Number(subscribe.value?.d||0))
const quota=computed(()=>{
  const explicit=Number(subscribe.value?.transfer_enable||0)
  if(explicit>0)return explicit
  const planGb=Number(subscribe.value?.plan?.transfer_enable||0)
  return planGb>0?planGb*1073741824:0
})
const percent=computed(()=>quota.value>0?Math.min(100,used.value/quota.value*100):0)
const activeNotice=computed(()=>notices.value.length?notices.value[noticeIndex.value%notices.value.length]:null)

const shortcuts=computed(()=>[
  {title:t('dashboard.quickSub'),desc:t('dashboard.quickSubDesc'),icon:'◉',action:()=>{if(subscribe.value?.subscribe_url)importOpen.value=true}},
  {title:t('dashboard.purchase'),desc:t('dashboard.purchaseDesc'),icon:'◷',to:'/plan'},
  {title:t('dashboard.nodes'),desc:t('node.desc'),icon:'◎',to:'/node'},
  {title:t('dashboard.support'),desc:t('dashboard.supportDesc'),icon:'?',to:'/ticket'},
])

onMounted(async()=>{
  loading.value=true
  try{
    const [sub,stat,news,guest]=await Promise.all([
      fetchSubscribe(),
      fetchUserStat().catch(()=>[] as number[]),
      fetchNotices().catch(()=>[] as NoticeItem[]),
      fetchGuestConfig().catch(()=>null),
    ])
    subscribe.value=sub
    stats.value=stat
    notices.value=news
    siteTitle.value=guest?.app_name||'TXBoard'
    if(notices.value.length>1){
      noticeTimer=window.setInterval(()=>{
        noticeIndex.value=(noticeIndex.value+1)%notices.value.length
      },5000)
    }
  }catch(e){error.value=errorMessage(e)}finally{loading.value=false}
})

onUnmounted(()=>{
  if(noticeTimer!==null)window.clearInterval(noticeTimer)
  if(copyTimer!==null)window.clearTimeout(copyTimer)
})

function markCopied(){
  copied.value=true
  if(copyTimer!==null)window.clearTimeout(copyTimer)
  copyTimer=window.setTimeout(()=>{copied.value=false},1600)
}
async function copySubscription(){
  if(!subscribe.value?.subscribe_url)return
  try{
    await navigator.clipboard.writeText(subscribe.value.subscribe_url)
    markCopied()
  }catch{error.value=t('dashboard.copyFailed')}
}
function bytes(value:number){
  if(!value)return '0 GB'
  const gb=value/1073741824
  return gb>=1024?(gb/1024).toFixed(2)+' TB':gb.toFixed(gb>=10?1:2)+' GB'
}
function expireText(value?:number|null){
  if(!value)return t('dashboard.longTerm')
  return t('dashboard.expires',{date:new Date(value*1000).toLocaleDateString(locale.value)})
}
function noticeDate(value:number){
  return value?new Date(value*1000).toLocaleString(locale.value):''
}
function shortcutClick(item:{to?:string;action?:()=>void}){
  if(item.action)item.action()
  else if(item.to)window.location.hash='#'+item.to
}
</script>

<template>
  <div class="xboard-dashboard">
    <div v-if="stats[2]>0" class="dash-alert dash-alert--info">
      <span>{{ t('dashboard.inviteAlert',{count:stats[2]}) }}</span>
      <router-link to="/invite">{{ t('dashboard.goView') }}</router-link>
    </div>
    <div v-if="stats[1]>0" class="dash-alert dash-alert--warning">
      <span>{{ t('dashboard.ticketAlert',{count:stats[1]}) }}</span>
      <router-link to="/ticket">{{ t('dashboard.goView') }}</router-link>
    </div>
    <div v-if="stats[0]>0" class="dash-alert dash-alert--danger">
      <span>{{ t('dashboard.unpaidAlert',{count:stats[0]}) }}</span>
      <router-link to="/order">{{ t('dashboard.goView') }}</router-link>
    </div>

    <div v-if="error" class="page-alert">{{ error }}</div>

    <section
      v-if="activeNotice"
      class="dash-promo-banner"
      :class="{'has-image':Boolean(activeNotice.img_url)}"
      :style="activeNotice.img_url?{backgroundImage:'url('+activeNotice.img_url+')'}:undefined"
    >
      <svg v-if="!activeNotice.img_url" class="dash-promo-decor" viewBox="0 0 1000 250" preserveAspectRatio="xMidYMid slice" aria-hidden="true">
        <rect x="755" y="44" width="22" height="22"/>
        <circle cx="900" cy="125" r="6"/>
        <path d="M470 130 L610 200 L470 270 Z" fill="none" stroke-width="2"/>
        <path d="M820 150 A120 120 0 0 1 940 270" fill="none" stroke-width="2"/>
      </svg>
      <div class="dash-promo-overlay">
        <span class="dash-promo-pill">{{ t('dashboard.announcement') }}</span>
        <h1>{{ activeNotice.title }}</h1>
        <span>{{ noticeDate(activeNotice.created_at) }}</span>
      </div>
      <div v-if="notices.length>1" class="dash-promo-dots">
        <button
          v-for="(_,index) in notices"
          :key="index"
          :class="{active:index===noticeIndex}"
          @click="noticeIndex=index"
        />
      </div>
    </section>

    <section class="xboard-card dashboard-sub-card">
      <header class="xboard-card-header">{{ t('dashboard.subscription') }}</header>
      <div class="xboard-card-body">
        <div v-if="loading" class="skeleton-stack"><div/><div/><div/></div>

        <template v-else-if="subscribe?.plan">
          <div class="sub-plan-name">{{ subscribe.plan.name }}</div>
          <div class="sub-meta">
            <span>{{ expireText(subscribe.expired_at) }}</span>
            <span v-if="subscribe.reset_day">{{ t('dashboard.resetDay',{day:subscribe.reset_day}) }}</span>
            <span v-if="subscribe.device_limit">{{ t('dashboard.device',{count:subscribe.device_limit}) }}</span>
            <span v-if="subscribe.speed_limit">{{ t('dashboard.speed',{speed:subscribe.speed_limit}) }}</span>
          </div>
          <div class="sub-traffic">
            <div class="traffic-label">
              <span>{{ t('dashboard.trafficUsage') }}</span>
              <strong>{{ bytes(used) }} / {{ quota?bytes(quota):'∞' }}</strong>
            </div>
            <div class="progress-track"><span :style="{width:percent+'%'}"/></div>
          </div>
          <div class="subscription-actions">
            <button class="primary-btn" :disabled="!subscribe.subscribe_url" @click="copySubscription">
              {{ copied?t('dashboard.copied'):t('dashboard.copyLink') }}
            </button>
            <button class="secondary-btn" :disabled="!subscribe.subscribe_url" @click="importOpen=true">{{ t('dashboard.quickSub') }}</button>
          </div>
        </template>

        <div v-else class="dashboard-empty-sub" @click="$router.push('/plan')">
          <div class="dashboard-empty-plus">＋</div>
          <div>{{ t('dashboard.selectPlan') }}</div>
        </div>
      </div>
    </section>

    <section class="xboard-card dashboard-shortcuts">
      <header class="xboard-card-header">{{ t('dashboard.shortcut') }}</header>
      <div class="shortcut-list">
        <button v-for="item in shortcuts" :key="item.title" class="shortcut-row" @click="shortcutClick(item)">
          <div>
            <strong>{{ item.title }}</strong>
            <span>{{ item.desc }}</span>
          </div>
          <div class="shortcut-icon">{{ item.icon }}</div>
        </button>
      </div>
    </section>

    <ClientImportModal
      :show="importOpen"
      :subscribe-url="subscribe?.subscribe_url||''"
      :site-title="siteTitle"
      @close="importOpen=false"
      @copied="markCopied"
    />
  </div>
</template>
