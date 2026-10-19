import { createApp } from 'vue'
import App from './App.vue'
import router from './router'
import { initLocale } from './i18n'
import './styles.css'

initLocale()
createApp(App).use(router).mount('#app')
