<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import DOMPurify from 'dompurify'
import { fetchKnowledge, fetchKnowledgeCategories, type KnowledgeItem } from '../api/knowledge'
import { errorMessage } from '../api/client'
import { useI18n } from '../i18n'

const ALL='__all__'
const {t,locale}=useI18n()
const rows=ref<KnowledgeItem[]>([])
const categories=ref<string[]>([])
const activeCategory=ref(ALL)
const keyword=ref('')
const query=ref('')
const openIds=ref<Set<number>>(new Set())
const loading=ref(true)
const error=ref('')

async function load(){
  loading.value=true
  error.value=''
  try{
    const [items,cats]=await Promise.all([
      fetchKnowledge(locale.value),
      fetchKnowledgeCategories(locale.value).catch(()=>[] as string[]),
    ])
    rows.value=items
    categories.value=cats.length?cats:[...new Set(items.map(item=>item.category).filter(Boolean))]
    openIds.value=new Set()
  }catch(e){error.value=errorMessage(e)}finally{loading.value=false}
}
onMounted(()=>void load())
watch(locale,()=>{
  activeCategory.value=ALL
  keyword.value=''
  query.value=''
  void load()
})

const filtered=computed(()=>rows.value.filter(item=>{
  if(activeCategory.value!==ALL&&item.category!==activeCategory.value)return false
  const q=query.value.trim().toLowerCase()
  if(!q)return true
  return (item.title+' '+item.body).toLowerCase().includes(q)
}))

function search(){query.value=keyword.value}
function toggle(id:number){
  const next=new Set(openIds.value)
  if(next.has(id))next.delete(id)
  else next.add(id)
  openIds.value=next
}
function safeHtml(body:string){return DOMPurify.sanitize(body)}
</script>

<template>
  <section class="txboard-card knowledge-page-card">
    <div class="knowledge-search-bar">
      <input v-model="keyword" class="form-control" :placeholder="t('knowledge.search')" @keyup.enter="search"/>
      <button class="secondary-btn" @click="search">{{ t('common.search') }}</button>
    </div>

    <div v-if="categories.length>1" class="knowledge-tabs">
      <button :class="{active:activeCategory===ALL}" @click="activeCategory=ALL">{{ t('knowledge.allCategory') }}</button>
      <button v-for="category in categories" :key="category" :class="{active:activeCategory===category}" @click="activeCategory=category">
        {{ category }}
      </button>
    </div>

    <div v-if="error" class="page-alert">{{ error }}</div>

    <div v-if="loading" class="skeleton-stack knowledge-loading"><div/><div/><div/></div>

    <div v-else-if="filtered.length" class="knowledge-collapse">
      <article v-for="item in filtered" :key="item.id" class="knowledge-collapse-item">
        <button class="knowledge-collapse-trigger" @click="toggle(item.id)">
          <span>{{ item.title }}</span>
          <span class="knowledge-collapse-arrow" :class="{open:openIds.has(item.id)}">⌄</span>
        </button>
        <div v-if="openIds.has(item.id)" class="knowledge-collapse-body" v-html="safeHtml(item.body)"/>
      </article>
    </div>

    <div v-else class="empty-state">{{ t('knowledge.noMatch') }}</div>
  </section>
</template>
