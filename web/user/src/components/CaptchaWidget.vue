<script setup lang="ts">
import { onMounted, watch } from 'vue'
import { captchaSiteKey, createCaptchaController, type CaptchaPayload } from '@txboard/shared'
import type { GuestConfig } from '../api/comm'

const props=defineProps<{config:GuestConfig|null}>()
const containerId='captcha-'+Math.random().toString(36).slice(2)

// Every provider detail (script loading, widget mounting, token retrieval,
// reset) lives in @txboard/shared; this component only maps it onto Vue's
// lifecycle and expose API. The admin SPA has an equivalent thin React binding,
// so the two frontends can no longer drift.
const controller=createCaptchaController({
  getConfig:()=>props.config,
  containerId,
})

async function getPayload():Promise<CaptchaPayload>{
  // The user flows label their reCAPTCHA v3 action "submit". An unavailable
  // token raises CaptchaUnavailableError, which the page shows as an error
  // instead of posting a request the API would reject.
  return controller.getPayload({ action:'submit' })
}

function reset(){
  controller.reset()
}

onMounted(()=>void controller.mount())
watch(()=>props.config,()=>void controller.mount())
defineExpose({getPayload,reset})
</script>

<template>
  <div v-if="captchaSiteKey(config)" class="captcha-wrap"><div :id="containerId"/></div>
</template>
