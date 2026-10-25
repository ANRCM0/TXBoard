export function initLocale() {
  let saved: string | null = null
  try { saved = localStorage.getItem('txboard_user_locale') } catch { /* Browser policy may disable storage. */ }
  const language = (navigator.language || 'zh-CN').toLowerCase()
  document.documentElement.lang = saved === 'en-US' || saved === 'zh-CN'
    ? saved : language.startsWith('zh') ? 'zh-CN' : 'en-US'
}
