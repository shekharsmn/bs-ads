import { createContext, useCallback, useContext, useEffect, useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useLocation, useSearchParams } from 'react-router-dom'
import { api } from '../api/client'
import { useAuth } from './AuthContext'

const WorkspaceContext = createContext(null)

const formatDate = date => date.toISOString().slice(0, 10)

function defaultDates() {
  const end = new Date()
  const start = new Date()
  start.setDate(end.getDate() - 29)
  return { start_date: formatDate(start), end_date: formatDate(end), range: '30d' }
}

export function WorkspaceProvider({ children }) {
  const { user } = useAuth()
  const location = useLocation()
  const [params, setParams] = useSearchParams()
  const storageKey = user ? `pulse:workspace:${user.id}:v1` : null
  const stored = useMemo(() => {
    if (!storageKey) return {}
    try { return JSON.parse(localStorage.getItem(storageKey) || '{}') } catch { return {} }
  }, [storageKey])
  const dates = defaultDates()
  const clients = useQuery({ queryKey: ['clients'], queryFn: () => api.getClients().then(r => r.data), enabled: Boolean(user) })
  const clientId = params.get('client_id') || stored.client_id || (clients.data?.[0]?.id ? String(clients.data[0].id) : '')
  const range = params.get('range') || stored.range || dates.range
  const startDate = params.get('start_date') || stored.start_date || dates.start_date
  const endDate = params.get('end_date') || stored.end_date || dates.end_date
  const isResearch = location.pathname.startsWith('/ad-library')

  useEffect(() => {
    if (!storageKey || !clientId) return
    localStorage.setItem(storageKey, JSON.stringify({ client_id: clientId, range, start_date: startDate, end_date: endDate }))
  }, [clientId, endDate, range, startDate, storageKey])

  const updateContext = useCallback(changes => {
    setParams(current => {
      const next = new URLSearchParams(current)
      Object.entries(changes).forEach(([key, value]) => value ? next.set(key, value) : next.delete(key))
      return next
    }, { replace: true })
  }, [setParams])

  const value = {
    clients: clients.data || [], clientId, range, startDate, endDate, isResearch,
    loadingClients: clients.isLoading, updateContext,
    selectedClient: clients.data?.find(client => String(client.id) === String(clientId)),
  }

  return <WorkspaceContext.Provider value={value}>{children}</WorkspaceContext.Provider>
}

// eslint-disable-next-line react-refresh/only-export-components
export const useWorkspace = () => useContext(WorkspaceContext)
