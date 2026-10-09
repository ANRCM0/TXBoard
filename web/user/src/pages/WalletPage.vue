<script setup lang="ts">
import QRCode from 'qrcode'
import { computed, onMounted, onUnmounted, ref } from 'vue'
import StripeCardForm from '../components/StripeCardForm.vue'
import { errorMessage } from '../api/client'
import {
  checkoutRecharge, createRecharge, fetchRecharge, fetchRechargeHistory,
  getRechargeMethods, getWalletBalance, moneyToMinor, type Recharge,
} from '../api/wallet'
import type { PaymentMethod } from '../api/order'
import { useI18n } from '../i18n'

const {locale}=useI18n()
const en=computed(()=>locale.value==='en-US')
const wallet=ref({balance_minor:0,commission_balance_minor:0})
const methods=ref<PaymentMethod[]>([])
const methodId=ref<number|null>(null)
const amount=ref('50.00')
const history=ref<Recharge[]>([])
const page=ref(1)
const total=ref(0)
const loading=ref(true)
const acting=ref(false)
const error=ref('')
const info=ref('')
const pending=ref<Recharge|null>(null)
const qrUrl=ref('')
const qrOpen=ref(false)
const stripeForm=ref<InstanceType<typeof StripeCardForm>|null>(null)
let pollTimer:number|null=null

const selectedMethod=computed(()=>methods.value.find(m=>m.id===methodId.value))
const feeEstimate=computed(()=>{
  if(!selectedMethod.value)return 0
  try{
    const minor=moneyToMinor(amount.value)
    return Math.max(0,Math.round(minor*Number(selectedMethod.value.handling_fee_percent||0)/100)
      +Number(selectedMethod.value.handling_fee_fixed||0))
  }catch{return 0}
})

function text(cn:string,english:string){return en.value?english:cn}
function money(minor:number){return '¥ '+(minor/100).toFixed(2)}
function date(ts:number){return new Date(ts*1000).toLocaleString(locale.value)}
function stopPoll(){if(pollTimer!==null){window.clearTimeout(pollTimer);pollTimer=null}}

async function refresh(){
  const [current,list]=await Promise.all([
    getWalletBalance(),fetchRechargeHistory(page.value),
  ])
  wallet.value=current
  history.value=list.data
  total.value=list.total
}
async function load(){
  loading.value=true
  error.value=''
  try{
    const [payments]=await Promise.all([getRechargeMethods(),refresh()])
    methods.value=payments
    if(!payments.some(m=>m.id===methodId.value))methodId.value=payments[0]?.id??null
  }catch(e){error.value=errorMessage(e)}finally{loading.value=false}
}
onMounted(()=>void load())
onUnmounted(stopPoll)

async function followPayment(tradeNo:string){
  stopPoll()
  async function tick(){
    try{
      const item=await fetchRecharge(tradeNo)
      if(item.status===1){
        stopPoll()
        pending.value=null
        qrOpen.value=false
        info.value=text('充值成功，余额已到账。','Recharge completed. Wallet credited.')
        await refresh()
        return
      }
    }catch{/* A transient status lookup failure must not claim payment. */}
    pollTimer=window.setTimeout(()=>void tick(),3000)
  }
  await tick()
}

async function payRecharge(item:Recharge){
  acting.value=true
  error.value=''
  pending.value=item
  try{
    const provider=methods.value.find(m=>m.id===item.payment_method_id)
    let token:string|undefined
    if(provider?.payment==='StripeCredit'){
      if(stripeForm.value?.mountError)throw new Error(stripeForm.value.mountError)
      if(!stripeForm.value?.ready)throw new Error(text('支付组件未准备好','Card form is not ready'))
      token=await stripeForm.value.createToken()
      if(!token)throw new Error(text('无法生成支付凭据','Card token could not be created'))
    }
    const result=await checkoutRecharge(item.trade_no,token)
    if(result.type===0&&typeof result.data==='string'){
      qrUrl.value=await QRCode.toDataURL(result.data,{width:240,margin:1})
      qrOpen.value=true
    }else if(result.type===1&&typeof result.data==='string'){
      const windowRef=window.open(result.data,'_blank')
      if(windowRef)windowRef.opener=null
      info.value=windowRef
        ?text('已打开支付窗口；完成支付后余额会自动更新。','Payment window opened. Balance updates after confirmation.')
        :text('浏览器拦截了支付窗口，请允许弹窗后重试。','Please allow popups and retry checkout.')
    }else{
      info.value=text('已发起支付，等待支付平台确认。','Payment initiated; waiting for confirmation.')
    }
    await followPayment(item.trade_no)
  }catch(e){error.value=errorMessage(e)}finally{acting.value=false}
}
async function create(){
  if(methodId.value===null){
    error.value=text('请选择支付方式','Select a payment method')
    return
  }
  error.value=''
  info.value=''
  acting.value=true
  try{
    const amountMinor=moneyToMinor(amount.value)
    const created=await createRecharge(amountMinor,methodId.value,crypto.randomUUID())
    await refresh()
    // Release the creation lock before calling checkout so UI can resume
    // with the pending record if the payment popup fails.
    acting.value=false
    await payRecharge(created)
  }catch(e){error.value=errorMessage(e)}finally{acting.value=false}
}
async function retry(item:Recharge){
  methodId.value=item.payment_method_id
  await payRecharge(item)
}
async function turnPage(step:number){
  if(page.value+step<1)return
  page.value+=step
  try{await refresh()}catch(e){error.value=errorMessage(e)}
}
</script>

<template>
  <div class="profile-page">
    <h2>{{ text('钱包充值','Wallet top-up') }}</h2>
    <div v-if="error" class="page-alert">{{ error }}</div>
    <div v-if="info" class="page-alert success">{{ info }}</div>

    <section class="xboard-card profile-wallet-card">
      <header class="xboard-card-header">{{ text('账户余额','Wallet balance') }}</header>
      <div class="xboard-card-body">
        <div class="profile-wallet-value">
          <strong>{{ money(wallet.balance_minor) }}</strong><span>CNY</span>
        </div>
        <p>{{ text('充值成功后计入账户余额，可用于支付套餐。佣金余额单独统计。','Successful top-ups credit the wallet for plan purchases. Commissions are separate.') }}</p>
      </div>
    </section>

    <section class="xboard-card profile-section-card">
      <header class="xboard-card-header">{{ text('充值金额与支付方式','Amount and payment method') }}</header>
      <div class="xboard-card-body profile-form-column">
        <label>{{ text('充值金额（元）','Top-up amount (CNY)') }}
          <input v-model="amount" type="text" inputmode="decimal" class="form-control"
                 placeholder="50.00" :disabled="acting"/>
        </label>
        <div class="order-toolbar">
          <button v-for="n in [10,50,100,200]" :key="n" class="secondary-btn small-btn"
                  :disabled="acting" @click="amount=n.toFixed(2)">¥{{ n }}</button>
        </div>
        <p>{{ text('单次 ¥1–5,000；充值只增加实际金额，支付渠道手续费单独显示。','¥1–5,000 per top-up. Provider fees are charged separately.') }}</p>
        <div v-if="methods.length" class="payment-list">
          <label v-for="method in methods" :key="method.id" class="payment-method"
                 :class="{active:methodId===method.id}">
            <input v-model="methodId" type="radio" :value="method.id" :disabled="acting"/>
            <span><strong>{{ method.name }}</strong><small>{{ method.payment }}</small></span>
          </label>
        </div>
        <div v-else class="empty-state">{{ text('暂无可用支付方式','No payment methods available') }}</div>
        <div v-if="methodId!==null" class="order-info-row">
          <span>{{ text('预计手续费','Estimated fee') }}</span><strong>{{ money(feeEstimate) }}</strong>
        </div>
        <StripeCardForm v-if="selectedMethod?.payment==='StripeCredit'" ref="stripeForm"
                        :payment-id="methodId" class="payment-stripe"/>
        <button class="primary-btn profile-save-btn" :disabled="acting||loading||methodId===null" @click="create">
          {{ acting?text('处理中…','Processing…'):text('立即充值','Continue to payment') }}
        </button>
      </div>
    </section>

    <section class="xboard-card profile-section-card">
      <header class="xboard-card-header">{{ text('充值记录','Recharge history') }}</header>
      <div class="responsive-table">
        <table>
          <thead><tr>
            <th>{{ text('流水号','Reference') }}</th>
            <th>{{ text('到账金额','Wallet credit') }}</th>
            <th>{{ text('实付（含手续费）','Total paid') }}</th>
            <th>{{ text('状态','Status') }}</th>
            <th>{{ text('创建时间','Created') }}</th>
            <th>{{ text('操作','Action') }}</th>
          </tr></thead>
          <tbody>
            <tr v-for="item in history" :key="item.trade_no">
              <td class="mono">{{ item.trade_no }}</td>
              <td>{{ money(item.amount_minor) }}</td>
              <td>{{ money(item.total_minor) }}</td>
              <td>{{ item.status===1?text('已到账','Paid'):text('待支付','Pending') }}</td>
              <td>{{ date(item.created_at) }}</td>
              <td>
                <button v-if="item.status===0" class="order-link-btn"
                        :disabled="acting" @click="retry(item)">
                  {{ text('继续支付','Continue') }}
                </button>
              </td>
            </tr>
            <tr v-if="!loading&&!history.length">
              <td colspan="6" class="empty-cell">{{ text('暂无充值记录','No recharge history') }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="pager">
        <span>{{ text('合计','Total') }} {{ total }}</span>
        <div>
          <button class="secondary-btn small-btn" :disabled="page<=1" @click="turnPage(-1)">
            {{ text('上一页','Previous') }}
          </button>
          <span>{{ page }}</span>
          <button class="secondary-btn small-btn" :disabled="page*20>=total" @click="turnPage(1)">
            {{ text('下一页','Next') }}
          </button>
        </div>
      </div>
    </section>

    <div v-if="qrOpen" class="modal-mask" @click.self="qrOpen=false">
      <div class="user-modal qr-modal">
        <div class="modal-head">
          <h2>{{ text('扫码充值','Scan to pay') }}</h2>
          <button class="user-icon-btn" @click="qrOpen=false">×</button>
        </div>
        <img v-if="qrUrl" :src="qrUrl" alt="Payment QR code"/>
        <p class="muted-copy">{{ text('支付到账以支付平台回调为准，请勿重复转账。','Wallet credit requires verified provider confirmation.') }}</p>
      </div>
    </div>
  </div>
</template>
