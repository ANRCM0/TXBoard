// Vue theme entry: apply bootstrap appearance + mount the SPA (GW-504).
import { createApp } from 'vue'
import { router } from './router'
import App from './App.vue'
import './styles/tokens.css'
import './styles/base.css'

async function init() {
  // Theme initialization reads the public bootstrap once and applies the
  // theme-owned appearance settings. Appearance is NOT authority: only
  // TXBoard permissions decide what the user may do.
  try {
    const res = await fetch('/gateway/v1/bootstrap', {
      headers: { Accept: 'application/json' },
      credentials: 'omit',
      redirect: 'error',
    })
    const envelope = await res.json()
    if (envelope?.ok && envelope.data) {
      const { site, theme } = envelope.data
      document.title = site?.name ?? document.title
      const appearance = (theme?.config ?? {}) as Record<string, unknown>
      const color = typeof appearance.theme_color === 'string' ? appearance.theme_color : 'default'
      document.documentElement.dataset.themeColor = color
      const background = typeof appearance.background_url === 'string' ? appearance.background_url : ''
      if (background) {
        document.body.style.backgroundImage = `url("${background.replace(/["\\]/g, '')}")`
      }
    }
  } catch {
    // Bootstrap failure is non-fatal: pages surface their own error state.
  }

  createApp(App).use(router).mount('#app')
}

init()
