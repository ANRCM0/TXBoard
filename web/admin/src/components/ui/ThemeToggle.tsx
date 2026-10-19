import { Moon, Sun } from 'lucide-react'
import { useTheme } from '../../lib/useTheme'

export function ThemeToggle() {
  const { dark, toggle } = useTheme()
  return <button type="button" className="admin-toolbar-icon" onClick={toggle}
    aria-label={dark ? '切换浅色模式' : '切换深色模式'} title={dark ? '切换浅色模式' : '切换深色模式'}>
    {dark ? <Sun size={18} /> : <Moon size={18} />}
  </button>
}
