<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { errorMessage } from '../api/client'
import { fetchPlans, PERIODS, type PlanItem } from '../api/plan'
import { useI18n } from '../i18n'

const router=useRouter()
const {t}=useI18n()
const plans=ref<PlanItem[]>([])
const loading=ref(true)
const error=ref('')

onMounted(async()=>{
  try{
    plans.value=(await fetchPlans()).filter(plan=>plan.show!==false&&Number(plan.show??1)!==0)
  }catch(e){error.value=errorMessage(e)}finally{loading.value=false}
})

function available(plan:PlanItem){
  return PERIODS.filter(([key])=>Number(plan[key])>0)
}
function price(plan:PlanItem,key:string){
  const cents=Number((plan as unknown as Record<string,unknown>)[key]||0)
  return '¥ '+(cents/100).toFixed(2)
}
function periodLabel(key:string){
  return ({
    month_price:t('period.month'),quarter_price:t('period.quarter'),half_year_price:t('period.halfYear'),
    year_price:t('period.year'),two_year_price:t('period.twoYear'),three_year_price:t('period.threeYear'),
    onetime_price:t('period.onetime'),reset_price:t('period.reset'),
  } as Record<string,string>)[key]||key
}
function periodSummary(plan:PlanItem){
  const parts=available(plan).map(([key])=>periodLabel(key)+' '+price(plan,key))
  return parts.join(' · ')||'—'
}
function capacity(plan:PlanItem){
  const value=plan.capacity_limit
  if(value===undefined||value===null)return ''
  const n=typeof value==='number'?value:Number(String(value).replace(/[^\d.-]/g,''))
  if(Number.isFinite(n))return n<=0?'Sold out':String(n)
  return String(value)
}
const empty=computed(()=>!loading.value&&!plans.value.length)
</script>

<template>
  <div class="plan-list-page">
    <h1 v-if="!loading&&!empty" class="plan-list-heading">{{ t('plan.title') }}</h1>
    <div v-if="error" class="page-alert">{{ error }}</div>

    <div v-if="loading" class="plan-grid">
      <div v-for="n in 2" :key="n" class="xboard-card skeleton-card"/>
    </div>

    <div v-else-if="empty" class="xboard-card empty-state"><strong>{{ t('plan.empty') }}</strong></div>

    <div v-else class="plan-grid">
      <article v-for="plan in plans" :key="plan.id" class="xboard-card plan-list-card">
        <header class="xboard-card-header">{{ plan.name }}</header>
        <div class="xboard-card-body">
          <p class="plan-period-hint">{{ t('plan.billing') }}</p>
          <p class="plan-period-summary">{{ periodSummary(plan) }}</p>

          <div class="plan-list-tags">
            <span v-if="plan.transfer_enable>0" class="x-tag">{{ plan.transfer_enable }} GB</span>
            <span v-if="capacity(plan)" class="x-tag">{{ capacity(plan) }}</span>
            <span v-for="tag in plan.tags||[]" :key="tag" class="x-tag">{{ tag }}</span>
          </div>

          <div class="plan-list-actions">
            <button class="primary-btn" @click="router.push('/plan/'+plan.id)">{{ t('common.detail') }}</button>
          </div>
        </div>
      </article>
    </div>
  </div>
</template>
