<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { changePassword, getActiveSessions, getQuickLoginUrl, removeActiveSession, resetSecurity, type ActiveSession } from '../api/profile'
import { errorMessage } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { useI18n } from '../i18n'

const auth=useAuthStore()
const {t,locale}=useI18n()
const sessions=ref<ActiveSession[]>([])
const error=ref('')
const success=ref('')
const acting=ref(false)
const quickUrl=ref('')
const password=reactive({old:'',next:'',confirm:''})

async function load(){
  try{
    if(!auth.user)await auth.loadUser()
    sessions.value=await getActiveSessions()
  }catch(e){error.value=errorMessage(e)}
}
onMounted(()=>void load())

async function updatePassword(){
  error.value=''
  success.value=''
  if(!password.old||!password.next)return
  if(password.next!==password.confirm){error.value=t('profile.passwordMismatch');return}
  acting.value=true
  try{
    await changePassword({old_password:password.old,new_password:password.next})
    password.old='';password.next='';password.confirm=''
    success.value=t('profile.passwordUpdated')
  }catch(e){error.value=errorMessage(e)}finally{acting.value=false}
}
async function revoke(session:ActiveSession){
  if(!confirm(t('profile.confirmRemove')))return
  acting.value=true
  try{await removeActiveSession(String(session.id));sessions.value=await getActiveSessions()}catch(e){error.value=errorMessage(e)}finally{acting.value=false}
}
async function reset(){
  if(!confirm(t('profile.confirmReset')))return
  acting.value=true
  try{
    const result=await resetSecurity()
    success.value=t('profile.securityReset')
    quickUrl.value=result||''
  }catch(e){error.value=errorMessage(e)}finally{acting.value=false}
}
async function quickLogin(){
  acting.value=true
  try{
    quickUrl.value=await getQuickLoginUrl()
    await navigator.clipboard.writeText(quickUrl.value)
    success.value=t('profile.quickCopied')
  }catch(e){error.value=errorMessage(e)}finally{acting.value=false}
}
function date(value:string|null){return value?new Date(value).toLocaleString(locale.value):'-'}
</script>

<template>
  <div class="profile-page">
    <div v-if="error" class="page-alert">{{ error }}</div>
    <div v-if="success" class="page-alert success">{{ success }}</div>

    <section class="xboard-card profile-wallet-card">
      <header class="xboard-card-header">{{ t('profile.balance') }}</header>
      <div class="xboard-card-body">
        <div class="profile-wallet-value">
          <strong>¥ {{ (Number(auth.user?.balance||0)/100).toFixed(2) }}</strong>
          <span>CNY</span>
        </div>
        <p>{{ t('dashboard.balanceHint') }}</p>
      </div>
    </section>

    <section class="xboard-card profile-section-card">
      <header class="xboard-card-header">{{ t('profile.account') }}</header>
      <div class="xboard-card-body">
        <div class="account-row"><span>{{ t('profile.email') }}</span><strong>{{ auth.user?.email||'-' }}</strong></div>
        <div class="account-row"><span>UUID</span><strong class="mono">{{ auth.user?.uuid||'-' }}</strong></div>
        <div class="account-row"><span>{{ t('profile.planId') }}</span><strong>{{ auth.user?.plan_id??'-' }}</strong></div>
        <div class="account-row"><span>{{ t('profile.commissionBalance') }}</span><strong>¥ {{ (Number(auth.user?.commission_balance||0)/100).toFixed(2) }}</strong></div>
      </div>
    </section>

    <section class="xboard-card profile-section-card">
      <header class="xboard-card-header">{{ t('profile.changePassword') }}</header>
      <div class="xboard-card-body profile-form-column">
        <label>{{ t('profile.oldPassword') }}<input v-model="password.old" type="password" class="form-control"/></label>
        <label>{{ t('profile.newPassword') }}<input v-model="password.next" type="password" class="form-control"/></label>
        <label>{{ t('profile.confirmNewPassword') }}<input v-model="password.confirm" type="password" class="form-control"/></label>
        <button class="primary-btn profile-save-btn" :disabled="acting||!password.old||!password.next" @click="updatePassword">{{ t('profile.savePassword') }}</button>
      </div>
    </section>

    <section class="xboard-card profile-section-card">
      <header class="xboard-card-header">{{ t('profile.sessions') }}</header>
      <div class="responsive-table profile-session-table">
        <table>
          <thead><tr><th>Session</th><th>{{ t('profile.created',{time:''}).replace(/s*$/,'') }}</th><th>{{ t('profile.lastUsed',{time:''}).replace(/s*$/,'') }}</th><th>{{ t('common.actions') }}</th></tr></thead>
          <tbody>
            <tr v-for="session in sessions" :key="session.id">
              <td>{{ session.name||('Session #'+session.id) }}</td>
              <td>{{ date(session.created_at) }}</td>
              <td>{{ date(session.last_used_at) }}</td>
              <td><button class="order-link-btn order-link-btn--danger" :disabled="acting" @click="revoke(session)">{{ t('profile.remove') }}</button></td>
            </tr>
            <tr v-if="!sessions.length"><td colspan="4" class="empty-cell">{{ t('profile.noSessions') }}</td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <section class="xboard-card profile-section-card">
      <header class="xboard-card-header">{{ t('profile.quickLogin') }}</header>
      <div class="xboard-card-body">
        <button class="primary-btn small-btn" :disabled="acting" @click="quickLogin">{{ t('profile.quickLogin') }}</button>
        <div v-if="quickUrl" class="quick-url"><code>{{ quickUrl }}</code></div>
      </div>
    </section>

    <section class="xboard-card profile-section-card">
      <header class="xboard-card-header">{{ t('profile.security') }}</header>
      <div class="xboard-card-body">
        <div class="profile-warning">{{ t('profile.securityDesc') }}</div>
        <button class="secondary-btn danger small-btn profile-reset-btn" :disabled="acting" @click="reset">{{ t('profile.resetSecurity') }}</button>
      </div>
    </section>
  </div>
</template>
