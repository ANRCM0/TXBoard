// my-theme orders page: read-only list/detail/status + gated create.
// docs/developer-guide.md §5: the create entry appears ONLY when the Gateway
// advertised orders.create in bootstrap.capabilities (fail-closed by default).
<template>
  <section class="panel">
    <h2>我的订单</h2>

    <ErrorState v-if="error" :error="error" @retry="load" />

    <p v-if="createNotice" class="notice" role="status">{{ createNotice }}</p>

    <!-- Create entry: rendered only when the Gateway advertises the write capability. -->
    <form v-if="canCreate" class="create-form" @submit.prevent="submitCreate">
      <label>套餐 ID
        <input v-model.number="newPlanId" type="number" min="1" required />
      </label>
      <label>周期
        <select v-model="newPeriod">
          <option value="month_price">月付</option>
          <option value="year_price">年付</option>
        </select>
      </label>
      <button class="primary" type="submit" :disabled="loading || creating">
        {{ creating ? '提交中…' : '创建订单' }}
      </button>
    </form>
    <p v-else class="muted">当前部署未开通在线下单（能力未通告）。</p>

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
import type { GatewayApiError, Order } from '@ANRCM0/theme-sdk'
import { api, draftKeyFor } from '../api'
import { formatMoney } from '../lib/format'
import ErrorState from '../components/ErrorState.vue'

const STATUS_LABELS: Record<number, string> = { 0: '待支付', 1: '已支付', 2: '已取消', 3: '其他' }

const orders = ref<Order[]>([])
const caps = ref<string[]>([])
const loading = ref(true)
const error = ref<Error | null>(null)
const createNotice = ref('')
const creating = ref(false)
const newPlanId = ref(1)
const newPeriod = ref('month_price')

// Fail-closed: the write entry exists only when bootstrap advertised it.
const canCreate = ref(false)

async function load() {
  loading.value = true
  error.value = null
  try {
    // Re-read bootstrap on every load: capabilities can change between
    // deploys; the client must never cache "write is allowed" assumptions.
    const bootstrap = await api.bootstrap()
    caps.value = bootstrap.capabilities
    canCreate.value = caps.value.includes('orders.create')
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
 * Create an order (docs/developer-guide.md §5). The SDK never auto-retries a
 * write. The idempotency key is generated ONCE per logical draft and
 * persisted in sessionStorage (plan_id + period scoped), so a page refresh
 * reuses the same key instead of silently creating a duplicate.
 * IDEMPOTENCY_IN_PROGRESS → back off, resend the SAME key.
 * IDEMPOTENCY_CONFLICT   → the draft key was reused with a different body:
 *                          re-choose the plan (a correct server-side refusal).
 */
async function submitCreate() {
  if (!canCreate.value) return
  creating.value = true
  createNotice.value = ''
  try {
    const draftKey = draftKeyFor(newPlanId.value, newPeriod.value)
    const result = await api.orders.create(
      { plan_id: newPlanId.value, period: newPeriod.value },
      { idempotencyKey: draftKey },
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
  } finally {
    creating.value = false
  }
}

onMounted(load)
</script>
