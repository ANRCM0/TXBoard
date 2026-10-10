import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import { initLocale } from './i18n'
import './styles.css'

initLocale()
createApp(App).use(createPinia()).use(router).mount('#app')
