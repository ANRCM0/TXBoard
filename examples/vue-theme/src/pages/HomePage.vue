<!-- Vue home page: bootstrap + plans + login entry (GW-504) -->
<template>
  <main class="page">
    <header class="hero">
      <img v-if="safeLogo" class="logo" :src="safeLogo" alt="" />
      <h1>{{ site?.name ?? '加载中…' }}</h1>
      <p>{{ site?.description }}</p>
      <RouterLink class="primary" :to="auth.isAuthed ? '/dashboard' : '/login'">
        {{ auth.isAuthed ? '进入用户中心' : '登录 / 注册' }}
      </RouterLink>
    </header>

    <ErrorState v-if="error" :error="error" @retry="load" />

    <section v-if="plans.length" class="plans" aria-label="套餐列表">
      <PlanCard v-for="plan in plans" :key="plan.id" :plan="plan" @choose="goToPlan(plan.id)" />
    </section>

    <p v-else-if="!error && !loading" class="muted">暂无公开套餐。</p>
    <p v-if="loading" class="muted" role="status">正在加载…</p>
  </main>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import type { Plan } from '@txboard/theme-sdk'
import { api, hasCapability } from '../api'
import { safeWebUrl } from '../lib/safe-url'
import { useAuth } from '../composables/useAuth'
import ErrorState from '../components/ErrorState.vue'
import PlanCard from '../components/PlanCard.vue'

const router = useRouter()
const auth = useAuth()
const site = ref<{ name: string; description: string; url: string; logo: string } | null>(null)
const plans = ref<Plan[]>([])
const caps = ref<string[]>([])
const loading = ref(true)
const error = ref<Error | null>(null)

const safeLogo = () => site.value?.logo ? safeWebUrl(site.value.logo) : null

async function load() {
  loading.value = true
  error.value = null
  try {
    const [bootstrap, planList] = await Promise.all([api.bootstrap(), api.plans.list()])
    site.value = bootstrap.site
    caps.value = bootstrap.capabilities
    plans.value = planList
  } catch (err) {
    error.value = err as Error
  } finally {
    loading.value = false
  }
}

function goToPlan(planId: number) {
  if (!auth.isAuthed) return router.push('/login')
  // Write capability is advertised by the Gateway; never assume it.
  if (!hasCapability(caps.value, 'orders.create')) return router.push('/plans')
  router.push(`/plans/${planId}`)
}

onMounted(load)
</script>
