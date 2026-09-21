<script setup lang="ts">
import { loadStripe, type Stripe, type StripeCardElement, type StripeElementStyle } from '@stripe/stripe-js'
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { fetchStripePublicKey } from '../api/comm'
import { errorMessage } from '../api/client'

const props=defineProps<{paymentId:number|null}>()
const mountId='stripe-card-'+Math.random().toString(36).slice(2)
const ready=ref(false)
const mountError=ref<string|null>(null)
let stripe:Stripe|null=null
let card:StripeCardElement|null=null
let observer:MutationObserver|undefined
let mountSeq=0

function dark(){return document.documentElement.dataset.userTheme==='dark'}
function style():StripeElementStyle{
  return {
    base:{
      color:dark()?'#e5e7eb':'#172033',
      iconColor:dark()?'#cbd5e1':'#64748b',
      '::placeholder':{color:dark()?'#64748b':'#94a3b8'},
    },
  }
}
function sync(){card?.update({style:style()})}

async function mount(){
  const seq=++mountSeq
  ready.value=false
  mountError.value=null
  if(!props.paymentId)return
  try{
    const key=await fetchStripePublicKey(props.paymentId)
    if(seq!==mountSeq)return
    stripe=await loadStripe(key)
    if(seq!==mountSeq)return
    if(!stripe)throw new Error('Stripe.js 加载失败')
    const elements=stripe.elements()
    card?.destroy()
    card=elements.create('card',{hidePostalCode:true,style:style()})
    card.mount('#'+mountId)
    ready.value=true
  }catch(e){
    mountError.value=errorMessage(e)
  }
}

async function createToken(){
  if(!stripe||!card)return undefined
  const result=await stripe.createToken(card)
  if(result.error)throw new Error(result.error.message||'银行卡验证失败')
  return result.token?.id
}

onMounted(()=>{
  void mount()
  observer=new MutationObserver(sync)
  observer.observe(document.documentElement,{attributes:true,attributeFilter:['data-user-theme']})
})
watch(()=>props.paymentId,()=>void mount())
onBeforeUnmount(()=>{observer?.disconnect();card?.destroy();card=null})

defineExpose({createToken,ready,mountError})
</script>

<template>
  <div v-show="paymentId" class="stripe-card-wrap">
    <div :id="mountId" class="stripe-card-mount"/>
    <p v-if="mountError" class="stripe-error">{{ mountError }}</p>
  </div>
</template>
