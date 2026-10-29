import { useEffect, useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '../api/client'

export default function ScopeFilterBar({ clientId = '', accountId = '', onClientChange, onAccountChange, onScopeChange, children, compact = false }) {
  const clients = useQuery({ queryKey: ['clients'], queryFn: () => api.getClients().then(response => response.data) })
  const clientList = useMemo(() => clients.data || [], [clients.data])
  const accounts = useMemo(() => clientList
    .filter(client => !clientId || String(client.id) === String(clientId))
    .flatMap(client => (client.ad_accounts || []).map(account => ({ ...account, client_name: client.name, client_id: client.id }))), [clientList, clientId])

  useEffect(() => {
    if (accountId && clients.isSuccess && !accounts.some(account => String(account.id) === String(accountId))) onAccountChange?.('')
  }, [accountId, accounts, clients.isSuccess, onAccountChange])

  return <div className={`scope-filter-bar ${compact ? 'compact' : ''}`}>
    <select aria-label="Filter by client" value={clientId} onChange={event => {
      const nextClientId = event.target.value
      if (onScopeChange) onScopeChange(nextClientId, '')
      else onClientChange?.(nextClientId)
    }}>
      <option value="">All clients</option>
      {clientList.map(client => <option key={client.id} value={client.id}>{client.name}</option>)}
    </select>
    <select aria-label="Filter by ad account" value={accountId} onChange={event => onAccountChange?.(event.target.value)}>
      <option value="">All ad accounts</option>
      {accounts.map(account => <option key={account.id} value={account.id}>{clientId ? account.name : `${account.client_name} · ${account.name}`}</option>)}
    </select>
    {children}
  </div>
}

