import { createContext, useContext, useState, useEffect } from 'react'
import { useLocation } from 'react-router-dom'
import { api } from '../api/client'
import { isPublicRoute } from '../lib/publicRoutes'

const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  const { pathname } = useLocation()
  const publicRoute = isPublicRoute(pathname)
  const [user, setUser] = useState(null)
  const [permissions, setPermissions] = useState({})
  const [features, setFeatures] = useState({})
  const [hasCheckedAuth, setHasCheckedAuth] = useState(false)

  useEffect(() => {
    if (publicRoute || hasCheckedAuth) return

    let cancelled = false

    api.me()
      .then(res => {
        if (cancelled) return
        setUser(res.data.user)
        setPermissions(res.data.permissions || {})
        setFeatures(res.data.features || {})
      })
      .catch(() => {
        if (!cancelled) setUser(null)
      })
      .finally(() => {
        if (!cancelled) setHasCheckedAuth(true)
      })

    return () => { cancelled = true }
  }, [publicRoute, hasCheckedAuth])

  const logout = async () => {
    await api.logout()
    setUser(null)
    setPermissions({})
    setFeatures({})
  }

  return (
    <AuthContext.Provider value={{ user, permissions, features, loading: !publicRoute && !hasCheckedAuth, logout }}>
      {children}
    </AuthContext.Provider>
  )
}

// eslint-disable-next-line react-refresh/only-export-components
export const useAuth = () => useContext(AuthContext)
