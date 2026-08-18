import axios from 'axios'

/**
 * Shared axios instance. The base URL is whatever server the user saved in
 * Settings (the app must work against a LAN Laragon box today and a hosted
 * server later), so it is injected at runtime rather than baked in.
 */
const api = axios.create({ timeout: 25000 })

let onUnauthorized = null

/** The session store registers its logout here so an expired/revoked token kicks back to login. */
export const setUnauthorizedHandler = (fn) => { onUnauthorized = fn }

export const setApiBaseUrl = (url) => {
  api.defaults.baseURL = String(url ?? '').trim().replace(/\/+$/, '')
}

export const setApiToken = (token) => {
  if (token) api.defaults.headers.common.Authorization = `Bearer ${token}`
  else delete api.defaults.headers.common.Authorization
}

api.interceptors.response.use(
  (res) => res,
  (err) => {
    const isLogin = err.config?.url?.endsWith('/api/login')
    if (err.response?.status === 401 && !isLogin && onUnauthorized) onUnauthorized()
    return Promise.reject(err)
  },
)

/** Human-readable message out of an axios error, Laravel-validation aware. */
export function apiErrorMessage(e, fallback = 'Something went wrong.') {
  if (!e?.response) return 'Cannot reach the server. Check the server URL in Settings and your Wi-Fi connection.'
  const data = e.response.data
  if (data?.errors) {
    const first = Object.values(data.errors)[0]
    if (Array.isArray(first) && first[0]) return String(first[0])
  }
  return data?.message || fallback
}

export default api
