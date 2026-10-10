<script setup lang="ts">
import { onMounted } from 'vue'
import { fetchGuestConfig } from './api/comm'
import { applyUserThemeConfig } from './lib/theme-config'

onMounted(() => {
  // Refresh the selected theme's public configuration at each page load.
  void fetchGuestConfig()
    .then(config => applyUserThemeConfig(config))
    .catch(() => { /* Keep built-in appearance defaults when offline. */ })
})
</script>

<template>
  <router-view />
</template>
