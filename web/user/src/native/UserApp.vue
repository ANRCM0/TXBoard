<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import QRCode from 'qrcode'
import { CoreUserApiError, type CoreOutput } from '@txboard/core-sdk'
import { useRoute, useRouter } from 'vue-router'
import { actions, money, orderStatus, paymentURL, user, userError } from './session'
import OrderTimeline from './OrderTimeline.vue'
import CaptchaWidget from '../components/CaptchaWidget.vue'
import type { CaptchaPayload } from '@txboard/shared'
const router = useRouter(), route = useRoute()
const email = ref(''), password = ref(''), error = ref(''), busy = ref(false), bootstrapped = ref(false), captchaBlocked = ref(false)
const captchaConfig = shallowRef<{is_captcha: number; captcha_type?: string; turnstile_site_key?: string; recaptcha_site_key?: string; recaptcha_v3_site_key?: string} | null>(null)
const captchaWidget = ref<{getPayload(): Promise<CaptchaPayload>; reset(): void}>()
const account = shallowRef<CoreOutput<'identity.account.get'>>()
const offers = shallowRef<CoreOutput<'catalog.offers.list'>>([])
const orders = shallowRef<CoreOutput<'commerce.orders.list'>>([])
const order = shallowRef<CoreOutput<'commerce.orders.get'>>()
const methods = shallowRef<CoreOutput<'billing.payment-methods.list'>>([])
const wallet = ref(false)
const method = ref(''), attemptId = ref(''), attempt = shallowRef<CoreOutput<'billing.payment-attempts.get'>>()
const checkout = shallowRef<CoreOutput<'commerce.orders.checkout'>>()
const paymentHistory = shallowRef<CoreOutput<'billing.order-payment-attempts.list'>>([])
const paymentHistoryReady = ref(false), paymentHistoryBusy = ref(false), paymentHistoryMore = ref(false)
const unresolvedPayment = ref(false), paymentHistoryError = ref('')
let paymentBefore: string | null = null, paymentRead = 0
const page = ref(1), lastPage = ref(1)
let generation = 0, scope = new AbortController(), loginKey: string | undefined
const tradeNo = computed(() => typeof route.params.tradeNo === 'string' ? route.params.tradeNo : '')
const authenticated = user.authenticated, pending = actions.pending
const payLink = computed(() => order.value?.status === 0 && checkout.value?.type === 1 ? paymentURL(checkout.value.data) : undefined)
const qr = ref('')
watch(payLink, async value => { qr.value = ''; if(value) { const result = await QRCode.toDataURL(value, {width:224}); if(payLink.value === value) qr.value = result } })
const frozenTerms = computed(() => !!order.value?.currency && order.value.purchase_terms_version === 'tx-core.purchase-terms/v2')
const selectedMethod = computed(() => methods.value.find(row => row.id === method.value && row.currency === order.value?.currency))
const freeOrder = computed(() => order.value?.amount_minor === '0')
const canCheckout = computed(() => frozenTerms.value && paymentHistoryReady.value && !paymentHistoryBusy.value && !unresolvedPayment.value && (freeOrder.value || wallet.value || !!selectedMethod.value))
function clearFacts() { generation++; paymentRead++; scope.abort(); scope = new AbortController(); account.value = undefined; offers.value = []; orders.value = []; order.value = undefined; methods.value = []; checkout.value = undefined; attempt.value = undefined; attemptId.value = ''; method.value = ''; wallet.value = false; paymentHistory.value = []; paymentHistoryReady.value = false; paymentHistoryBusy.value = false; paymentHistoryMore.value = false; unresolvedPayment.value = false; paymentHistoryError.value = ''; paymentBefore = null }
const unsubscribe = user.onClear(clearFacts)
async function perform(run: () => Promise<void>) { if (busy.value) return; busy.value = true; error.value = ''; try { await run() } catch(failure) { error.value = userError(failure) } finally { busy.value = false } }
async function load() {
  clearFacts(); const current = generation, signal = scope.signal
  if (!authenticated.value) return
  await perform(async () => {
    const profile = await user.call('identity.account.get', {}, undefined, signal)
    if (current !== generation) return
    account.value = profile.data
    if (route.path === '/plan') {
      const result = await user.call('catalog.offers.list', {}, undefined, signal)
      if(current === generation) offers.value = result.data
    } else if (tradeNo.value) {
      await refreshOrder()
      const result = await user.call('billing.payment-methods.list', {}, undefined, signal)
      if(current === generation) methods.value = result.data
    } else if (route.path === '/order') {
      const result = await user.call('commerce.orders.list', {page:page.value, per_page:20}, undefined, signal)
      if(current === generation) { orders.value = result.data; lastPage.value = result.meta.last_page }
    }
  })
}
async function refreshOrder() {
  const current = generation, target = tradeNo.value
  if (!target) return
  try { const result = await user.call('commerce.orders.get', {trade_no:target}, undefined, scope.signal); if(current === generation && tradeNo.value === target) { order.value = result.data; await readOrderAttempts() } }
  catch(failure) { if(current === generation) { order.value = undefined; error.value = userError(failure) } }
}
async function readOrderAttempts(append = false) {
  const current = generation, target = tradeNo.value
  if (!target || append && (!paymentBefore || !paymentHistoryMore.value)) return
  const read = ++paymentRead
  paymentHistoryBusy.value = true; paymentHistoryError.value = ''; paymentHistoryReady.value = false
  try {
    const result = await user.call('billing.order-payment-attempts.list', {trade_no:target,limit:20,
      ...(append && paymentBefore ? {before_id:paymentBefore} : {})}, undefined, scope.signal)
    if (current !== generation || target !== tradeNo.value || read !== paymentRead) return
    paymentHistory.value = append ? [...paymentHistory.value, ...result.data] : result.data
    paymentBefore = result.meta.next_before_id; paymentHistoryMore.value = result.meta.has_more
    unresolvedPayment.value = result.meta.has_unresolved; paymentHistoryReady.value = true
    const selected = paymentHistory.value.find(row => row.attempt_id === attemptId.value) ?? paymentHistory.value[0]
    attemptId.value = selected?.attempt_id ?? ''; attempt.value = selected
  } catch (failure) {
    if (current !== generation || read !== paymentRead) return
    paymentHistoryError.value = userError(failure); checkout.value = undefined; attempt.value = undefined
    if (failure instanceof CoreUserApiError && [401,403,404].includes(failure.status)) {
      paymentHistory.value = []; attemptId.value = ''; paymentHistoryMore.value = false
    }
  } finally { if (current === generation && read === paymentRead) paymentHistoryBusy.value = false }
}
function selectAttempt() { attempt.value = paymentHistory.value.find(row => row.attempt_id === attemptId.value) }
async function login() { await perform(async () => {
  if(!bootstrapped.value || captchaBlocked.value) return
  const payload = await captchaWidget.value?.getPayload() ?? {}
  loginKey ??= crypto.randomUUID()
  try { await user.login({email:email.value,password:password.value,...payload},loginKey) }
  finally { captchaWidget.value?.reset() }
  password.value = ''; loginKey = undefined; await router.replace('/dashboard')
}) }
async function createOrder(offerId: string) { await perform(async () => { const result = await actions.run('commerce.orders.create',{offer_id:offerId}); await router.push('/order/'+result.trade_no) }) }
async function startPayment() { await perform(async () => {
  if(!order.value || !canCheckout.value) return
  const input = {trade_no:order.value.trade_no, ...(freeOrder.value ? {} : wallet.value ? {wallet:true} : {method:selectedMethod.value!.id})}
  checkout.value = await actions.run('commerce.orders.checkout',input)
  if(checkout.value.type === 1) { attemptId.value = checkout.value.attempt.attempt_id; await readAttempt() }
  await refreshOrder()
}) }
async function readAttempt() {
  const current = generation, target = tradeNo.value, selected = attemptId.value
  const result = await user.call('billing.payment-attempts.get',{attempt_id:selected},undefined,scope.signal)
  if (current === generation && target === tradeNo.value && selected === attemptId.value) attempt.value = result.data
}
async function queryAttempt() { await perform(async () => { attempt.value = await actions.run('billing.payment-attempts.query',{attempt_id:attemptId.value}); await refreshOrder() }) }
async function cancelOrder() { await perform(async () => { await actions.run('commerce.orders.cancel',{trade_no:tradeNo.value}); await refreshOrder() }) }
async function recover() { const action = pending.value; if(!action) return; await perform(async () => {
  if(action.operation === 'commerce.orders.create') { const result = await actions.run(action.operation,action.input); await router.push('/order/'+result.trade_no) }
  else if(action.operation === 'commerce.orders.checkout') { checkout.value = await actions.run(action.operation,action.input); if(checkout.value.type === 1) attemptId.value = checkout.value.attempt.attempt_id; await refreshOrder() }
  else if(action.operation === 'billing.payment-attempts.query') { await actions.run(action.operation,action.input); await refreshOrder() }
  else { await actions.run(action.operation,action.input); await refreshOrder() }
}) }
async function logout() { await perform(async () => { try { await user.logout() } finally { await router.replace('/login') } }) }
async function bootstrap() {
  const abort = new AbortController(), timer = setTimeout(() => abort.abort(),8000)
  try {
    const response = await fetch('/gateway/v1/bootstrap',{credentials:'omit',redirect:'error',cache:'no-store',signal:abort.signal,headers:{Accept:'application/json'}})
    if(!response.ok || !response.body || !response.headers.get('content-type')?.includes('application/json')) throw new Error()
    const reader = response.body.getReader(); let body = '', size = 0; const decoder = new TextDecoder('utf-8',{fatal:true})
    try { for(;;) { const part = await reader.read(); if(part.done) break; size += part.value.byteLength; if(size > 262144) throw new Error(); body += decoder.decode(part.value,{stream:true}) } body += decoder.decode() } finally { void reader.cancel().catch(()=>{}) }
    const value = JSON.parse(body)
    if(value?.ok !== true || typeof value.data?.security?.captcha?.enabled !== 'boolean') throw new Error()
    const configured = value.data.security.captcha
    captchaBlocked.value = false
    if (configured.enabled) {
      if (!['turnstile','recaptcha','recaptcha-v3'].includes(configured.type) || typeof configured.siteKey !== 'string' || !configured.siteKey.trim() || configured.siteKey.length > 2048) throw new Error()
      captchaConfig.value = {is_captcha:1,captcha_type:configured.type,
        ...(configured.type==='turnstile' ? {turnstile_site_key:configured.siteKey} : configured.type==='recaptcha-v3' ? {recaptcha_v3_site_key:configured.siteKey} : {recaptcha_site_key:configured.siteKey})}
    } else captchaConfig.value = {is_captcha:0}
    bootstrapped.value = true
  } catch { captchaBlocked.value = true; error.value = '无法读取登录安全配置，请重新加载。' } finally { clearTimeout(timer) }
}
onMounted(() => { void bootstrap(); void load() })
onBeforeUnmount(() => { unsubscribe(); generation++; scope.abort() })
</script>
<template>
<main v-if="!authenticated" class="native-user-login content-card">
  <h1>TXBoard 用户登录</h1><p>登录凭据仅保留在当前页面内存中，刷新后需要重新登录。</p>
  <p v-if="error" role="alert">{{error}}</p>
  <form @submit.prevent="login"><label>邮箱<input v-model="email" type="email" autocomplete="username" required @input="loginKey=undefined"></label>
  <label>密码<input v-model="password" type="password" autocomplete="current-password" minlength="8" required @input="loginKey=undefined"></label>
  <CaptchaWidget ref="captchaWidget" :config="captchaConfig" />
  <button class="primary-btn" :disabled="busy || !bootstrapped || captchaBlocked">{{busy?'登录中…':'登录'}}</button></form>
</main>
<div v-else class="user-shell">
  <aside class="user-sidebar"><div class="user-brand"><div class="user-brand-mark">TX</div><strong>TXBoard</strong></div>
    <nav class="user-nav"><RouterLink class="user-nav-item" to="/dashboard">账户概览</RouterLink><RouterLink class="user-nav-item" to="/plan">购买套餐</RouterLink><RouterLink class="user-nav-item" to="/order">我的订单</RouterLink></nav></aside>
  <div class="user-main"><header class="user-header"><strong>用户中心</strong><nav class="native-user-mobile-nav" aria-label="用户导航"><RouterLink to="/dashboard">账户概览</RouterLink><RouterLink to="/plan">购买套餐</RouterLink><RouterLink to="/order">我的订单</RouterLink></nav><button class="secondary-btn" :disabled="busy" @click="logout">退出登录</button></header>
  <div class="user-content page-stack"><p v-if="error" role="alert">{{error}}</p>
    <section v-if="pending" class="content-card" role="status"><h2>操作等待确认</h2><p>保留原操作 {{pending.key}}。确认结果前不可发起其他操作。</p><button class="primary-btn" :disabled="busy" @click="recover">使用原操作确认结果</button></section>
    <p v-if="busy" role="status">正在读取…</p><button class="secondary-btn" :disabled="busy" @click="load">重新读取当前页面</button>
    <section v-if="route.path==='/dashboard' && account" class="content-card"><h1>账户概览</h1><p>{{account.email}}</p><p>账户 ID：{{account.id}}</p><p>{{account.subscription.authorized?'订阅权益已生效':'当前没有可用订阅'}}</p>
      <template v-if="account.subscription.authorized"><p>剩余流量：{{account.subscription.remaining_bytes}} 字节</p><p>订阅版本：{{account.subscription.subscription_version}}</p></template></section>
    <section v-if="route.path==='/plan'" class="page-stack"><h1>购买套餐</h1><article v-for="offer in offers" :key="offer.offer_id" class="plan-card content-card"><h2>{{offer.name}}</h2><p>{{offer.content}}</p><p>{{offer.offer_code}} · {{money(offer.price_minor,offer.currency)}}</p><p>流量：{{offer.quota_bytes}} 字节</p><button class="primary-btn" :disabled="busy || !!pending || !offer.sell" @click="createOrder(offer.offer_id)">创建订单</button></article></section>
    <section v-if="route.path==='/order'" class="content-card"><h1>我的订单</h1><p v-if="!orders.length">暂无订单。</p><ul><li v-for="row in orders" :key="row.trade_no"><RouterLink :to="'/order/'+row.trade_no">{{row.trade_no}} · {{row.plan?.name ?? '未记录'}} · {{orderStatus(row.status)}}</RouterLink></li></ul><button class="secondary-btn" :disabled="page<=1 || busy" @click="page--;load()">上一页</button> {{page}} / {{lastPage}} <button class="secondary-btn" :disabled="page>=lastPage || busy" @click="page++;load()">下一页</button></section>
    <template v-if="tradeNo && order"><section class="content-card"><h1>订单详情</h1><p>订单号：{{order.trade_no}}</p><p>套餐：{{order.plan?.name ?? '未记录'}} · {{order.period}}</p><p>金额：{{order.currency ? money(order.amount_minor,order.currency) : order.amount_minor + '（货币未记录）'}}</p><p>状态：{{orderStatus(order.status)}}</p><p>创建时间：{{order.created_at}}</p><p>支付时间：{{order.paid_at ?? '未支付'}}</p>
      <p v-if="!frozenTerms" role="alert">订单缺少冻结货币或购买条款，无法发起支付。</p><template v-if="order.status===0 && frozenTerms"><label v-if="!freeOrder"><input v-model="wallet" type="checkbox" :disabled="busy || !!pending">使用钱包全额支付</label><label v-if="!freeOrder && !wallet">支付方式<select v-model="method" :disabled="busy || !!pending"><option value="">请选择</option><option v-for="payment in methods.filter(row => row.currency === order?.currency)" :key="payment.id" :value="payment.id">{{payment.name}} · {{payment.currency}}</option></select></label><button class="primary-btn" :disabled="busy || !!pending || !canCheckout" @click="startPayment">确认并发起支付</button><button class="secondary-btn" :disabled="busy || !!pending" @click="cancelOrder">取消订单</button></template>
      <p v-if="payLink"><a class="primary-btn" :href="payLink" target="_blank" rel="noopener noreferrer">打开支付页面</a></p>
      <img v-if="qr" :src="qr" alt="支付链接二维码" width="224" height="224"><p v-if="checkout?.type===-1">支付请求已受理，以订单状态和时间线确认为准。</p>
      <section aria-label="订单支付记录"><h2>支付记录</h2>
      <p v-if="paymentHistoryError" role="alert">{{paymentHistoryError}}</p><p v-if="paymentHistoryBusy" role="status">正在读取已有支付尝试…</p>
      <p v-if="unresolvedPayment">已有支付请求等待确认，请先查询渠道结果。当前不会再次发起支付。</p>
      <p v-if="paymentHistoryReady &amp;&amp; !paymentHistory.length">此订单暂无渠道支付尝试。</p>
      <button class="secondary-btn" :disabled="busy || paymentHistoryBusy" @click="readOrderAttempts()">重新读取支付记录</button>
      <label v-if="paymentHistory.length">选择支付尝试<select v-model="attemptId" :disabled="busy || !!pending || paymentHistoryBusy" @change="selectAttempt"><option v-for="row in paymentHistory" :key="row.attempt_id" :value="row.attempt_id">{{row.created_at}} · #{{row.attempt_id}} · {{row.status}} · {{money(row.amount_minor,row.currency)}}</option></select></label>
      <button v-if="paymentHistoryMore" class="secondary-btn" :disabled="busy || paymentHistoryBusy" @click="readOrderAttempts(true)">读取更早记录</button>
      <button class="secondary-btn" :disabled="busy || paymentHistoryBusy || !attemptId" @click="perform(readAttempt)">读取选中状态</button><button class="secondary-btn" :disabled="busy || paymentHistoryBusy || !!pending || !attemptId" @click="queryAttempt">向渠道确认支付结果</button><p v-if="attempt">支付尝试 {{attempt.attempt_id}}：{{attempt.status}} · {{money(attempt.amount_minor,attempt.currency)}}</p></section>
    </section><OrderTimeline :key="tradeNo" :trade-no="tradeNo" @advance="refreshOrder" @denied="order=undefined;checkout=undefined;attempt=undefined" /></template>
    <section v-if="!['/dashboard','/plan','/order'].includes(route.path) && !tradeNo" class="content-card"><h1>功能正在迁移</h1><p>请使用账户、套餐与订单入口。</p></section>
  </div></div>
</div>
</template>
<style scoped>
.native-user-login{max-width:460px;margin:10vh auto;padding:28px}.native-user-login form{display:grid;gap:18px}label{display:grid;gap:8px;margin:12px 0}input,select{border:1px solid var(--u-border);border-radius:8px;padding:10px;background:var(--u-panel);color:var(--u-text)}button{margin:4px}[role=alert]{color:var(--u-danger)}li{padding:10px 0}a.primary-btn{display:inline-block;text-decoration:none}
</style>
<style scoped>
.native-user-mobile-nav{display:none;gap:12px;flex-wrap:wrap}.native-user-mobile-nav a{color:var(--u-text);text-decoration:none}.native-user-mobile-nav a.router-link-active{color:var(--u-primary)}@media(max-width:900px){.user-header{flex-wrap:wrap;gap:12px}.native-user-mobile-nav{display:flex;order:3;width:100%}}
</style>
