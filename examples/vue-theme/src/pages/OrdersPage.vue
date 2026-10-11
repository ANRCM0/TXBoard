// Vue orders page: read-only list/detail/status + gated create (GW-504).
<template>
  <section class="panel">
    <h2>我的订单</h2>

    <ErrorState v-if="error" :error="error" @retry="load" />

    <p v-if="createNotice" class="notice" role="status">{{ createNotice }}</p>

    <table v-if="orders.length && !loading">
      <thead>
        <tr><th>订单号</th><th>状态</th><th>金额</th><th>操作</th></tr>
      </thead>
      <tbody>
        <tr v-for="order in orders" :key="order.trade_no">
          <td>{{ order.trade_no }}</td>
          <td>{{ STATUS_LABELS[order.status] ?? '未知' }}</td>
          <td>{{ formatMoney(order.total_amount) }}</td>
          <td>
            <button class="link" @click="checkStatus(order.trade_no)">刷新状态</button>
          </td>
        </tr>
      </tbody>
    </table>
    <p v-else-if="!loading && !error" class="muted">暂无订单。</p>
    <p v-if="loading" role="status">加载中…</p>
  </section>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import type { GatewayApiError, Order } from '@txboard/theme-sdk'
import { api, newIdempotencyKey } from '../api'
import { formatMoney } from '../lib/format'
import ErrorState from '../components/ErrorState.vue'

const STATUS_LABELS: Record<number, string> = { 0: '待支付', 1: '已支付', 2: '已取消', 3: '其他' }

const orders = ref<Order[]>([])
const loading = ref(true)
const error = ref<Error | null>(null)
const createNotice = ref('')

async function load() {
  loading.value = true
  error.value = null
  try {
    orders.value = await api.orders.list()
  } catch (err) {
    error.value = err as Error
  } finally {
    loading.value = false
  }
}

async function checkStatus(tradeNo: string) {
  try {
    const status = await api.orders.status(tradeNo)
    const target = orders.value.find(o => o.trade_no === tradeNo)
    if (target) target.status = status.status
  } catch (err) {
    error.value = err as Error
  }
}

/**
 * Create an order (GW-304, default 405 unless the deployment enabled
 * GATEWAY_ORDER_WRITE_ENABLED — the capability is advertised in bootstrap).
 * The SDK never auto-retries a write. IDEMPOTENCY_IN_PROGRESS means: back off
 * and resend the SAME key. ORDER_REJECTED means the order was refused.
 */
async function createOrder(planId: number, period: string, draftKey: string) {
  try {
    const result = await api.orders.create(
      { plan_id: planId, period },
      { idempotencyKey: draftKey || newIdempotencyKey() },
    )
    createNotice.value = `订单已创建：${result.trade_no}`
    await load()
  } catch (err) {
    const gerr = err as GatewayApiError
    if (gerr.code === 'IDEMPOTENCY_IN_PROGRESS') {
      createNotice.value = '订单正在处理中，请稍后重试。'
    } else if (gerr.code === 'IDEMPOTENCY_CONFLICT') {
      createNotice.value = '该订单草稿已用于不同请求，请重新选择套餐。'
    } else if (gerr.code === 'ORDER_REJECTED') {
      createNotice.value = '订单被拒绝：' + gerr.message
    } else {
      error.value = gerr
    }
  }
}

onMounted(load)
</script>
