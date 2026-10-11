// Vue theme router: hash-based navigation for a statically served SPA.
// Theme appearance (colors, background, logo) is applied from bootstrap.
import { createRouter, createWebHashHistory } from 'vue-router'
import HomePage from '../pages/HomePage.vue'
import LoginPage from '../pages/LoginPage.vue'
import UserPage from '../pages/UserPage.vue'
import OrdersPage from '../pages/OrdersPage.vue'

export const router = createRouter({
  history: createWebHashHistory(),
  routes: [
    { path: '/', name: 'home', component: HomePage },
    { path: '/login', name: 'login', component: LoginPage },
    { path: '/dashboard', name: 'dashboard', component: UserPage },
    { path: '/orders', name: 'orders', component: OrdersPage },
    // Plans and theme config pages live in a full theme; the shape is identical.
  ],
})
