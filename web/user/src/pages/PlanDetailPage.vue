<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { checkCoupon } from '../api/coupon'
import { errorMessage } from '../api/client'
import { cancelOrder, fetchFirstBlockingOrder, saveOrder } from '../api/order'
import { fetchPlanById, PERIODS, type PlanItem } from '../api/plan'
import { useI18n } from '../i18n'

const route=useRoute()
const router=useRouter()
const {t}=useI18n()
const plan=ref<PlanItem|null>(null)
const period=ref('')
const couponCode=ref('')
const couponDiscount=ref('')
const loading=ref(true)
const buying=ref(false)
const error=ref('')

const availablePeriods=computed(()=>plan.value?PERIODS.filter(([key])=>Number((plan.value as unknown as Record<string,unknown>)[key]||0)>0):[])

async function load(){
  loading.value=true
  error.value=''
  try{
    plan.value=await fetchPlanById(Number(route.params.id))
    period.value=availablePeriods.value[0]?.[0]||''
  }catch(e){
    error.value=errorMessage(e)
    await router.replace('/plan')
  }finally{loading.value=false}
}
onMounted(()=>void load())
watch(()=>route.params.id,()=>void load())
watch(period,()=>{couponDiscount.value='';couponCode.value=''})

function price(key:string){
  if(!plan.value)return '¥ 0.00'
  return '¥ '+(Number((plan.value as unknown as Record<string,unknown>)[key]||0)/100).toFixed(2)
}
function periodLabel(key:string){
  return ({
    month_price:t('period.month'),quarter_price:t('period.quarter'),half_year_price:t('period.halfYear'),
    year_price:t('period.year'),two_year_price:t('period.twoYear'),three_year_price:t('period.threeYear'),
    onetime_price:t('period.onetime'),reset_price:t('period.reset'),
  } as Record<string,string>)[key]||key
}
function description(value?:string){
  return value?value.replace(/[#*_]/g,' ').replace(/\s+/g,' ').trim():t('plan.noDescription')
}
async function applyCoupon(){
  if(!plan.value||!couponCode.value.trim()||!period.value)return
  error.value=''
  try{
    const info=await checkCoupon({code:couponCode.value.trim(),plan_id:plan.value.id,period:period.value})
    couponDiscount.value=info.type===2?String(info.value)+'%':'¥ '+(Number(info.value)/100).toFixed(2)
  }catch(e){couponDiscount.value='';error.value=errorMessage(e)}
}
async function buy(){
  if(!plan.value||!period.value)return
  buying.value=true
  error.value=''
  try{
    const blocking=await fetchFirstBlockingOrder()
    if(blocking){
      if(blocking.status===1)throw new Error(t('order.processing'))
      if(!confirm(t('order.confirmCancel')))return
      await cancelOrder(blocking.trade_no)
    }
    const tradeNo=await saveOrder({
      plan_id:plan.value.id,
      period:period.value,
      ...(couponCode.value.trim()?{coupon_code:couponCode.value.trim()}:{}),
    })
    await router.push('/order/'+tradeNo)
  }catch(e){error.value=errorMessage(e)}finally{buying.value=false}
}
</script>

<template>
  <div class="plan-detail-page">
    <div v-if="error" class="page-alert">{{ error }}</div>
    <div v-if="loading" class="xboard-card skeleton-card"/>

    <section v-else-if="plan" class="xboard-card plan-detail-xcard">
      <header class="xboard-card-header">{{ plan.name }}</header>
      <div class="xboard-card-body">
        <p class="plan-detail-copy">{{ description(plan.content) }}</p>

        <div v-if="!availablePeriods.length" class="page-alert">{{ t('plan.noPeriod') }}</div>

        <template v-else>
          <div class="plan-section-label">{{ t('plan.choosePeriod') }}</div>
          <div class="period-grid plan-radio-grid">
            <label
              v-for="[key] in availablePeriods"
              :key="key"
              class="period-option"
              :class="{active:period===key}"
            >
              <input v-model="period" type="radio" :value="key"/>
              <span><strong>{{ periodLabel(key) }}</strong><small>{{ price(key) }}</small></span>
            </label>
          </div>

          <div class="plan-section-label">{{ t('plan.coupon') }}</div>
          <div class="coupon-check-row plan-coupon-row">
            <input v-model="couponCode" class="form-control" :placeholder="t('plan.couponPlaceholder')"/>
            <button class="secondary-btn" :disabled="!couponCode.trim()||!period" @click="applyCoupon">{{ t('plan.validate') }}</button>
          </div>
          <p v-if="couponDiscount" class="coupon-success">{{ t('plan.couponValid',{discount:couponDiscount}) }}</p>

          <button class="primary-btn plan-buy-btn" :disabled="buying||!period" @click="buy">
            {{ buying?t('plan.creating'):t('plan.createOrder') }}
          </button>
        </template>
      </div>
    </section>
  </div>
</template>
