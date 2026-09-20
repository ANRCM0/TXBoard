<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from '../i18n'

const {t}=useI18n()
const props=defineProps<{modelValue:string;suffixes?:string[]|0}>()
const emit=defineEmits<{ 'update:modelValue':[value:string] }>()
const local=ref('')
const suffix=ref('')

const normalizedSuffixes=computed(()=>
  Array.isArray(props.suffixes)
    ? props.suffixes.map(item=>String(item).replace(/^@/,'')).filter(Boolean)
    : [],
)
const enabled=computed(()=>normalizedSuffixes.value.length>0)

watch(
  normalizedSuffixes,
  value=>{
    if(value.length){
      if(!value.includes(suffix.value))suffix.value=value[0]
      sync()
    }else{
      suffix.value=''
      local.value=props.modelValue
    }
  },
  {immediate:true},
)

watch(
  ()=>props.modelValue,
  value=>{
    if(!enabled.value){local.value=value;return}
    const at=value.lastIndexOf('@')
    if(at>0){
      const domain=value.slice(at+1)
      if(normalizedSuffixes.value.includes(domain)){
        local.value=value.slice(0,at)
        suffix.value=domain
      }
    }
  },
)

function sync(){
  emit(
    'update:modelValue',
    enabled.value
      ? local.value.trim()+(suffix.value?'@'+suffix.value:'')
      : local.value,
  )
}

function onFullInput(event:Event){
  emit('update:modelValue',(event.target as HTMLInputElement).value)
}
</script>

<template>
  <div v-if="enabled" class="email-suffix-input">
    <input v-model="local" type="text" autocomplete="username"  :placeholder="t('auth.emailUsername')" @input="sync"/>
    <select v-model="suffix" @change="sync">
      <option v-for="item in normalizedSuffixes" :key="item" :value="item">@{{ item }}</option>
    </select>
  </div>
  <input
    v-else
    :value="modelValue"
    type="email"
    autocomplete="email"
    placeholder="you@example.com"
    @input="onFullInput"
  />
</template>
