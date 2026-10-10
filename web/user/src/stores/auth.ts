import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { getAuthData, clearAuthData } from '../api/client'
import { login as loginApi, logout as logoutApi, register as registerApi, type LoginForm, type RegisterForm } from '../api/auth'
import { fetchUserInfo, type UserInfo } from '../api/user'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<UserInfo | null>(null)
  const loading = ref(false)
  const authenticated = ref(Boolean(getAuthData()))
  const hasToken = computed(() => authenticated.value)
  let userLoading: Promise<UserInfo> | null = null

  async function login(form: LoginForm) {
    loading.value = true
    try {
      await loginApi(form)
      authenticated.value = true
      await loadUser()
    } finally {
      loading.value = false
    }
  }

  async function register(form: RegisterForm) {
    loading.value = true
    try {
      await registerApi(form)
      authenticated.value = true
      await loadUser()
    } finally {
      loading.value = false
    }
  }

  function loadUser() {
    if (userLoading) return userLoading
    userLoading = fetchUserInfo()
      .then(data => {
        user.value = data
        authenticated.value = true
        return data
      })
      .catch(error => {
        const status = (error as { response?: { status?: number } })?.response?.status
        if (status === 401 || status === 403) {
          clearAuthData()
          user.value = null
          authenticated.value = false
        }
        throw error
      })
      .finally(() => {
        userLoading = null
      })
    return userLoading
  }

  async function checkSession() {
    if (!getAuthData()) {
      authenticated.value = false
      user.value = null
      return false
    }
    try {
      // /txapi/me is both the session proof and the user profile: avoid
      // a redundant login-check request and a second round trip.
      await loadUser()
      return true
    } catch {
      // loadUser clears credentials only for definitive 401/403 responses.
      // A network failure must not silently destroy a valid bearer token.
      return false
    }
  }

  async function logout() {
    await logoutApi()
    user.value = null
    authenticated.value = false
  }

  return { user, loading, authenticated, hasToken, login, register, loadUser, checkSession, logout }
})
