// Use the same direct destination on either layout, including cross-group navigation.
export async function navigateAdmin(page, path, mobile = false) {
  if (mobile) {
    if (!await page.locator('.admin-sidebar.is-open').count()) await page.getByRole('button', { name: '打开导航', exact: true }).click()
    const drawer = page.locator('.admin-sidebar.is-open')
    await drawer.locator(path === '/' ? '.admin-nav-root' : 'a[href="/admin' + path + '"]').click()
  } else {
    await page.getByRole('button', { name: '搜索菜单和功能', exact: true }).click()
    const escaped = path.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
    await page.getByRole('option').filter({ has: page.locator('small').filter({ hasText: new RegExp('^' + escaped + '$') }) }).click()
  }
}
