/** Update only the Admin tab icon owned by TXBoard. Called after the
 * backend acknowledges an autosave; failed edits do not affect the tab. */
export function setAdminFavicon(url: string): void {
  let element = document.getElementById('txboard-admin-favicon') as HTMLLinkElement | null
  if (!url) {
    element?.remove()
    return
  }
  if (!element) {
    element = document.createElement('link')
    element.id = 'txboard-admin-favicon'
    element.rel = 'icon'
    element.referrerPolicy = 'no-referrer'
    document.head.appendChild(element)
  }
  element.href = url
}
