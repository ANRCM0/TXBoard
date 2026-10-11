// Vue plan card: renders public plan DTO only; money stays in minor units.
<template>
  <article class="plan-card">
    <h3>{{ plan.name }}</h3>
    <p class="price">¥{{ formatMoney(plan.month_price) }} <span>/ 月</span></p>
    <p class="muted">{{ plan.description ?? '' }}</p>
    <button class="primary" @click="$emit('choose', plan.id)">选择</button>
  </article>
</template>

<script setup lang="ts">
import type { Plan } from '@ANRCM0/theme-sdk'
import { formatMoney } from '../lib/format'

defineProps<{ plan: Plan }>()
defineEmits<{ choose: [id: number] }>()
</script>
