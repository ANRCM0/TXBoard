<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import CaptchaWidget from '../components/CaptchaWidget.vue'
import AuthEmailInput from '../components/AuthEmailInput.vue'
import TelegramLoginWidget from '../components/TelegramLoginWidget.vue'
import {
  forgetPassword,
  loginWithMailLink,
  sendEmailVerify,
  token2Login,
} from '../api/auth'
import { fetchGuestConfig, type GuestConfig } from '../api/comm'
import { errorMessage } from '../api/client'
import { recordPageView, resolveInviteCode } from '../api/pv'
import { useAuthStore } from '../stores/auth'
import { useI18n } from '../i18n'

type Tab='login'|'register'|'forget'
const route=useRoute()
const router=useRouter()
const auth=useAuthStore()
const {t,locale,setLocale}=useI18n()
const tab=ref<Tab>('login')
const guest=ref<GuestConfig|null>(null)
const message=ref('')
const error=ref('')
const sendingCode=ref(false)
const submitting=ref(false)
const mailLinkMode=ref(false)
const agreed=ref(false)
const captchaLogin=ref<InstanceType<typeof CaptchaWidget>|null>(null)
const captchaAuth=ref<InstanceType<typeof CaptchaWidget>|null>(null)
const form=reactive({
  email:'',
  password:'',
  confirmPassword:'',
  emailCode:'',
  inviteCode:'',
})

const registrationDisabled=computed(()=>Number(guest.value?.stop_register)===1||Number(guest.value?.register_enable)===0)
const needsEmailCode=computed(()=>Number(guest.value?.is_email_verify)===1)
const showCaptcha=computed(()=>Number(guest.value?.is_captcha)===1)
const showMailLink=computed(()=>Number(guest.value?.login_with_mail_link_enable)===1)
const telegramLoginEndpoint=computed(()=>guest.value?.telegram_login_endpoint?.trim()||'')
const inviteRequired=computed(()=>Number(guest.value?.is_invite_force)===1)
const termsRequired=computed(()=>Boolean(guest.value?.tos_url))
const telegramAuthUrl=computed(()=>{
  const value=guest.value?.telegram_login_domain?.trim()
  if(!value)return undefined
  if(/^https?:\/\//i.test(value))return value
  const base=guest.value?.app_url?.replace(/\/$/,'')
  return base?base+'/'+value.replace(/^\//,''):undefined
})
const showTelegram=computed(()=>Number(guest.value?.telegram_login_enable)===1&&Boolean(guest.value?.telegram_bot_username)&&Boolean(telegramAuthUrl.value||telegramLoginEndpoint.value))

watch(()=>route.query.tab,value=>{
  tab.value=value==='register'||value==='forget'?value:'login'
  mailLinkMode.value=false
  error.value=''
  message.value=''
},{immediate:true})

watch(()=>route.query.code,value=>{
  if(typeof value==='string'&&value)form.inviteCode=value
},{immediate:true})

onMounted(async()=>{
  try{guest.value=await fetchGuestConfig()}catch{guest.value=null}
  const invite=typeof route.query.code==='string'?route.query.code:resolveInviteCode()
  if(invite)form.inviteCode=invite
  recordPageView()
  await tryTokenLogin()
})

async function tryTokenLogin(){
  const verify=route.query.verify
  if(typeof verify!=='string'||!verify||tab.value!=='login')return
  submitting.value=true
  try{
    await token2Login(verify)
    await auth.loadUser()
    await router.replace(resolveRedirect())
  }catch(e){
    error.value=errorMessage(e)
  }finally{
    submitting.value=false
  }
}

function resolveRedirect(){
  return typeof route.query.redirect==='string'&&route.query.redirect?route.query.redirect:'/dashboard'
}

function changeLocale(event:Event){
  setLocale((event.target as HTMLSelectElement).value)
}

function switchTab(next:Tab){
  error.value=''
  message.value=''
  mailLinkMode.value=false
  tab.value=next
  void router.replace({path:'/login',query:next==='login'?{}:{tab:next}})
}

async function submit(){
  if(submitting.value)return
  error.value=''
  message.value=''
  submitting.value=true
  try{
    if(!form.email.trim())throw new Error(t('auth.emailRequired'))
    if(tab.value==='login'&&mailLinkMode.value){
      const captcha=await captchaLogin.value?.getPayload()
      await loginWithMailLink(form.email.trim(),captcha)
      message.value=t('auth.mailLinkSent')
      captchaLogin.value?.reset()
      return
    }

    if(!form.password)throw new Error(t('auth.passwordRequired'))

    if(tab.value==='login'){
      const captcha=await captchaLogin.value?.getPayload()
      await auth.login({
        email:form.email.trim(),
        password:form.password,
        ...captcha,
      })
      await router.replace(resolveRedirect())
      return
    }

    if(form.password!==form.confirmPassword)throw new Error(t('auth.passwordMismatch'))
    if(form.password.length<8)throw new Error(t('auth.passwordTooShort'))

    if(tab.value==='register'){
      if(registrationDisabled.value)throw new Error(t('auth.registrationClosed'))
      if(inviteRequired.value&&!form.inviteCode.trim())throw new Error(t('auth.inviteRequired'))
      if(needsEmailCode.value&&!form.emailCode)throw new Error(t('auth.emailCodeRequired'))
      if(termsRequired.value&&!agreed.value)throw new Error(t('auth.termsRequired'))
      const captcha=await captchaAuth.value?.getPayload()
      await auth.register({
        email:form.email.trim(),
        password:form.password,
        ...(form.emailCode?{email_code:form.emailCode}:{}),
        ...(form.inviteCode.trim()?{invite_code:form.inviteCode.trim()}:{}),
        ...captcha,
      })
      await router.replace('/dashboard')
      return
    }

    if(!form.emailCode)throw new Error(t('auth.emailCodeRequired'))
    const captcha=await captchaAuth.value?.getPayload()
    await forgetPassword({
      email:form.email.trim(),
      password:form.password,
      email_code:form.emailCode,
      ...captcha,
    })
    switchTab('login')
    message.value=t('auth.passwordResetSuccess')
  }catch(e){
    error.value=errorMessage(e)
    captchaLogin.value?.reset()
    captchaAuth.value?.reset()
  }finally{
    submitting.value=false
  }
}

async function sendCode(){
  error.value=''
  message.value=''
  if(!form.email.trim()){error.value=t('auth.emailFirst');return}
  sendingCode.value=true
  try{
    const captcha=await captchaAuth.value?.getPayload()
    await sendEmailVerify(form.email.trim(),tab.value==='forget'?'forget':'register',captcha)
    message.value=t('auth.codeSent')
    captchaAuth.value?.reset()
  }catch(e){
    error.value=errorMessage(e)
    captchaAuth.value?.reset()
  }finally{
    sendingCode.value=false
  }
}
</script>

<template>
  <div class="auth-screen">
    <div class="auth-panel">
      <div class="auth-form-card">
        <img v-if="guest?.logo" :src="guest.logo" alt="" class="auth-brand-logo"/>

        <h1 class="auth-title">
          {{ tab==='forget' ? t('auth.resetPassword') : (guest?.app_name || 'TXBoard') }}
        </h1>
        <p class="auth-subtitle">{{ guest?.app_description || t('auth.defaultDescription') }}</p>

        <form class="auth-fields" @submit.prevent="submit">
          <label class="auth-field">
            <span>{{ t('auth.email') }}</span>
            <AuthEmailInput v-model="form.email" :suffixes="guest?.email_whitelist_suffix"/>
          </label>

          <CaptchaWidget
            v-if="showCaptcha && tab==='login'"
            ref="captchaLogin"
            :config="guest"
            class="auth-field"
          />

          <CaptchaWidget
            v-if="showCaptcha && tab!=='login'"
            ref="captchaAuth"
            :config="guest"
            class="auth-field"
          />

          <div v-if="(tab==='register'&&needsEmailCode)||tab==='forget'" class="auth-field verify-row">
            <input
              v-model="form.emailCode"
              autocomplete="one-time-code"
              :placeholder="t('auth.emailCode')"
            />
            <button type="button" class="secondary-btn verify-btn" :disabled="sendingCode" @click="sendCode">
              {{ sendingCode?t('auth.processing'):t('auth.sendCode') }}
            </button>
          </div>

          <label v-if="!(tab==='login'&&mailLinkMode)" class="auth-field">
            <span>{{ tab==='forget'?t('auth.newPassword'):t('auth.password') }}</span>
            <input
              v-model="form.password"
              type="password"
              :placeholder="tab==='forget'?t('auth.newPassword'):t('auth.password')"
              :autocomplete="tab==='login'?'current-password':'new-password'"
            />
          </label>

          <label v-if="tab!=='login'" class="auth-field">
            <span>{{ t('auth.confirmPassword') }}</span>
            <input
              v-model="form.confirmPassword"
              type="password"
              :placeholder="t('auth.confirmPassword')"
              autocomplete="new-password"
            />
          </label>

          <label v-if="tab==='register'&&Number(guest?.invite_enable)!==0" class="auth-field">
            <span>{{ t('auth.inviteCode') }}</span>
            <input
              v-model="form.inviteCode"
              :readonly="Boolean(route.query.code)"
              :placeholder="inviteRequired?t('auth.inviteCode')+' *':t('auth.optional')"
            />
          </label>

          <label v-if="tab==='register'&&termsRequired" class="auth-agree">
            <input v-model="agreed" type="checkbox"/>
            <span>{{ t('auth.termsPrefix') }} <a :href="guest?.tos_url" target="_blank" rel="noopener">{{ t('auth.terms') }}</a></span>
          </label>

          <div v-if="error" class="auth-alert error">{{ error }}</div>
          <div v-if="message" class="auth-alert success">{{ message }}</div>

          <button class="primary-btn auth-submit" :disabled="submitting||auth.loading">
            {{ submitting||auth.loading?t('auth.processing'):tab==='login'?(mailLinkMode?t('auth.sendLoginLink'):t('auth.login')):tab==='register'?t('auth.registerAndLogin'):t('auth.resetPassword') }}
          </button>

          <TelegramLoginWidget
            v-if="tab==='login'&&showTelegram&&guest?.telegram_bot_username&&!mailLinkMode"
            :bot-username="guest.telegram_bot_username"
            :auth-url="telegramAuthUrl"
            :endpoint="telegramLoginEndpoint"
            @error="error=$event"
          />
        </form>
      </div>

      <footer class="auth-footer-bar">
        <div class="auth-footer-links">
          <template v-if="tab==='login'">
            <button v-if="!registrationDisabled" type="button" class="auth-footer-link" @click="switchTab('register')">{{ t('auth.register') }}</button>
            <span v-if="!registrationDisabled" class="auth-footer-divider"/>
            <button type="button" class="auth-footer-link" @click="switchTab('forget')">{{ t('auth.forget') }}</button>
            <template v-if="showMailLink">
              <span class="auth-footer-divider"/>
              <button type="button" class="auth-footer-link" @click="mailLinkMode=!mailLinkMode">
                {{ mailLinkMode?t('auth.passwordLogin'):t('auth.mailLink') }}
              </button>
            </template>
          </template>
          <button v-else type="button" class="auth-footer-link" @click="switchTab('login')">{{ t('auth.passwordLogin') }}</button>
        </div>

        <select class="auth-lang-select" :value="locale" @change="changeLocale">
          <option value="zh-CN">中文</option>
          <option value="en-US">English</option>
        </select>
      </footer>
    </div>
  </div>
</template>

