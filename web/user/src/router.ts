import { createRouter, createWebHashHistory } from 'vue-router'
import UserApp from './native/UserApp.vue'
import { user } from './native/session'
const router = createRouter({history:createWebHashHistory(),routes:[
  {path:'/order/:tradeNo',component:UserApp},
  {path:'/:pathMatch(.*)*',component:UserApp},
]})
router.beforeEach(to => {
  if(!user.authenticated.value && to.path !== '/login') return '/login'
  if(user.authenticated.value && (to.path === '/login' || to.path === '/')) return '/dashboard'
  return true
})
export default router
