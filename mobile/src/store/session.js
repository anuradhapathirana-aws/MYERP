import AsyncStorage from '@react-native-async-storage/async-storage'
import * as SecureStore from 'expo-secure-store'
import { createContext, useCallback, useContext, useEffect, useState } from 'react'
import apiClient, { setApiBaseUrl, setApiToken, setUnauthorizedHandler } from '../api/client'
import { login as apiLogin } from '../api/auth'

const KEY_SERVER = 'erp.serverUrl'
const KEY_USER   = 'erp.user'
const KEY_TOKEN  = 'erp.token' // token lives in SecureStore, never AsyncStorage

const SessionContext = createContext(null)

export function SessionProvider({ children }) {
  const [ready, setReady]             = useState(false)
  const [serverUrl, setServerUrlState] = useState('')
  const [token, setToken]             = useState(null)
  const [user, setUser]               = useState(null)

  useEffect(() => {
    (async () => {
      try {
        const [url, userJson, storedToken] = await Promise.all([
          AsyncStorage.getItem(KEY_SERVER),
          AsyncStorage.getItem(KEY_USER),
          SecureStore.getItemAsync(KEY_TOKEN),
        ])
        if (url) {
          setApiBaseUrl(url)
          setServerUrlState(url)
        }
        if (storedToken) {
          setApiToken(storedToken)
          setToken(storedToken)
        }
        if (userJson) setUser(JSON.parse(userJson))
      } catch {
        // corrupt storage — start clean
      } finally {
        setReady(true)
      }
    })()
  }, [])

  const logout = useCallback(async () => {
    setApiToken(null)
    setToken(null)
    setUser(null)
    await Promise.all([
      SecureStore.deleteItemAsync(KEY_TOKEN).catch(() => {}),
      AsyncStorage.removeItem(KEY_USER).catch(() => {}),
    ])
  }, [])

  // A 401 anywhere (expired/revoked token) drops the session; the router gate sends the user to login.
  useEffect(() => { setUnauthorizedHandler(logout) }, [logout])

  const setServerUrl = useCallback(async (url) => {
    const clean = String(url ?? '').trim().replace(/\/+$/, '')
    setApiBaseUrl(clean)
    setServerUrlState(clean)
    await AsyncStorage.setItem(KEY_SERVER, clean)
  }, [])

  const login = useCallback(async (email, password) => {
    const data = await apiLogin(email, password)
    const profile = {
      user_id:        data.user_id,
      user_name:      data.user_name,
      user_email:     data.user_email,
      roles:          data.roles ?? [],
      permissions:    data.permissions ?? [],
      active_modules: data.active_modules ?? [],
    }
    setApiToken(data.token)
    setToken(data.token)
    setUser(profile)
    await Promise.all([
      SecureStore.setItemAsync(KEY_TOKEN, data.token),
      AsyncStorage.setItem(KEY_USER, JSON.stringify(profile)),
    ])
    return profile
  }, [])

  // Mirrors the web's usePermissions hook: super_admin bypasses every check
  // (the backend grants it globally via Gate::before, so its permission list is empty).
  const can = useCallback((perm) => {
    if (user?.roles?.includes('super_admin')) return true
    return user?.permissions?.includes(perm) ?? false
  }, [user])

  return (
    <SessionContext.Provider value={{ ready, serverUrl, setServerUrl, token, user, login, logout, can, api: apiClient }}>
      {children}
    </SessionContext.Provider>
  )
}

export const useSession = () => useContext(SessionContext)
