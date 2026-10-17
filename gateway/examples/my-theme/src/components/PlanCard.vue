// Vue plan card: renders public plan DTO only; money stays in minor units.
<template>
  <article class="plan-card">
    <h3>{{ plan.name }}</h3>
    <p class="price">{{ plan.currency }} {{ formatOfferMoney(plan.price_minor) }} <span>/ {{ plan.offer_code }}</span></p>
    <p class="muted">{{ plan.content ?? '' }}</p>
    <button class="primary" @click="$emit('choose', plan.offer_id)">选择</button>
  </article>
</template>

<script setup lang="ts">
import type { NativeOffer } from '@txboard/theme-sdk'
import { formatOfferMoney } from '../lib/format'

defineProps<{ plan: NativeOffer }>()
defineEmits<{ choose: [id: string] }>()
</script>
