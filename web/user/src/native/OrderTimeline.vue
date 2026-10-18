<script setup lang="ts">
import { onBeforeUnmount, onMounted, shallowRef } from 'vue'
import { appendOrderEventPage, CoreUserApiError, type OrderEventHistory } from '@txboard/core-sdk'
import { user, userError } from './session'
const props = defineProps<{tradeNo: string}>()
const emit = defineEmits<{ advance: []; denied: [] }>()
const history = shallowRef<OrderEventHistory>(), error = shallowRef('')
const labels = { created:'订单已创建', payment_accepted:'支付已确认', fulfilled:'履约已完成', cancelled:'订单已取消' }
let active = true, loading = false, terminal = false, denied = false
let timer: ReturnType<typeof setTimeout> | undefined
const abort = new AbortController()
async function load() {
  if (!active || loading) return
  loading = true; if (timer) clearTimeout(timer)
  try {
    do {
      const page = await user.call('commerce.orders.events.list', {trade_no:props.tradeNo, after_sequence:history.value?.afterSequence ?? '0', limit:100}, undefined, abort.signal)
      if (!active) return
      const previous = history.value?.afterSequence ?? '0'
      history.value = appendOrderEventPage(history.value, page)
      if (previous !== history.value.afterSequence) emit('advance')
    } while (history.value.hasMore)
    error.value = ''; denied = false
    const last = history.value.events.at(-1)
    terminal = last?.type === 'fulfilled' || last?.type === 'cancelled'
  } catch (failure) {
    if (!active) return
    error.value = userError(failure)
    denied = failure instanceof CoreUserApiError && [401,403,404].includes(failure.status)
    if (denied) { history.value = undefined; emit('denied') }
  } finally { loading = false; if (active && !terminal && !denied) timer = setTimeout(() => void load(), 5000) }
}
const reconnect = () => { if (!terminal && !denied) void load() }
const visible = () => { if (document.visibilityState === 'visible') reconnect() }
onMounted(() => { void load(); window.addEventListener('online', reconnect); document.addEventListener('visibilitychange', visible) })
onBeforeUnmount(() => { active = false; abort.abort(); if(timer) clearTimeout(timer); window.removeEventListener('online', reconnect); document.removeEventListener('visibilitychange', visible) })
</script>
<template><section class="content-card" aria-label="订单状态时间线"><h2>状态时间线</h2>
  <p v-if="error" role="alert">{{error}} <button class="secondary-btn" @click="load">重新加载时间线</button></p>
  <p v-if="history && !history.historyComplete">历史记录不完整，仅展示已记录的事件。</p>
  <p v-if="history?.events.length === 0">暂无事件记录。</p>
  <ol v-if="history"><li v-for="event in history.events" :key="event.sequence">{{labels[event.type]}} <time :datetime="event.occurred_at">{{event.occurred_at}}</time></li></ol>
</section></template>
