import { createContext, useCallback, useContext, useEffect, useState } from 'react'
import { api, tokenStore } from '../lib/api'

const AuthContext = createContext(null)


export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)

  const [platformUrl, setPlatformUrl] = useState(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    if (!tokenStore.get()) {
      setLoading(false)
      return
    }

    api('/me')
      .then((response) => {
        setUser(response.user)
        setPlatformUrl(response.platform_url ?? null)
      })
      .catch(() => tokenStore.clear())
      .finally(() => setLoading(false))
  }, [])

  const login = useCallback(async (email, password) => {
    const response = await api('/login', { method: 'POST', body: { email, password } })
    tokenStore.set(response.token)
    setUser(response.user)
    setPlatformUrl(response.platform_url ?? null)
  }, [])

  const logout = useCallback(async () => {
    try { await api('/logout', { method: 'POST' }) } catch { /* تجاهل */ }
    tokenStore.clear()
    setUser(null)
  }, [])

  const can = useCallback((permission) => Boolean(user?.permissions?.includes(permission)), [user])
  const hasRole = useCallback((role) => Boolean(user?.roles?.includes(role)), [user])

  return (
    <AuthContext.Provider value={{ user, platformUrl, loading, login, logout, can, hasRole }}>
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth() {
  return useContext(AuthContext)
}
