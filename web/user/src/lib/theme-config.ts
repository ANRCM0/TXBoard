import type { GuestConfig } from '../api/comm'

/**
 * The active theme owns public presentation settings. System configuration
 * (accounts, payments, registration and site identity) stays independent.
 */
export function applyUserThemeConfig(guest: Pick<GuestConfig, 'frontend_theme' | 'theme_config'>) {
  // The built-in Vue frontend is only rendered when the built-in theme is active.
  if (guest.frontend_theme && guest.frontend_theme !== 'TXBoard') return

  const config = guest.theme_config ?? {}
  const accent = String(config.theme_color ?? 'default')
  const allowed = ['default', 'blue', 'black', 'darkblue']
  document.documentElement.dataset.userAccent = allowed.includes(accent) ? accent : 'default'

  const background = typeof config.background_url === 'string' ? config.background_url.trim() : ''
  const rootStyle = document.documentElement.style
  if (!background) {
    rootStyle.removeProperty('--u-theme-background-image')
    return
  }
  try {
    const resolved = new URL(background, window.location.origin)
    if (!['http:', 'https:'].includes(resolved.protocol)) {
      rootStyle.removeProperty('--u-theme-background-image')
      return
    }
    // CSS quoted url(): encode characters which could break the quoted value.
    const escaped = resolved.href.replace(/["\\\n\r\f]/g, char =>
      '\\' + char.charCodeAt(0).toString(16) + ' ',
    )
    rootStyle.setProperty('--u-theme-background-image', `url("${escaped}")`)
  } catch {
    rootStyle.removeProperty('--u-theme-background-image')
  }
}
