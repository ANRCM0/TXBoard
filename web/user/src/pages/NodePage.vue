<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { errorMessage } from '../api/client'
import { fetchServers, type ServerNode } from '../api/server'
import { useI18n } from '../i18n'

const {t}=useI18n()
const nodes=ref<ServerNode[]>([])
const loading=ref(true)
const error=ref('')

onMounted(async()=>{
  try{nodes.value=await fetchServers()}catch(e){error.value=errorMessage(e)}finally{loading.value=false}
})
</script>

<template>
  <div class="node-page">
    <div v-if="error" class="page-alert">{{ error }}</div>

    <div v-if="loading" class="txboard-card node-loading-card">
      <div class="skeleton-stack"><div/><div/><div/></div>
    </div>

    <section v-else-if="nodes.length" class="txboard-card node-list-card">
      <div class="node-list-header">
        <div class="node-col-name">{{ t('node.name') }}</div>
        <div class="node-col-meta">
          <div>{{ t('common.status') }}</div>
          <div>{{ t('node.rate') }}</div>
          <div>{{ t('node.tags') }}</div>
        </div>
      </div>

      <div v-for="node in nodes" :key="node.id" class="node-list-row">
        <div class="node-col-name node-name">{{ node.name }}</div>
        <div class="node-col-meta">
          <div class="node-meta-cell">
            <span class="node-status-dot" :class="{online:node.is_online}"/>
          </div>
          <div class="node-meta-cell">
            <span class="x-tag">{{ node.rate }} x</span>
          </div>
          <div class="node-meta-cell node-meta-tags">
            <span v-for="tag in node.tags||[]" :key="tag" class="x-tag">{{ tag }}</span>
            <span v-if="!(node.tags||[]).length" class="node-tag-empty">-</span>
          </div>
        </div>
      </div>
    </section>

    <div v-else class="page-alert node-empty-alert">
      {{ t('node.subscribeHint') }}
      <router-link to="/plan">{{ t('dashboard.selectPlan') }}</router-link>
    </div>
  </div>
</template>
