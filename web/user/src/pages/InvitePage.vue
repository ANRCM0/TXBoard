<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { fetchGuestConfig } from '../api/comm'
import { fetchInvite, fetchInviteDetails, generateInviteCode, transferCommission, withdrawCommission, type InviteCode } from '../api/invite'
import { errorMessage } from '../api/client'
import { useUserCommConfig } from '../composables/useUserCommConfig'
import { featureEnabled } from '../lib/feature-flags'
import { useI18n } from '../i18n'

const router=useRouter()
const {t,locale}=useI18n()
const codes=ref<InviteCode[]>([])
const stat=ref<number[]>([])
const details=ref<Array<{created_at?:number;get_amount?:number}>>([])
const error=ref('')
const success=ref('')
const transferAmount=ref('')
const withdrawMethod=ref('')
const withdrawAccount=ref('')
const appUrl=ref('')
const acting=ref(false)
const {config,load:loadConfig}=useUserCommConfig()

const available=computed(()=>Number(stat.value[4]||0)/100)
function positiveAmount(v:unknown){
  const n=Number(v||0)
  return Number.isFinite(n)&&n>0?n:0
}
// Transfer and withdrawal have independent minimums. Older backends omit
// commission_transfer_limit entirely and an unset field can arrive as an empty
// string, so both cases inherit the withdrawal minimum. A value that is
// present and numeric (including 0, meaning no minimum) is authoritative.
const transferMinimum=computed(()=>{
  const raw=config.value?.commission_transfer_limit
  if(raw===undefined||raw===null||raw==='')return positiveAmount(config.value?.commission_withdraw_limit)
  return positiveAmount(raw)
})
const withdrawMinimum=computed(()=>positiveAmount(config.value?.commission_withdraw_limit))
const baseRate=computed(()=>Number(stat.value[3]||0))
const distributionEnabled=computed(()=>featureEnabled(config.value?.commission_distribution_enable,config.value!==null))
const distributionTiers=computed(()=>[
  {label:t('invite.level1'),value:Math.floor((Number(config.value?.commission_distribution_l1)||0)*baseRate.value/100)},
  {label:t('invite.level2'),value:Math.floor((Number(config.value?.commission_distribution_l2)||0)*baseRate.value/100)},
  {label:t('invite.level3'),value:Math.floor((Number(config.value?.commission_distribution_l3)||0)*baseRate.value/100)},
])
const withdrawEnabled=computed(()=>
  config.value!==null &&
  featureEnabled(config.value.commission_enable,true) &&
  !featureEnabled(config.value.withdraw_close,true)
)

async function load(){
  try{
    const [info,detail,guest]=await Promise.all([
      fetchInvite(),
      fetchInviteDetails().catch(()=>({data:[],total:0})),
      fetchGuestConfig().catch(()=>null),
      loadConfig().catch(()=>null),
    ])
    codes.value=info.codes||[]
    stat.value=info.stat||[]
    details.value=detail.data||[]
    appUrl.value=guest?.app_url?.replace(/\/$/,'')||window.location.origin
    const methods=config.value?.withdraw_methods
    if(Array.isArray(methods)&&methods.length&&!withdrawMethod.value)withdrawMethod.value=String(methods[0])
  }catch(e){error.value=errorMessage(e)}
}
onMounted(()=>void load())

async function generate(){
  acting.value=true
  error.value=''
  try{await generateInviteCode();success.value=t('invite.generated');await load()}catch(e){error.value=errorMessage(e)}finally{acting.value=false}
}

async function transfer(){
  const amount=Number(transferAmount.value)
  error.value=''
  success.value=''
  if(!(amount>0)){error.value=t('invite.invalidAmount');return}
  if(!/^\d+(\.\d{1,2})?$/.test(transferAmount.value.trim())){error.value=t('invite.invalidAmount');return}
  if(Math.round(amount*100)>Math.round(available.value*100)){error.value=t('invite.insufficient');return}
  if(transferMinimum.value>0&&amount<transferMinimum.value){error.value=t('invite.minimum',{amount:moneyMajor(transferMinimum.value)});return}
  acting.value=true
  try{
    await transferCommission(Math.round(amount*100))
    transferAmount.value=''
    success.value=t('invite.transferred')
    await load()
  }catch(e){error.value=errorMessage(e)}finally{acting.value=false}
}

async function withdraw(){
  error.value=''
  success.value=''
  if(!withdrawEnabled.value){error.value=t('invite.withdrawClosed');return}
  if(!withdrawMethod.value){error.value=t('invite.selectMethod');return}
  if(!withdrawAccount.value.trim()){error.value=t('invite.enterAccount');return}
  if(withdrawMinimum.value>0&&available.value<withdrawMinimum.value){error.value=t('invite.withdrawMinimum',{amount:moneyMajor(withdrawMinimum.value)});return}
  acting.value=true
  try{
    await withdrawCommission({withdraw_method:withdrawMethod.value,withdraw_account:withdrawAccount.value.trim()})
    withdrawAccount.value=''
    success.value=t('invite.withdrawSubmitted')
    if(featureEnabled(config.value?.ticket_enable,config.value!==null))await router.push('/ticket')
    else await load()
  }catch(e){error.value=errorMessage(e)}finally{acting.value=false}
}

async function copy(code:string){
  try{
    await navigator.clipboard.writeText(appUrl.value+'/#/login?tab=register&code='+encodeURIComponent(code))
    success.value=t('invite.linkCopied')
  }catch{error.value=t('invite.copyFailed')}
}

function moneyCents(v:number){return '¥ '+(Number(v||0)/100).toFixed(2)}
function moneyMajor(v:number){return '¥ '+Number(v||0).toFixed(2)}
function date(v?:number){return v?new Date(v*1000).toLocaleString(locale.value):'-'}
</script>

<template>
  <div class="invite-page">
    <div v-if="error" class="page-alert">{{ error }}</div>
    <div v-if="success" class="page-alert success">{{ success }}</div>

    <div class="invite-summary-grid">
      <div class="txboard-card invite-summary-card"><span>{{ t('invite.people') }}</span><strong>{{ stat[0]||0 }}</strong><small>{{ t('invite.accumulated') }}</small></div>
      <div class="txboard-card invite-summary-card"><span>{{ t('invite.validCommission') }}</span><strong>{{ moneyCents(stat[1]||0) }}</strong><small>{{ t('invite.validCommissionHint') }}</small></div>
      <div class="txboard-card invite-summary-card"><span>{{ t('invite.pendingCommission') }}</span><strong>{{ moneyCents(stat[2]||0) }}</strong><small>{{ t('invite.pendingCommissionHint') }}</small></div>
      <div class="txboard-card invite-summary-card"><span>{{ t('invite.available') }}</span><strong>{{ moneyMajor(available) }}</strong><small>{{ t('invite.availableHint') }}</small></div>
    </div>

    <section v-if="distributionEnabled" class="txboard-card invite-distribution-card">
      <div class="section-head"><div><span class="eyebrow">DISTRIBUTION</span><h2>{{ t('invite.distribution') }}</h2></div></div>
      <div class="commission-tier-grid">
        <div class="commission-tier"><span>{{ t('invite.baseRate') }}</span><strong>{{ baseRate.toFixed(0) }}%</strong></div>
        <div v-for="tier in distributionTiers" :key="tier.label" class="commission-tier"><span>{{ tier.label }}</span><strong>{{ tier.value }}%</strong></div>
      </div>
    </section>

    <div class="invite-two-column">
      <section class="txboard-card invite-section-card">
        <div class="section-head"><div><span class="eyebrow">CODES</span><h2>{{ t('invite.codes') }}</h2></div><button class="primary-btn small-btn" :disabled="acting" @click="generate">{{ t('invite.generate') }}</button></div>
        <div class="invite-code-list">
          <div v-for="code in codes" :key="code.code" class="invite-code-row">
            <div><code>{{ code.code }}</code><span>{{ t('invite.visits',{count:code.pv||0}) }}</span></div>
            <button class="secondary-btn small-btn" @click="copy(code.code)">{{ t('invite.copyLink') }}</button>
          </div>
          <div v-if="!codes.length" class="empty-state">{{ t('invite.noCodes') }}</div>
        </div>
      </section>

      <section class="txboard-card invite-section-card">
        <div class="section-head"><div><span class="eyebrow">TRANSFER</span><h2>{{ t('invite.transfer') }}</h2></div></div>
        <div class="form-stack">
          <label class="field-label">{{ t('invite.transferAmount') }}<input v-model="transferAmount" type="number" min="0.01" step="0.01" class="form-control" :placeholder="t('invite.max',{amount:available.toFixed(2)})"/></label>
          <p v-if="transferMinimum" class="muted-copy">{{ t('invite.minimum',{amount:moneyMajor(transferMinimum)}) }}</p>
          <button class="primary-btn full-btn" :disabled="acting||!Number(transferAmount)" @click="transfer">{{ t('invite.transferBalance') }}</button>
        </div>
      </section>
    </div>

    <section v-if="withdrawEnabled" class="txboard-card invite-section-card">
      <div class="section-head"><div><span class="eyebrow">WITHDRAW</span><h2>{{ t('invite.withdraw') }}</h2><p>{{ t('invite.withdrawDesc') }}</p></div></div>
      <div class="withdraw-grid">
        <label class="field-label">{{ t('invite.withdrawMethod') }}
          <select v-model="withdrawMethod" class="form-control"><option v-for="method in config?.withdraw_methods||[]" :key="method" :value="String(method)">{{ method }}</option></select>
        </label>
        <label class="field-label">{{ t('invite.withdrawAccount') }}<input v-model="withdrawAccount" class="form-control" :placeholder="t('invite.withdrawPlaceholder')"/></label>
      </div>
      <div class="withdraw-notes">
        <span>{{ t('invite.withdrawable',{amount:moneyMajor(available)}) }}</span>
        <span v-if="withdrawMinimum">{{ t('invite.withdrawMinimum',{amount:moneyMajor(withdrawMinimum)}) }}</span>
      </div>
      <button class="secondary-btn" :disabled="acting||!withdrawAccount.trim()||!withdrawMethod" @click="withdraw">{{ t('invite.submitWithdraw') }}</button>
    </section>

    <section class="txboard-card invite-history-card">
      <div class="section-head"><div><span class="eyebrow">HISTORY</span><h2>{{ t('invite.history') }}</h2></div></div>
      <div class="responsive-table"><table><thead><tr><th>{{ t('common.time') }}</th><th>{{ t('invite.commission') }}</th></tr></thead><tbody>
        <tr v-for="(row,index) in details" :key="index"><td>{{ date(row.created_at) }}</td><td><strong>{{ moneyCents(row.get_amount||0) }}</strong></td></tr>
        <tr v-if="!details.length"><td colspan="2" class="empty-cell">{{ t('common.none') }}</td></tr>
      </tbody></table></div>
    </section>
  </div>
</template>
