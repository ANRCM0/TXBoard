import { createRouter, createWebHashHistory, type RouteRecordRaw } from 'vue-router'
import { getAuthData } from './api/client'
import type { UserCommConfig } from './api/comm'
import { useUserCommConfig } from './composables/useUserCommConfig'
import { featureEnabled } from './lib/feature-flags'

const publicPaths=['/login','/register','/forgetpassword']

type FeatureKey='traffic_log_enable'|'knowledge_enable'|'ticket_enable'|'invite_enable'|'gift_card_enable'

const routes:RouteRecordRaw[]=[
  {path:'/login',component:()=>import('./pages/AuthPage.vue')},
  {path:'/register',redirect:to=>({path:'/login',query:{...to.query,tab:'register'}})},
  {path:'/forgetpassword',redirect:to=>({path:'/login',query:{...to.query,tab:'forget'}})},
  {
    path:'/',
    component:()=>import('./layouts/AppLayout.vue'),
    children:[
      {path:'',redirect:'/dashboard'},
      {path:'dashboard',component:()=>import('./pages/DashboardPage.vue')},
      {path:'plan',component:()=>import('./pages/PlanPage.vue')},
      {path:'plan/:id',component:()=>import('./pages/PlanDetailPage.vue')},
      {path:'order',component:()=>import('./pages/OrderPage.vue')},
      {path:'order/:trade_no',component:()=>import('./pages/OrderDetailPage.vue')},
      {path:'node',component:()=>import('./pages/NodePage.vue')},
      {path:'traffic',component:()=>import('./pages/TrafficPage.vue'),meta:{feature:'traffic_log_enable'}},
      {path:'knowledge',component:()=>import('./pages/KnowledgePage.vue'),meta:{feature:'knowledge_enable'}},
      {path:'ticket',component:()=>import('./pages/TicketPage.vue'),meta:{feature:'ticket_enable'}},
      {path:'invite',component:()=>import('./pages/InvitePage.vue'),meta:{feature:'invite_enable'}},
      {path:'gift-card',component:()=>import('./pages/GiftCardPage.vue'),meta:{feature:'gift_card_enable'}},
      {path:'profile',component:()=>import('./pages/ProfilePage.vue')},
      {path:':pathMatch(.*)*',component:()=>import('./pages/NotFoundPage.vue')},
    ],
  },
]

const router=createRouter({history:createWebHashHistory(),routes})

function enabled(config:UserCommConfig,key:FeatureKey){
  return featureEnabled(config[key],true)
}

router.beforeEach(async to=>{
  if(import.meta.env.VITE_STATIC_PREVIEW==='1')return true
  const loggedIn=Boolean(getAuthData())
  if(!loggedIn&&!publicPaths.includes(to.path)){
    return {path:'/login',query:{redirect:to.fullPath}}
  }
  if(loggedIn&&publicPaths.includes(to.path)){
    return '/dashboard'
  }

  const feature=to.meta.feature as FeatureKey|undefined
  if(loggedIn&&feature){
    const {config,load}=useUserCommConfig()
    try{
      const current=config.value??await load()
      if(!enabled(current,feature))return '/dashboard'
    }catch{
      return '/dashboard'
    }
  }
  return true
})

export default router
