<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { checkGiftCard, fetchGiftCardDetail, fetchGiftCardHistory, redeemGiftCard, type GiftCardCheckResult, type GiftCardDetail, type GiftCardHistoryItem, type GiftCardRewards } from '../api/gift-card'
import { errorMessage } from '../api/client'
import { useI18n } from '../i18n'

const {t,locale}=useI18n()
const code=ref('')
const preview=ref<GiftCardCheckResult|null>(null)
const history=ref<GiftCardHistoryItem[]>([])
const error=ref('')
const success=ref('')
const acting=ref(false)
const redeemOpen=ref(false)
const detail=ref<GiftCardDetail|null>(null)
const detailLoading=ref(false)

onMounted(()=>void loadHistory())

async function loadHistory(){
  try{history.value=(await fetchGiftCardHistory({page:1,per_page:20})).data||[]}catch(e){error.value=errorMessage(e)}
}
function openRedeem(){
  code.value=''
  preview.value=null
  redeemOpen.value=true
}
async function check(){
  if(!code.value.trim())return
  acting.value=true
  error.value=''
  success.value=''
  try{preview.value=await checkGiftCard(code.value.trim())}catch(e){preview.value=null;error.value=errorMessage(e)}finally{acting.value=false}
}
async function openDetail(id:number){
  detailLoading.value=true
  error.value=''
  try{detail.value=await fetchGiftCardDetail(id)}catch(e){error.value=errorMessage(e)}finally{detailLoading.value=false}
}
async function redeem(){
  if(!code.value.trim())return
  acting.value=true
  try{
    const result=await redeemGiftCard(code.value.trim())
    success.value=result.message||t('gift.redeemed',{name:result.template_name})
    preview.value=null
    code.value=''
    redeemOpen.value=false
    await loadHistory()
  }catch(e){error.value=errorMessage(e)}finally{acting.value=false}
}
function rewardText(r:GiftCardRewards){
  const parts:string[]=[]
  if(r.balance)parts.push(t('gift.balanceReward',{amount:(Number(r.balance)/100).toFixed(2)}))
  if(r.transfer_enable)parts.push(t('gift.trafficReward',{amount:(Number(r.transfer_enable)/1073741824).toFixed(2)}))
  if(r.expire_days)parts.push(t('gift.expireReward',{days:r.expire_days}))
  if(r.plan_id)parts.push(t('gift.planReward',{id:r.plan_id}))
  if(r.device_limit)parts.push(t('gift.deviceReward',{count:r.device_limit}))
  return parts.length?parts.join(' · '):t('gift.rewardFallback')
}
function date(v?:number){return v?new Date(v*1000).toLocaleString(locale.value):'-'}
</script>

<template>
  <div class="gift-page">
    <div v-if="error" class="page-alert">{{ error }}</div>
    <div v-if="success" class="page-alert success">{{ success }}</div>

    <section class="xboard-card gift-main-card">
      <header class="xboard-card-header gift-card-header">
        <span>{{ t('gift.title') }}</span>
        <button class="primary-btn small-btn" @click="openRedeem">{{ t('gift.confirmRedeem') }}</button>
      </header>
      <div class="xboard-card-body">
        <p class="gift-card-hint">{{ t('gift.desc') }}</p>
      </div>
    </section>

    <section class="xboard-card gift-history-card">
      <header class="xboard-card-header">{{ t('gift.history') }}</header>
      <div class="responsive-table">
        <table>
          <thead>
            <tr>
              <th>{{ t('gift.template') }}</th>
              <th>{{ t('common.type') }}</th>
              <th>{{ t('gift.code') }}</th>
              <th>{{ t('gift.reward') }}</th>
              <th>{{ t('common.time') }}</th>
              <th>{{ t('common.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in history" :key="row.id">
              <td>{{ row.template_name }}</td>
              <td><span class="x-tag">{{ row.template_type_name }}</span></td>
              <td><code>{{ row.code }}</code></td>
              <td>{{ rewardText(row.rewards_given) }}</td>
              <td>{{ date(row.created_at) }}</td>
              <td><button class="order-link-btn" @click="openDetail(row.id)">{{ t('common.detail') }}</button></td>
            </tr>
            <tr v-if="!history.length"><td colspan="6" class="empty-cell">{{ t('gift.noHistory') }}</td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <div v-if="redeemOpen" class="modal-mask" @click.self="redeemOpen=false">
      <div class="user-modal gift-redeem-modal">
        <div class="modal-head">
          <h2>{{ t('gift.enter') }}</h2>
          <button class="user-icon-btn" @click="redeemOpen=false">×</button>
        </div>
        <input v-model="code" class="form-control" placeholder="GC-XXXX-XXXX" :disabled="acting" @keyup.enter="check"/>
        <div class="modal-actions gift-check-actions">
          <button class="secondary-btn" :disabled="acting" @click="redeemOpen=false">{{ t('common.cancel') }}</button>
          <button class="primary-btn" :disabled="acting||!code.trim()" @click="check">{{ acting?t('gift.checking'):t('gift.check') }}</button>
        </div>

        <div v-if="preview" class="gift-preview">
          <div class="gift-preview-title">{{ preview.code_info.template.name }}</div>
          <p v-if="preview.code_info.template.description">{{ preview.code_info.template.description }}</p>
          <span class="x-tag">{{ preview.code_info.template.type_name }}</span>
          <div class="gift-preview-rewards">
            <span>{{ t('gift.reward') }}</span>
            <strong>{{ rewardText(preview.reward_preview) }}</strong>
          </div>
          <p v-if="preview.reason" class="gift-preview-warning">{{ preview.reason }}</p>
          <div v-if="preview.can_redeem" class="modal-actions">
            <button class="primary-btn" :disabled="acting" @click="redeem">{{ t('gift.confirmRedeem') }}</button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="detail||detailLoading" class="modal-mask" @click.self="detail=null">
      <div class="user-modal wide">
        <div class="modal-head"><div><h2>{{ t('gift.detail') }}</h2><small>{{ detail?.code||'' }}</small></div><button class="user-icon-btn" @click="detail=null">×</button></div>
        <div v-if="detailLoading" class="empty-state">{{ t('gift.loading') }}</div>
        <div v-else-if="detail" class="gift-detail-grid">
          <dl class="gift-detail-table">
            <div><dt>{{ t('gift.template') }}</dt><dd>{{ detail.template.name }}</dd></div>
            <div><dt>{{ t('common.type') }}</dt><dd>{{ detail.template.type_name }}</dd></div>
            <div><dt>{{ t('gift.code') }}</dt><dd><code>{{ detail.code }}</code></dd></div>
            <div><dt>{{ t('gift.rewards') }}</dt><dd>{{ rewardText(detail.rewards_given) }}</dd></div>
            <div><dt>{{ t('gift.redeemTime') }}</dt><dd>{{ date(detail.created_at) }}</dd></div>
            <div v-if="detail.multiplier_applied&&detail.multiplier_applied!==1"><dt>{{ t('gift.multiplier') }}</dt><dd>{{ detail.multiplier_applied }}x</dd></div>
          </dl>
        </div>
      </div>
    </div>
  </div>
</template>
