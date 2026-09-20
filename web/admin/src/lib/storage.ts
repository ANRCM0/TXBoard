const TOKEN_KEY = 'access_token'

export function getAccessToken() {
  return localStorage.getItem(TOKEN_KEY)
}

export function normalizeAuthorization(value: string | null | undefined) {
  const token = String(value || '').trim()
  if (!token) return ''
  return /^Bearer\s+/i.test(token) ? token.replace(/^Bearer\s+/i, 'Bearer ') : `Bearer ${token}`
}

export function getAuthorizationHeader() {
  return normalizeAuthorization(getAccessToken())
}

export function setAccessToken(token: string) {
  const normalized = normalizeAuthorization(token)
  if (!normalized) {
    localStorage.removeItem(TOKEN_KEY)
    return
  }
  localStorage.setItem(TOKEN_KEY, normalized)
}

export function removeAccessToken() {
  localStorage.removeItem(TOKEN_KEY)
}
