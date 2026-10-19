export function initLocale() {
  const saved = localStorage.getItem('txboard_user_locale')
  const language = (navigator.language || 'zh-CN').toLowerCase()
  document.documentElement.lang = saved === 'en-US' || saved === 'zh-CN'
    ? saved : language.startsWith('zh') ? 'zh-CN' : 'en-US'
}
