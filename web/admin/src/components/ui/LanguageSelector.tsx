import { useState } from 'react'
import { Languages } from 'lucide-react'
import { LOCALES, useLocale, type Locale } from '../../lib/i18n'
import './language-selector.css'

const names: Record<Locale, string> = { 'zh-CN': '简体中文', 'en-US': 'English', 'ru-RU': 'Русский' }
export function LanguageSelector({ className = '' }: { className?: string }) {
  const { locale, setLocale, loading, t } = useLocale()
  const [failed, setFailed] = useState(false)
  return <span className={'ui-language-selector ' + className}>
    <label><Languages size={16} aria-hidden="true" /><span className="admin-route-announcer">{t('语言')}</span>
      <select aria-label={t('语言')} value={locale} aria-busy={loading} onChange={event => { setFailed(false); void setLocale(event.target.value as Locale).catch(() => setFailed(true)) }}>
        {LOCALES.map(language => <option key={language} value={language} lang={language}>{names[language]}</option>)}
      </select>
    </label>
    {failed ? <span role="alert">{t('语言资源加载失败，请重试。')}</span> : null}
  </span>
}
