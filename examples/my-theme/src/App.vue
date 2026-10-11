<!-- Vue app shell: brand, nav, route outlet (GW-504) -->
<template>
  <div class="app">
    <header class="top">
      <span class="brand">my-theme</span>
      <nav>
        <RouterLink to="/">首页</RouterLink>
        <RouterLink v-if="auth.isAuthed" to="/dashboard">用户中心</RouterLink>
        <RouterLink v-if="auth.isAuthed" to="/orders">我的订单</RouterLink>
        <RouterLink v-if="!auth.isAuthed" to="/login">登录</RouterLink>
        <button v-else class="link" @click="auth.logout()">退出</button>
      </nav>
    </header>
    <RouterView />
  </div>
</template>

<script setup lang="ts">
import { useAuth } from './composables/useAuth'
const auth = useAuth()
</script>
