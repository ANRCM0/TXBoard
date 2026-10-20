import { useEffect, useState } from 'react'

export type ThemePreference = 'light' | 'dark' | 'system'
function readTheme(): ThemePreference {
  try {
    const value = localStorage.getItem('theme')
    return value === 'light' || value === 'dark' ? value : 'system'
  } catch { return 'system' }
}

export function useTheme() {
  const [theme, setTheme] = useState(readTheme)
  const [systemDark, setSystemDark] = useState(() => window.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false)
  const dark = theme === 'dark' || theme === 'system' && systemDark

  useEffect(() => {
    const media = window.matchMedia?.('(prefers-color-scheme: dark)')
    const update = () => setSystemDark(media?.matches ?? false)
    media?.addEventListener('change', update)
    const sync = () => setTheme(readTheme())
    window.addEventListener('storage', sync)
    window.addEventListener('txboard-theme', sync)
    return () => {
      media?.removeEventListener('change', update)
      window.removeEventListener('storage', sync)
      window.removeEventListener('txboard-theme', sync)
    }
  }, [])

  useEffect(() => {
    document.documentElement.dataset.theme = dark ? 'dark' : 'light'
    document.documentElement.style.colorScheme = dark ? 'dark' : 'light'
  }, [dark])

  function chooseTheme(value: ThemePreference) {
    setTheme(value)
    try {
      localStorage.setItem('theme', value)
      window.dispatchEvent(new Event('txboard-theme'))
    } catch { /* preference is optional */ }
  }
  return { dark, theme, setTheme: chooseTheme }
}
