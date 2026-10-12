// Vue login page: CAPTCHA-aware, fail-closed when CAPTCHA config is broken.
<template>
  <form class="login" @submit.prevent="submit" novalidate>
    <h1>登录</h1>

    <label>邮箱地址
      <input v-model="email" type="email" autocomplete="off" required />
    </label>
    <label>密码
      <input v-model="password" type="password" autocomplete="off" required />
    </label>

    <CaptchaField
      v-if="captcha"
      :captcha="captcha"
      v-model="captchaTokens"
    />

    <p v-if="captcha && captcha.enabled && !captcha.siteKey" class="error" role="alert">
      CAPTCHA 已启用但未正确配置，无法登录。请联系管理员。
    </p>

    <button class="primary" type="submit" :disabled="loginBlocked || loading">
      {{ loading ? '登录中…' : '登录' }}
    </button>

    <ErrorState v-if="error" :error="error" />
  </form>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import type { CaptchaConfig } from '@ANRCM0/theme-sdk'
import { api } from '../api'
import { useAuth } from '../composables/useAuth'
import CaptchaField from '../components/CaptchaField.vue'
import ErrorState from '../components/ErrorState.vue'

const route = useRoute()
const router = useRouter()
const auth = useAuth()
const email = ref('')
const password = ref('')
const captcha = ref<CaptchaConfig | null>(null)
const captchaTokens = ref<Record<string, string>>({})
const loading = ref(false)
const error = ref<Error | null>(null)

const loginBlocked = computedLoginBlock()

function computedLoginBlock(): boolean {
  // enabled=true but no siteKey/type → never submit without a verified token.
  return !!captcha.value?.enabled && (!captcha.value.type || !captcha.value.siteKey)
}

async function submit() {
  if (loginBlocked) return
  loading.value = true
  error.value = null
  try {
    await auth.login({
      email: email.value,
      password: password.value,
      ...(captchaTokens.value.turnstile_token ? { turnstile_token: captchaTokens.value.turnstile_token } : {}),
      ...(captchaTokens.value.recaptcha_v3_token ? { recaptcha_v3_token: captchaTokens.value.recaptcha_v3_token } : {}),
      ...(captchaTokens.value.recaptcha_data ? { recaptcha_data: captchaTokens.value.recaptcha_data } : {}),
    })
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/dashboard'
    router.replace(redirect)
  } catch (err) {
    error.value = err as Error
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  try {
    const bootstrap = await api.bootstrap()
    captcha.value = bootstrap.security.captcha
  } catch (err) {
    error.value = err as Error
  }
})
</script>
