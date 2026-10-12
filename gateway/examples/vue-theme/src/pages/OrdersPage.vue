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
    <nav v-if="total !== null" aria-label="订单分页">
      <button :disabled="loading || current === 1" @click="changePage(current - 1)">上一页</button>
      <span>第 {{ current }} / {{ lastPage }} 页，共 {{ total }} 条</span>
      <button :disabled="loading || current >= lastPage" @click="changePage(current + 1)">下一页</button>
    </nav>
    <p v-else-if="!loading" class="muted">此 Gateway 未提供分页总数；当前列表可能不含完整历史。</p>
  </section>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import type { GatewayApiError, Order } from '@txboard/theme-sdk'
import { api, newIdempotencyKey } from '../api'
import { formatMoney } from '../lib/format'
import ErrorState from '../components/ErrorState.vue'

const STATUS_LABELS: Record<number, string> = { 0: '待支付', 1: '开通中', 2: '已取消', 3: '已完成', 4: '已折抵' }

const orders = ref<Order[]>([])
const loading = ref(true)
const error = ref<Error | null>(null)
const createNotice = ref('')
const current = ref(1)
const lastPage = ref(1)
const total = ref<number | null>(null)

async function load() {
  loading.value = true
  error.value = null
  try {
    const bootstrap = await api.bootstrap()
    if (bootstrap.capabilities.includes('orders.page')) {
      const page = await api.orders.page({ current: current.value, page_size: 20 })
      orders.value = page.items
      lastPage.value = page.lastPage
      total.value = page.total
    } else {
      orders.value = await api.orders.list()
      lastPage.value = 1
      total.value = null
    }
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

async function changePage(page: number) {
  current.value = page
  await load()
}

onMounted(load)
</script>
