import { createContext, useCallback, useContext, useEffect, useState } from 'react'
import { api, tokenStore } from '../lib/api'

const AuthContext = createContext(null)

/**
 * حالة المستخدم الحالي وصلاحياته.
 *
 * قائمة الصلاحيات تأتي جاهزة من الخادم (صلاحيات الدور + المنح المباشر
 * مدموجة). الواجهة لا تحسبها بنفسها، وتستخدمها لإخفاء الأزرار فقط —
 * الفحص الحقيقي يقع في الخادم على كل مسار.
 */
export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [loading, setLoading] = useState(true)

  // عند فتح الصفحة: لو كان هناك توكن محفوظ، نتحقّق أنه ما زال صالحاً.
  useEffect(() => {
    if (!tokenStore.get()) {
      setLoading(false)
      return
    }

    api('/me')
      .then((response) => setUser(response.user))
      .catch(() => tokenStore.clear())
      .finally(() => setLoading(false))
  }, [])

  const login = useCallback(async (email, password) => {
    const response = await api('/login', { method: 'POST', body: { email, password } })
    tokenStore.set(response.token)
    setUser(response.user)
  }, [])

  const logout = useCallback(async () => {
    // نحذف التوكن من الخادم أولاً، ثم محلياً مهما كانت النتيجة.
    try { await api('/logout', { method: 'POST' }) } catch { /* تجاهل */ }
    tokenStore.clear()
    setUser(null)
  }, [])

  const can = useCallback((permission) => Boolean(user?.permissions?.includes(permission)), [user])
  const hasRole = useCallback((role) => Boolean(user?.roles?.includes(role)), [user])

  return (
    <AuthContext.Provider value={{ user, loading, login, logout, can, hasRole }}>
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth() {
  return useContext(AuthContext)
}
