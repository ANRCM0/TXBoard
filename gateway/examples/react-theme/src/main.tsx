import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import App from './App'
import './styles/tokens.css'
import './styles/base.css'

// Theme initialization: apply bootstrap appearance once, then mount.
// Appearance values from theme.config drive CSS variables only — they are
// never authority for permissions or business rules.
async function init() {
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

  createRoot(document.getElementById('root')!).render(
    <StrictMode>
      <App />
    </StrictMode>,
  )
}

init()
