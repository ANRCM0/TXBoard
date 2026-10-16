// Vue user pages: profile, subscription, plans, orders — all via the SDK.
// Every protected read goes through the Gateway user-read routes only.
<template>
  <section class="panel">
    <h2>账号概览</h2>

    <ErrorState v-if="error" :error="error" @retry="load" />
    <div v-else-if="loading" role="status">加载中…</div>
    <template v-else>
      <dl class="stats">
        <div><dt>邮箱</dt><dd>{{ profile?.email }}</dd></div>
        <div><dt>当前订阅</dt><dd>{{ subscription?.authorized ? '#' + subscription.subscription_id : '无订阅' }}</dd></div>
        <div><dt>到期时间</dt><dd>{{ subscription?.authorized ? formatDate(subscription.expires_at) : '—' }}</dd></div>
        <div><dt>剩余流量</dt><dd>{{ formatBytes(subscription?.authorized ? subscription.remaining_bytes : null) }}</dd></div>
      </dl>
    </template>
  </section>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import type { SubscriptionSummary, UserProfile } from '@txboard/theme-sdk'
import { api } from '../api'
import { formatBytes, formatDate } from '../lib/format'
import ErrorState from '../components/ErrorState.vue'

const profile = ref<UserProfile | null>(null)
const subscription = ref<SubscriptionSummary | null>(null)
const loading = ref(true)
const error = ref<Error | null>(null)

async function load() {
  loading.value = true
  error.value = null
  try {
    // Dashboard stats are also available: api.dashboard.stats().
    [profile.value, subscription.value] = await Promise.all([
      api.user.profile(),
      api.user.subscription(),
    ])
  } catch (err) {
    error.value = err as Error
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>
