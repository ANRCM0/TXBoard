// my-theme router: hash-based navigation for a statically served SPA.
// Auth guard: protected routes redirect to /login; unauthenticated users never
// reach the pages that would issue Bearer requests.
import { createRouter, createWebHashHistory } from 'vue-router'
import HomePage from '../pages/HomePage.vue'
import LoginPage from '../pages/LoginPage.vue'
import UserPage from '../pages/UserPage.vue'
import OrdersPage from '../pages/OrdersPage.vue'
import { readToken } from './api'

function requireAuth(to, _from, next) {
  if (readToken()) return next()
  next({ path: '/login', query: { redirect: to.fullPath } })
}

export const router = createRouter({
  history: createWebHashHistory(),
  routes: [
    { path: '/', name: 'home', component: HomePage },
    { path: '/login', name: 'login', component: LoginPage },
    { path: '/dashboard', name: 'dashboard', component: UserPage, beforeEnter: requireAuth },
    { path: '/orders', name: 'orders', component: OrdersPage, beforeEnter: requireAuth },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})
