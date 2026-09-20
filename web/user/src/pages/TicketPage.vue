<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { closeTicket, fetchTicketById, fetchTickets, replyTicket, saveTicket, type TicketItem } from '../api/ticket'
import { errorMessage } from '../api/client'
import { useI18n } from '../i18n'

const {t,locale}=useI18n()
const rows=ref<TicketItem[]>([])
const selected=ref<TicketItem|null>(null)
const page=ref(1)
const total=ref(0)
const loading=ref(true)
const error=ref('')
const createOpen=ref(false)
const subject=ref('')
const level=ref(1)
const firstMessage=ref('')
const reply=ref('')
const acting=ref(false)
const pageSize=20

async function load(){
  loading.value=true
  try{
    const list=await fetchTickets(page.value,pageSize)
    rows.value=list.data||[]
    total.value=list.total||0
  }catch(e){error.value=errorMessage(e)}finally{loading.value=false}
}
onMounted(()=>void load())

async function openTicket(row:TicketItem){
  try{selected.value=await fetchTicketById(row.id)}catch(e){error.value=errorMessage(e)}
}
async function createTicket(){
  if(!subject.value.trim()||!firstMessage.value.trim())return
  acting.value=true
  try{
    await saveTicket({subject:subject.value.trim(),level:level.value,message:firstMessage.value.trim()})
    createOpen.value=false
    subject.value=''
    firstMessage.value=''
    page.value=1
    await load()
    if(rows.value[0])await openTicket(rows.value[0])
  }catch(e){error.value=errorMessage(e)}finally{acting.value=false}
}
async function sendReply(){
  if(!selected.value||!reply.value.trim())return
  acting.value=true
  try{
    await replyTicket({id:selected.value.id,message:reply.value.trim()})
    reply.value=''
    selected.value=await fetchTicketById(selected.value.id)
    await load()
  }catch(e){error.value=errorMessage(e)}finally{acting.value=false}
}
async function closeCurrent(){
  if(!selected.value||!confirm(t('ticket.confirmClose')))return
  acting.value=true
  try{
    await closeTicket(selected.value.id)
    selected.value=await fetchTicketById(selected.value.id)
    await load()
  }catch(e){error.value=errorMessage(e)}finally{acting.value=false}
}
function levelText(v:number){return ({0:t('ticket.low'),1:t('ticket.medium'),2:t('ticket.high')} as Record<number,string>)[v]||'-'}
function date(v?:number){return v?new Date(v*1000).toLocaleString(locale.value):'-'}
function statusLabel(row:TicketItem){
  if(row.status===1)return t('ticket.closed')
  if(row.reply_status===1)return t('ticket.replied')
  return t('ticket.waitReply')
}
function statusClass(row:TicketItem){
  if(row.status===1||row.reply_status===1)return 'ok'
  return 'bad'
}
</script>

<template>
  <div class="ticket-page">
    <div v-if="error" class="page-alert">{{ error }}</div>

    <section class="xboard-card ticket-main-card">
      <header class="xboard-card-header ticket-card-header">
        <span>{{ t('ticket.title') }}</span>
        <button class="primary-btn small-btn" @click="createOpen=true">{{ t('ticket.new') }}</button>
      </header>

      <div class="responsive-table">
        <table>
          <thead>
            <tr>
              <th>{{ t('ticket.subject') }}</th>
              <th>{{ t('ticket.priority') }}</th>
              <th>{{ t('common.status') }}</th>
              <th>{{ t('ticket.updatedAt') }}</th>
              <th>{{ t('common.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.id">
              <td>{{ row.subject }}</td>
              <td>{{ levelText(row.level) }}</td>
              <td>
                <span class="order-status-inline">
                  <span class="order-status-dot" :class="statusClass(row)"/>
                  {{ statusLabel(row) }}
                </span>
              </td>
              <td>{{ date(row.updated_at) }}</td>
              <td>
                <button class="order-link-btn" @click="openTicket(row)">{{ t('ticket.view') }}</button>
              </td>
            </tr>
            <tr v-if="!loading&&!rows.length"><td colspan="5" class="empty-cell">{{ t('ticket.empty') }}</td></tr>
          </tbody>
        </table>
      </div>

      <div class="pager">
        <span>{{ t('common.total',{total}) }}</span>
        <div>
          <button class="secondary-btn small-btn" :disabled="page<=1" @click="page--;load()">{{ t('common.previous') }}</button>
          <span>{{ t('common.page',{page}) }}</span>
          <button class="secondary-btn small-btn" :disabled="page*pageSize>=total" @click="page++;load()">{{ t('common.next') }}</button>
        </div>
      </div>
    </section>

    <div v-if="createOpen" class="modal-mask" @click.self="createOpen=false">
      <div class="user-modal ticket-create-modal">
        <div class="modal-head"><h2>{{ t('ticket.new') }}</h2><button class="user-icon-btn" @click="createOpen=false">×</button></div>
        <div class="form-stack">
          <label class="field-label">{{ t('ticket.subject') }}<input v-model="subject" class="form-control"/></label>
          <label class="field-label">{{ t('ticket.priority') }}<select v-model="level" class="form-control"><option :value="0">{{ t('ticket.low') }}</option><option :value="1">{{ t('ticket.medium') }}</option><option :value="2">{{ t('ticket.high') }}</option></select></label>
          <label class="field-label">{{ t('ticket.description') }}<textarea v-model="firstMessage" class="form-control textarea"/></label>
          <div class="modal-actions">
            <button class="secondary-btn" @click="createOpen=false">{{ t('common.cancel') }}</button>
            <button class="primary-btn" :disabled="acting" @click="createTicket">{{ acting?t('ticket.submitting'):t('ticket.submit') }}</button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="selected" class="modal-mask" @click.self="selected=null">
      <div class="user-modal wide">
        <div class="modal-head"><div><h2>{{ selected.subject }}</h2><small>{{ statusLabel(selected) }}</small></div><button class="user-icon-btn" @click="selected=null">×</button></div>
        <div class="ticket-thread">
          <article v-for="msg in selected.message||[]" :key="msg.id" class="ticket-bubble" :class="{mine:msg.is_me}">
            <strong>{{ msg.is_me?t('ticket.me'):t('ticket.admin') }}</strong><p>{{ msg.message }}</p><small>{{ date(msg.created_at) }}</small>
          </article>
          <div v-if="!(selected.message||[]).length" class="empty-state">{{ t('ticket.noMessages') }}</div>
        </div>
        <div v-if="selected.status===0" class="form-stack">
          <label class="field-label">{{ t('ticket.reply') }}<textarea v-model="reply" class="form-control textarea"/></label>
          <div class="modal-actions">
            <button class="secondary-btn danger" :disabled="acting" @click="closeCurrent">{{ t('ticket.close') }}</button>
            <button class="primary-btn" :disabled="acting||!reply.trim()" @click="sendReply">{{ acting?t('ticket.processingAction'):t('ticket.send') }}</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
