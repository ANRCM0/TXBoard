<script setup lang="ts">
import { onMounted } from 'vue'
import { useAuthStore } from './stores/auth'

const auth = useAuthStore()
onMounted(() => {
  if (auth.hasToken) void auth.checkSession()
})
</script>

<template>
  <router-view />
</template>
