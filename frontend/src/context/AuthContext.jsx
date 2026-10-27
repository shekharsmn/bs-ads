import { createContext, useContext, useState, useEffect } from 'react'
import { api } from '../api/client'

const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  const isPublicShare = window.location.pathname === '/privacy-policy' || window.location.pathname.startsWith('/share/') || window.location.pathname.startsWith('/shared/')
  const [user, setUser] = useState(null)
  const [permissions, setPermissions] = useState({})
  const [features, setFeatures] = useState({})
  const [loading, setLoading] = useState(!isPublicShare)

  useEffect(() => {
    if (isPublicShare) return

    api.me()
      .then(res => {
        setUser(res.data.user)
        setPermissions(res.data.permissions || {})
        setFeatures(res.data.features || {})
      })
      .catch(() => setUser(null))
      .finally(() => setLoading(false))
  }, [isPublicShare])

  const logout = async () => {
    await api.logout()
    setUser(null)
    setPermissions({})
    setFeatures({})
  }

  return (
    <AuthContext.Provider value={{ user, permissions, features, loading, logout }}>
      {children}
    </AuthContext.Provider>
  )
}

// eslint-disable-next-line react-refresh/only-export-components
export const useAuth = () => useContext(AuthContext)
