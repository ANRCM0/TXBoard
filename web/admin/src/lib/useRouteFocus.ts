import { useEffect, useRef, useState, type RefObject } from 'react'
import { t } from './i18n'

export function useRouteFocus(pathname: string, main: RefObject<HTMLElement>, scroll: RefObject<HTMLDivElement>) {
  const previousPath = useRef(pathname)
  const [announcement, setAnnouncement] = useState('')

  useEffect(() => {
    if (scroll.current) scroll.current.scrollTop = 0
    window.scrollTo({ top: 0, left: 0, behavior: 'instant' })
    if (previousPath.current === pathname) return
    previousPath.current = pathname
    const content = main.current
    if (!content) return
    content.focus({ preventScroll: true })
    setAnnouncement('')
    let interacted = false
    let finished = false
    const preserveUserFocus = () => { interacted = true }
    document.addEventListener('pointerdown', preserveUserFocus, true)
    document.addEventListener('keydown', preserveUserFocus, true)

    // Lazy routes can take longer than a fixed series of short focus retries.
    const observer = new MutationObserver(focusHeading)
    const timeout = window.setTimeout(() => {
      if (finished) return
      finished = true
      observer.disconnect()
      setAnnouncement(t('页面已更新'))
    }, 10_000)
    const timer = window.setTimeout(focusHeading, 80)
    observer.observe(content, { childList: true, subtree: true, attributes: true, attributeFilter: ['hidden', 'inert', 'aria-hidden', 'style', 'class'] })

    function focusHeading() {
      if (finished) return
      const heading = Array.from(content?.querySelectorAll<HTMLElement>('h1') ?? []).find(candidate => {
        if (candidate.closest('[hidden], [inert], [aria-hidden="true"]')) return false
        for (let element: HTMLElement | null = candidate; element; element = element.parentElement) {
          const style = getComputedStyle(element)
          if (style.display === 'none' || style.visibility === 'hidden') return false
          if (element === content) break
        }
        return true
      })
      if (!heading) return
      finished = true
      observer.disconnect()
      window.clearTimeout(timeout)
      window.clearTimeout(timer)
      heading.tabIndex = -1
      if (!interacted) heading.focus({ preventScroll: true })
      setAnnouncement(t('已进入 {page}', { page: heading.textContent?.trim() || t('管理页面') }))
    }

    return () => {
      finished = true
      observer.disconnect()
      window.clearTimeout(timer)
      window.clearTimeout(timeout)
      document.removeEventListener('pointerdown', preserveUserFocus, true)
      document.removeEventListener('keydown', preserveUserFocus, true)
    }
  }, [pathname, main, scroll])

  return announcement
}
