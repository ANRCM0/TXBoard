// Vue error state: maps GatewayApiError codes to recoverable UI states.
// Shows the Gateway requestId so operators can trace the request in logs.
<template>
  <div class="error-state" role="alert">
    <strong>{{ title }}</strong>
    <p v-if="detail">{{ detail }}</p>
    <p v-if="requestId" class="request-id">请求 ID：{{ requestId }}</p>
    <button class="secondary" @click="$emit('retry')">重试</button>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import type { GatewayApiError } from '@ANRCM0/theme-sdk'

const props = defineProps<{ error: Error | GatewayApiError | null }>()
defineEmits<{ retry: [] }>()

const code = computed(() => (props.error as GatewayApiError)?.code)
const requestId = computed(() => (props.error as GatewayApiError)?.requestId)

const title = computed(() => {
  switch (code.value) {
    case 'UNAUTHORIZED': return '登录已过期，请重新登录。'
    case 'RATE_LIMITED': return '请求过于频繁，请稍后再试。'
    case 'UPSTREAM_UNAVAILABLE':
    case 'GATEWAY_ERROR': return '服务暂时不可用，请稍后重试。'
    case 'NETWORK_ERROR': return '网络连接失败，请检查网络。'
    case 'VERSION_MISMATCH': return '客户端与网关版本不兼容，请联系管理员。'
    case 'ORDER_REJECTED': return '订单被拒绝。'
    default: return '发生错误，请重试。'
  }
})

const detail = computed(() => {
  // Unknown codes: the caller should confirm remote state before proceeding.
  const needs = (props.error as GatewayApiError)?.needsStateConfirmation
  return needs ? props.error?.message ?? undefined : undefined
})
</script>
