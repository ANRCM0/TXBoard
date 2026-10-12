// Vue CAPTCHA field: renders the widget for the type announced by bootstrap.
// When enabled=true but type/siteKey is missing, render a disabled submit —
// never silently skip verification (contract: guest config constraints).
<template>
  <div v-if="captcha.enabled" class="captcha">
    <div v-if="captcha.type === 'turnstile' && captcha.siteKey" class="cf-turnstile" :data-sitekey="captcha.siteKey" />
    <div v-else-if="(captcha.type === 'recaptcha' || captcha.type === 'recaptcha-v3') && captcha.siteKey" class="g-recaptcha" :data-sitekey="captcha.siteKey" />
    <p v-else class="error" role="alert">CAPTCHA 配置不完整，无法验证。</p>
  </div>
</template>

<script setup lang="ts">
import type { CaptchaConfig } from '@txboard/theme-sdk'
import { onMounted } from 'vue'

const props = defineProps<{ captcha: CaptchaConfig }>()
const emit = defineEmits<{ 'update:modelValue': [tokens: Record<string, string>] }>()

// Provider scripts are loaded here from the provider's official CDN; the
// gateway only publishes which provider + site key are configured. The theme
// must read the token from the provider callback and pass it to login.
onMounted(() => {
  if (!props.captcha.enabled || !props.captcha.siteKey) return
  if (props.captcha.type === 'turnstile') {
    // window.turnstile.render / implicit render; call window.turnstile.getResponse
    // on submit and emit { turnstile_token }.
  }
  if (props.captcha.type === 'recaptcha' || props.captcha.type === 'recaptcha-v3') {
    // grecaptcha.execute / render; emit { recaptcha_v3_token, recaptcha_data }.
  }
})
</script>
