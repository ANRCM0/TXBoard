<script setup lang="ts">
import QRCode from 'qrcode'
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { canCancelOrder, cancelOrder, checkOrderStatus, checkoutOrder, checkoutOrderWithMethod, fetchOrderDetail, fetchPaymentMethods, type OrderDetail, type PaymentMethod } from '../api/order'
import { errorMessage } from '../api/client'
import StripeCardForm from '../components/StripeCardForm.vue'
import { useI18n } from '../i18n'

const route=useRoute()
const {t,locale}=useI18n()
const order=ref<OrderDetail|null>(null)
const methods=ref<PaymentMethod[]>([])
const selectedMethod=ref<number|null>(null)
const loading=ref(true)
const paying=ref(false)
const error=ref('')
const qrOpen=ref(false)
const qrDataUrl=ref('')
const redirectHint=ref('')
const stripeForm=ref<InstanceType<typeof StripeCardForm>|null>(null)
let pollTimer:number|null=null

const selectedPayment=computed(()=>methods.value.find(item=>item.id===selectedMethod.value))
const handlingFee=computed(()=>{
  if(!order.value||!selectedPayment.value)return 0
  const method=selectedPayment.value
  return Math.round(order.value.total_amount*Number(method.handling_fee_percent||0)/100)+Number(method.handling_fee_fixed||0)
})
const payTotal=computed(()=>Math.max(0,Number(order.value?.total_amount||0)+handlingFee.value))

async function load(){
  stopPoll()
  loading.value=true
  error.value=''
  qrOpen.value=false
  qrDataUrl.value=''
  redirectHint.value=''
  try{
    const tradeNo=String(route.params.trade_no||'')
    order.value=await fetchOrderDetail(tradeNo)
    if(order.value.status===0){
      methods.value=await fetchPaymentMethods().catch(()=>[])
      const locked=Number(order.value.payment_id||0)
      selectedMethod.value=locked||methods.value[0]?.id||null
    }else{
      methods.value=[]
      selectedMethod.value=null
    }
  }catch(e){error.value=errorMessage(e)}finally{loading.value=false}
}

onMounted(()=>void load())
watch(()=>route.params.trade_no,()=>void load())
onUnmounted(stopPoll)

function stopPoll(){
  if(pollTimer!==null){window.clearTimeout(pollTimer);pollTimer=null}
}
function startPoll(){
  stopPoll()
  if(!order.value)return
  const tradeNo=order.value.trade_no
  const tick=async()=>{
    try{
      const status=await checkOrderStatus(tradeNo)
      if(status!==0){stopPoll();await load();return}
    }catch{}
    pollTimer=window.setTimeout(()=>void tick(),3000)
  }
  pollTimer=window.setTimeout(()=>void tick(),3000)
}

async function pay(){
  if(!order.value)return
  error.value=''
  redirectHint.value=''
  paying.value=true
  try{
    let token:string|undefined
    if(selectedPayment.value?.payment==='StripeCredit'){
      if(stripeForm.value?.mountError)throw new Error(stripeForm.value.mountError)
      if(!stripeForm.value?.ready)throw new Error(t('order.stripeLoading'))
      token=await stripeForm.value.createToken()
      if(!token)throw new Error(t('order.stripeToken'))
    }
    const result=payTotal.value<=0
      ? await checkoutOrder(order.value.trade_no)
      : selectedMethod.value!==null
        ? await checkoutOrderWithMethod(order.value.trade_no,selectedMethod.value,token)
        : await checkoutOrder(order.value.trade_no)

    if(result.type===-1||result.type===2||result.data===true){await load();return}
    if(result.type===0&&typeof result.data==='string'){
      qrDataUrl.value=await QRCode.toDataURL(result.data,{width:240,margin:1})
      qrOpen.value=true
      startPoll()
      return
    }
    if(result.type===1&&typeof result.data==='string'){
      const win=window.open(result.data,'_blank')
      if(win)win.opener=null
      redirectHint.value=win?t('order.redirectOpened'):t('order.popupBlocked')
      startPoll()
      return
    }
    startPoll()
  }catch(e){error.value=errorMessage(e)}finally{paying.value=false}
}

async function cancel(){
  if(!order.value||!confirm(t('order.confirmCancel')))return
  try{await cancelOrder(order.value.trade_no);await load()}catch(e){error.value=errorMessage(e)}
}

function money(cents?:number|null){return '¥ '+(Number(cents||0)/100).toFixed(2)}
function date(ts?:number|null){return ts?new Date(ts*1000).toLocaleString(locale.value):'-'}
function typeLabel(type?:number){
  return ({1:t('order.new'),2:t('order.renewal'),3:t('order.upgrade'),4:t('order.resetFlow')} as Record<number,string>)[Number(type)]||'-'
}
function statusLabel(value:number){
  return ({0:t('order.pending'),1:t('order.processing'),2:t('order.cancelled'),3:t('order.completed'),4:t('order.discounted')} as Record<number,string>)[value]||String(value)
}
function statusClass(value:number){
  if(value===3)return 'ok'
  if(value===0)return 'warn'
  if(value===1||value===4)return 'info'
  return 'bad'
}
</script>

<template>
  <div class="order-detail-page">
    <div v-if="error" class="page-alert">{{ error }}</div>
    <div v-if="redirectHint" class="page-alert success">{{ redirectHint }}</div>
    <div v-if="loading" class="xboard-card skeleton-card"/>

    <div v-else-if="order" class="order-detail-layout">
      <div class="order-detail-main">
        <section class="xboard-card order-info-card">
          <header class="xboard-card-header">{{ t('order.product') }}</header>
          <div class="xboard-card-body">
            <div class="order-info-row">
              <span>{{ t('order.plan') }}</span>
              <strong>{{ order.plan?.name||('Plan #'+order.plan_id) }}</strong>
            </div>
            <div class="order-info-row">
              <span>{{ t('order.period') }}</span>
              <strong>{{ order.period }}</strong>
            </div>
            <div class="order-info-row">
              <span>{{ t('order.planTraffic') }}</span>
              <strong>{{ order.plan?.transfer_enable??'-' }} GB</strong>
            </div>
          </div>
        </section>

        <section class="xboard-card order-info-card">
          <header class="xboard-card-header order-card-title-row">
            <span>{{ t('order.detailTitle') }}</span>
            <button v-if="canCancelOrder(order)" class="order-close-btn" @click="cancel">{{ t('order.cancelOrder') }}</button>
          </header>
          <div class="xboard-card-body">
            <div class="order-info-row">
              <span>{{ t('order.tradeNo') }}</span>
              <strong class="order-trade-value">{{ order.trade_no }}</strong>
            </div>
            <div class="order-info-row">
              <span>{{ t('order.orderType') }}</span>
              <strong>{{ typeLabel(order.type) }}</strong>
            </div>
            <div class="order-info-row">
              <span>{{ t('order.orderAmount') }}</span>
              <strong>{{ money(order.total_amount) }}</strong>
            </div>
            <div class="order-info-row">
              <span>{{ t('common.status') }}</span>
              <strong class="order-status-inline">
                <span class="order-status-dot" :class="statusClass(order.status)"/>
                {{ statusLabel(order.status) }}
              </strong>
            </div>
            <div class="order-info-row">
              <span>{{ t('order.createdAt') }}</span>
              <strong>{{ date(order.created_at) }}</strong>
            </div>
            <div class="order-info-row">
              <span>{{ t('order.paidAt') }}</span>
              <strong>{{ date(order.paid_at) }}</strong>
            </div>
          </div>
        </section>

        <section v-if="order.status===0" class="xboard-card payment-card">
          <header class="xboard-card-header">{{ t('order.payment') }}</header>
          <div class="payment-list">
            <label
              v-for="method in methods"
              :key="method.id"
              class="payment-method"
              :class="{active:selectedMethod===method.id,disabled:Boolean(order.payment_id)&&Number(order.payment_id)!==method.id}"
            >
              <input
                v-model="selectedMethod"
                type="radio"
                :value="method.id"
                :disabled="Boolean(order.payment_id)&&Number(order.payment_id)!==method.id"
              />
              <span><strong>{{ method.name }}</strong><small>{{ method.payment }}</small></span>
              <img v-if="method.icon" :src="method.icon" alt=""/>
            </label>
          </div>
          <StripeCardForm v-if="selectedPayment?.payment==='StripeCredit'" ref="stripeForm" :payment-id="selectedMethod" class="payment-stripe"/>
          <div v-if="!methods.length" class="empty-state">{{ t('order.noPayment') }}</div>
        </section>
      </div>

      <aside v-if="order.status===0" class="order-summary-panel">
        <div class="order-summary-title">{{ t('order.total') }}</div>
        <div class="order-summary-plan">
          <span>{{ order.plan?.name||('Plan #'+order.plan_id) }}</span>
          <strong>{{ money(order.total_amount) }}</strong>
        </div>
        <div v-if="order.discount_amount" class="order-summary-row">
          <span>{{ t('plan.discount') }}</span><strong>-{{ money(order.discount_amount) }}</strong>
        </div>
        <div v-if="order.balance_amount" class="order-summary-row">
          <span>{{ t('order.balancePaid') }}</span><strong>-{{ money(order.balance_amount) }}</strong>
        </div>
        <div v-if="handlingFee" class="order-summary-row">
          <span>{{ t('order.handlingFee') }}</span><strong>+{{ money(handlingFee) }}</strong>
        </div>
        <div class="order-summary-total">
          <span>{{ t('order.total') }}</span>
          <strong>{{ money(payTotal) }}</strong>
        </div>
        <button class="primary-btn full-btn order-checkout-btn" :disabled="paying" @click="pay">
          {{ paying?t('order.paying'):t('order.payNow') }}
        </button>
      </aside>
    </div>

    <div v-if="qrOpen" class="modal-mask" @click.self="qrOpen=false">
      <div class="user-modal qr-modal">
        <div class="modal-head"><h2>{{ t('order.qrPay') }}</h2><button class="user-icon-btn" @click="qrOpen=false">×</button></div>
        <img v-if="qrDataUrl" :src="qrDataUrl" alt="QR"/><p class="muted-copy">{{ t('order.qrHint') }}</p>
      </div>
    </div>
  </div>
</template>
