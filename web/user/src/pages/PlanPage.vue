<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { errorMessage } from '../api/client'
import { fetchPlans, type PlanItem } from '../api/plan'
import { useI18n } from '../i18n'

const router=useRouter()
const {t}=useI18n()
const plans=ref<PlanItem[]>([])
const loading=ref(true)
const error=ref('')

onMounted(async()=>{
  try{
    // /txapi/plans already enforces show/sell/capacity server-side.
    plans.value=await fetchPlans()
  }catch(e){error.value=errorMessage(e)}finally{loading.value=false}
})

function price(cents:number){
  return '¥ '+(cents/100).toFixed(2)
}
function periodLabel(key:string){
  return ({
    monthly:t('period.month'),quarterly:t('period.quarter'),half_yearly:t('period.halfYear'),
    yearly:t('period.year'),two_yearly:t('period.twoYear'),three_yearly:t('period.threeYear'),
    onetime:t('period.onetime'),reset_traffic:t('period.reset'),
  } as Record<string,string>)[key]||key
}
function periodSummary(plan:PlanItem){
  const parts=plan.prices.map(item=>periodLabel(item.period)+' '+price(item.amount_minor))
  return parts.join(' · ')||'—'
}
function capacity(plan:PlanItem){
  return plan.capacity_limit===null?'':String(plan.capacity_limit)
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
            <span v-if="plan.traffic_limit_bytes>0" class="x-tag">{{ plan.traffic_limit_bytes/1073741824 }} GB</span>
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
