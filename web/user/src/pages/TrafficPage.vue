<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { fetchTrafficLog, type TrafficLogItem } from '../api/traffic'
import { errorMessage } from '../api/client'
import { useI18n } from '../i18n'

const {t,locale}=useI18n()
const rows=ref<TrafficLogItem[]>([])
const loading=ref(true)
const error=ref('')

onMounted(async()=>{
  try{rows.value=await fetchTrafficLog()}catch(e){error.value=errorMessage(e)}finally{loading.value=false}
})

function rate(row:TrafficLogItem){
  const raw=row.server_rate??row.rate??1
  const n=parseFloat(String(raw))
  return Number.isFinite(n)&&n>0?n:1
}
function bytes(value:number){
  if(!value)return '0 B'
  const units=['B','KB','MB','GB','TB']
  let n=value,i=0
  while(n>=1024&&i<units.length-1){n/=1024;i++}
  return n.toFixed(i>=3?2:1)+' '+units[i]
}
function date(ts:number){return new Date(ts*1000).toLocaleDateString(locale.value)}
const total=computed(()=>rows.value.length)
</script>

<template>
  <section class="xboard-card traffic-page-card">
    <div class="traffic-info-alert">{{ t('traffic.hint') }}</div>
    <div v-if="error" class="traffic-error-alert">{{ error }}</div>

    <div class="responsive-table traffic-table-wrap">
      <table>
        <thead>
          <tr>
            <th>{{ t('common.time') }}</th>
            <th>{{ t('traffic.upload') }}</th>
            <th>{{ t('traffic.download') }}</th>
            <th>{{ t('traffic.rate') }}</th>
            <th>{{ t('traffic.total') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(row,index) in rows" :key="row.record_at+'-'+index">
            <td>{{ date(row.record_at) }}</td>
            <td>{{ bytes(Number(row.u||0)/rate(row)) }}</td>
            <td>{{ bytes(Number(row.d||0)/rate(row)) }}</td>
            <td><span class="x-tag">{{ rate(row) }} x</span></td>
            <td>{{ bytes((Number(row.u||0)+Number(row.d||0))/rate(row)) }}</td>
          </tr>
          <tr v-if="!loading&&!rows.length"><td colspan="5" class="empty-cell">{{ t('traffic.empty') }}</td></tr>
        </tbody>
      </table>
    </div>

    <div class="traffic-footer-count">{{ t('common.total',{total}) }}</div>
  </section>
</template>
