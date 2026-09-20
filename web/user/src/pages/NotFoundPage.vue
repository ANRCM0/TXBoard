<script setup lang="ts">
import { useI18n } from '../i18n'
const {t}=useI18n()
</script>

<template>
  <div class="empty-page">
    <strong>404</strong>
    <h1>{{ t('notFound.title') }}</h1>
    <p>{{ t('notFound.desc') }}</p>
    <router-link class="primary-btn link-btn" to="/dashboard">{{ t('notFound.back') }}</router-link>
  </div>
</template>
