<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { canCancelOrder, cancelOrder, fetchOrders, type OrderItem } from '../api/order'
import { errorMessage } from '../api/client'
import { useI18n } from '../i18n'

const router=useRouter()
const {t,locale}=useI18n()
const rows=ref<OrderItem[]>([])
const loading=ref(true)
const error=ref('')
const page=ref(1)
const total=ref(0)
const status=ref('')
const acting=ref('')

async function load(){
  loading.value=true
  try{
    const result=await fetchOrders({page:page.value,pageSize:20,...(status.value!==''?{status:Number(status.value)}:{})})
    rows.value=result.data||[]
    total.value=result.total||0
  }catch(e){error.value=errorMessage(e)}finally{loading.value=false}
}
onMounted(()=>void load())

async function cancel(row:OrderItem){
  if(!confirm(t('order.confirmCancel')))return
  acting.value=row.trade_no
  try{await cancelOrder(row.trade_no);await load()}catch(e){error.value=errorMessage(e)}finally{acting.value=''}
}
function money(cents:number){return '¥ '+(Number(cents||0)/100).toFixed(2)}
function date(ts:number){return new Date(ts*1000).toLocaleString(locale.value)}
function typeLabel(type?:number){
  return ({1:t('order.new'),2:t('order.renewal'),3:t('order.upgrade'),4:t('order.resetFlow')} as Record<number,string>)[Number(type)]||t('order.generic')
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
  <div class="order-list-page">
    <div class="order-toolbar">
      <select v-model="status" class="form-control compact" @change="page=1;load()">
        <option value="">{{ t('order.allStatus') }}</option>
        <option value="0">{{ t('order.pending') }}</option>
        <option value="1">{{ t('order.processing') }}</option>
        <option value="2">{{ t('order.cancelled') }}</option>
        <option value="3">{{ t('order.completed') }}</option>
        <option value="4">{{ t('order.discounted') }}</option>
      </select>
    </div>

    <div v-if="error" class="page-alert">{{ error }}</div>

    <section class="xboard-card order-table-card">
      <div class="responsive-table">
        <table>
          <thead>
            <tr>
              <th>{{ t('order.tradeNo') }}</th>
              <th>{{ t('common.type') }}</th>
              <th>{{ t('order.plan') }}</th>
              <th>{{ t('order.period') }}</th>
              <th>{{ t('common.amount') }}</th>
              <th>{{ t('common.status') }}</th>
              <th>{{ t('order.createdAt') }}</th>
              <th>{{ t('common.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.trade_no">
              <td>
                <button class="order-link-btn" @click="router.push('/order/'+row.trade_no)">{{ row.trade_no }}</button>
              </td>
              <td>{{ typeLabel(row.type) }}</td>
              <td>{{ row.plan?.name||('Plan #'+row.plan_id) }}</td>
              <td>{{ row.period }}</td>
              <td>{{ money(row.total_amount) }}</td>
              <td>
                <span class="order-status-inline">
                  <span class="order-status-dot" :class="statusClass(row.status)"/>
                  {{ statusLabel(row.status) }}
                </span>
              </td>
              <td>{{ date(row.created_at) }}</td>
              <td>
                <button class="order-link-btn" @click="router.push('/order/'+row.trade_no)">
                  {{ row.status===0?t('order.payDetail'):t('common.detail') }}
                </button>
                <span v-if="canCancelOrder(row)" class="order-actions-divider"/>
                <button
                  v-if="canCancelOrder(row)"
                  class="order-link-btn order-link-btn--danger"
                  :disabled="acting===row.trade_no"
                  @click="cancel(row)"
                >
                  {{ t('common.cancel') }}
                </button>
              </td>
            </tr>
            <tr v-if="!loading&&!rows.length"><td colspan="8" class="empty-cell">{{ t('order.empty') }}</td></tr>
          </tbody>
        </table>
      </div>
      <div class="pager">
        <span>{{ t('common.total',{total}) }}</span>
        <div>
          <button class="secondary-btn small-btn" :disabled="page<=1" @click="page--;load()">{{ t('common.previous') }}</button>
          <span>{{ t('common.page',{page}) }}</span>
          <button class="secondary-btn small-btn" :disabled="page*20>=total" @click="page++;load()">{{ t('common.next') }}</button>
        </div>
      </div>
    </section>
  </div>
</template>
