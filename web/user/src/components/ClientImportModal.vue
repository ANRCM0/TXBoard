<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { appendSubscribeTypes, buildImportClients, detectPlatform, filterClientsByPlatform, PROTOCOL_TYPES } from '../lib/client-import'
import { useI18n } from '../i18n'

const props=defineProps<{show:boolean;subscribeUrl:string;siteTitle?:string}>()
const emit=defineEmits<{close:[];copied:[]}>()
const {t}=useI18n()
const selectedTypes=ref<string[]>(['auto'])

watch(()=>props.show,open=>{if(open)selectedTypes.value=['auto']})
const filteredUrl=computed(()=>appendSubscribeTypes(props.subscribeUrl,selectedTypes.value))
const clients=computed(()=>filterClientsByPlatform(buildImportClients(filteredUrl.value,props.siteTitle||'TXBoard'),detectPlatform()))

function protocolLabel(item:{label:string;value:string}){return item.value==='auto'?t('client.auto'):item.label}
function clientName(name:string){return name==='__copy__'?t('client.copySubscription'):name}
function toggleType(value:string){
  if(value==='auto'){selectedTypes.value=['auto'];return}
  const next=selectedTypes.value.filter(item=>item!=='auto')
  selectedTypes.value=next.includes(value)?next.filter(item=>item!==value):[...next,value]
  if(!selectedTypes.value.length)selectedTypes.value=['auto']
}
async function clickClient(client:ReturnType<typeof buildImportClients>[number]){
  if(client.action==='copy'){await navigator.clipboard.writeText(filteredUrl.value);emit('copied');return}
  if(client.url)window.location.href=client.url
}
</script>

<template>
  <div v-if="show" class="modal-mask" @click.self="emit('close')">
    <div class="user-modal">
      <div class="modal-head"><div><h2>{{ t('client.title') }}</h2><small>{{ t('client.desc') }}</small></div><button class="user-icon-btn" @click="emit('close')">×</button></div>
      <div class="protocol-grid">
        <label v-for="item in PROTOCOL_TYPES" :key="item.value" class="option-chip" :class="{active:selectedTypes.includes(item.value)}">
          <input type="checkbox" :checked="selectedTypes.includes(item.value)" @change="toggleType(item.value)"/><span>{{ protocolLabel(item) }}</span>
        </label>
      </div>
      <div class="client-import-list">
        <button v-for="client in clients" :key="client.name" class="client-import-btn" @click="clickClient(client)">
          <span>{{ clientName(client.name) }}</span><small>{{ client.action==='copy'?t('client.copy'):t('client.open') }}</small>
        </button>
      </div>
      <div v-if="!clients.length" class="empty-state">{{ t('client.empty') }}</div>
    </div>
  </div>
</template>
