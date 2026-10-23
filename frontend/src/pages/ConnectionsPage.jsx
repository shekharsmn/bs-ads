import { useCallback, useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Cable, Check, ChevronDown, KeyRound, Plus, RefreshCw, Search, X } from 'lucide-react'
import { api } from '../api/client'
import { useAuth } from '../context/AuthContext'
import { Page } from './ClientsPage'

const emptyForm = {
  platform: 'google',
  name: '',
  scope: 'agency',
  client_id: '',
  access_token: '',
  app_id: '',
  app_secret: '',
}

export default function ConnectionsPage() {
  const { user } = useAuth()
  const qc = useQueryClient()
  const oauthDiscoveryStarted = useRef(false)
  const [showConnect, setShowConnect] = useState(false)
  const [form, setForm] = useState(emptyForm)
  const [activeConnectionId, setActiveConnectionId] = useState(null)
  const [discovered, setDiscovered] = useState({})
  const [selection, setSelection] = useState({})
  const [busyConnectionId, setBusyConnectionId] = useState(null)
  const [error, setError] = useState('')

  const clients = useQuery({ queryKey: ['clients'], queryFn: () => api.getClients().then(response => response.data) })
  const connections = useQuery({ queryKey: ['connections'], queryFn: () => api.getConnections().then(response => response.data) })
  const clientList = clients.data || []
  const hasClients = clientList.length > 0

  const initializeSelection = useCallback((connection, accounts) => {
    const fallbackClientId = connection.client_id || clients.data?.[0]?.id || ''
    setSelection(Object.fromEntries(accounts.filter(account => account.selectable).map(account => [account.external_id, {
      selected: false,
      client_id: account.assigned_client?.id || fallbackClientId,
    }])))
  }, [clients.data])

  const discover = useCallback(async connection => {
    setError('')
    setBusyConnectionId(connection.id)
    setActiveConnectionId(connection.id)
    try {
      const response = await api.discoverAccounts(connection.id)
      const accounts = response.data.accounts || []
      setDiscovered(current => ({ ...current, [connection.id]: accounts }))
      initializeSelection(connection, accounts)
      await qc.invalidateQueries({ queryKey: ['connections'] })
    } catch (requestError) {
      setError(requestError.response?.data?.message || requestError.response?.data?.error || 'Account discovery failed.')
    } finally {
      setBusyConnectionId(null)
    }
  }, [initializeSelection, qc])

  useEffect(() => {
    const params = new URLSearchParams(window.location.search)
    const connectionId = Number(params.get('connection_id'))
    const oauthError = params.get('error')
    if (oauthError) setError(oauthError)
    if (!connectionId || oauthDiscoveryStarted.current || !connections.data?.length) return

    const connection = connections.data.find(item => item.id === connectionId)
    if (!connection) return
    oauthDiscoveryStarted.current = true
    window.history.replaceState({}, '', '/connections')
    discover(connection)
  }, [connections.data, discover])

  const createMeta = useMutation({
    mutationFn: async () => {
      const response = await api.createConnection({
        platform: 'meta',
        name: form.name || 'Meta Ads',
        scope: form.scope,
        client_id: form.scope === 'client' ? Number(form.client_id) : null,
        manager_external_id: null,
        credentials: {
          access_token: form.access_token,
          app_id: form.app_id || null,
          app_secret: form.app_secret || null,
        },
      })
      return response.data
    },
    onSuccess: async connection => {
      setShowConnect(false)
      setForm(emptyForm)
      await qc.invalidateQueries({ queryKey: ['connections'] })
      await discover(connection)
    },
    onError: requestError => setError(requestError.response?.data?.message || 'Could not save the Meta connection.'),
  })

  const assign = useMutation({
    mutationFn: async connection => {
      const accounts = discovered[connection.id] || []
      const payload = accounts.filter(account => selection[account.external_id]?.selected).map(account => {
        const clientId = Number(selection[account.external_id].client_id)
        const client = clients.data?.find(item => item.id === clientId)
        return {
          client_id: clientId,
          external_id: account.external_id,
          manager_external_id: account.manager_external_id || null,
          name: account.name,
          currency: account.currency || client?.currency,
          timezone: account.timezone || client?.timezone,
        }
      })
      return api.assignDiscoveredAccounts(connection.id, payload)
    },
    onSuccess: async (_, connection) => {
      await qc.invalidateQueries({ queryKey: ['connections'] })
      await discover(connection)
    },
    onError: requestError => setError(requestError.response?.data?.message || 'Could not assign the selected accounts.'),
  })

  const connect = event => {
    event.preventDefault()
    setError('')
    if (form.scope === 'client' && !form.client_id) {
      setError('Choose the client that owns this connection.')
      return
    }
    if (form.platform === 'google') {
      window.location.href = api.googleConnectionUrl({ name: form.name || 'Google Ads', scope: form.scope, client_id: form.client_id })
      return
    }
    createMeta.mutate()
  }

  const updateSelection = (externalId, patch) => {
    setSelection(current => ({ ...current, [externalId]: { ...current[externalId], ...patch } }))
  }

  const selectedCount = Object.values(selection).filter(item => item.selected).length

  return <Page title="Ad platforms" subtitle="Connect once, discover accessible ad accounts, then assign them to clients.">
    <div className="ops-toolbar">
      {user?.role === 'admin' && !showConnect && <button className="ops-primary" onClick={() => setShowConnect(true)}><Plus size={15}/> Connect platform</button>}
    </div>

    {showConnect && <form className="connection-setup" onSubmit={connect}>
      <div className="connection-step"><b>1</b><span><strong>Platform</strong><small>Choose the ad network.</small></span></div>
      <div className="connection-fields two">
        <Field label="Platform"><select value={form.platform} onChange={event => setForm({ ...form, platform: event.target.value })}><option value="google">Google Ads</option><option value="meta">Meta Ads</option></select></Field>
        <Field label="Connection name"><input value={form.name} onChange={event => setForm({ ...form, name: event.target.value })} placeholder={form.platform === 'google' ? 'Brandshark Google Ads' : 'Brandshark Meta Ads'}/></Field>
      </div>
      <div className="connection-step"><b>2</b><span><strong>Access scope</strong><small>Agency connections can serve several clients.</small></span></div>
      <div className="connection-fields two">
        <Field label="Scope"><select value={form.scope} onChange={event => setForm({ ...form, scope: event.target.value })}><option value="agency">Agency manager / business</option><option value="client">Client-specific</option></select></Field>
        {form.scope === 'client' && <Field label="Client"><select required value={form.client_id} onChange={event => setForm({ ...form, client_id: event.target.value })}><option value="">Choose client</option>{clientList.map(client => <option key={client.id} value={client.id}>{client.name}</option>)}</select></Field>}
      </div>
      <div className="connection-step"><b>3</b><span><strong>Authorize</strong><small>{form.platform === 'google' ? 'Continue securely with Google OAuth.' : 'Enter a Meta system-user token with ads_read access.'}</small></span></div>
      {form.platform === 'meta' && <div className="connection-fields three">
        <Field label="Access token"><input required type="password" value={form.access_token} onChange={event => setForm({ ...form, access_token: event.target.value })}/></Field>
        <Field label="App ID"><input value={form.app_id} onChange={event => setForm({ ...form, app_id: event.target.value })}/></Field>
        <Field label="App secret"><input type="password" value={form.app_secret} onChange={event => setForm({ ...form, app_secret: event.target.value })}/></Field>
      </div>}
      <div className="connection-actions"><button type="button" onClick={() => { setShowConnect(false); setForm(emptyForm) }}><X size={14}/> Cancel</button><button className="ops-primary" disabled={createMeta.isPending}><KeyRound size={14}/>{form.platform === 'google' ? 'Continue with Google' : createMeta.isPending ? 'Connecting…' : 'Connect Meta'}</button></div>
    </form>}

    {error && <div className="connection-error">{error}</div>}

    <div className="connection-list">{(connections.data || []).map(connection => {
      const accounts = discovered[connection.id]
      const isOpen = activeConnectionId === connection.id
      return <section className={`connection-source ${isOpen ? 'open' : ''}`} key={connection.id}>
        <div className="connection-source-row">
          <span className={`connection-platform ${connection.platform}`}><Cable size={15}/></span>
          <div><strong>{connection.name}</strong><small>{connection.platform === 'google' ? 'Google Ads' : 'Meta Ads'} · {connection.scope === 'agency' ? 'agency access' : connection.client?.name || 'client access'}</small></div>
          <span className={`connection-health ${connection.status}`}>{connection.status}</span>
          <span>{connection.ad_accounts_count} assigned</span>
          {user?.role === 'admin' && <button className="connection-manage" onClick={() => isOpen && accounts ? setActiveConnectionId(null) : discover(connection)} disabled={busyConnectionId === connection.id}>{busyConnectionId === connection.id ? <RefreshCw className="spin" size={14}/> : accounts && isOpen ? <ChevronDown size={14}/> : <Search size={14}/>} {isOpen ? 'Close' : 'Discover accounts'}</button>}
        </div>

        {connection.ad_accounts?.length > 0 && <div className="assigned-strip">{connection.ad_accounts.map(account => <span key={account.id}><i data-platform={connection.platform}/>{account.name}<small>{account.client?.name}</small></span>)}</div>}

        {isOpen && accounts && <div className="account-picker">
          <div className="account-picker-head"><div><strong>Accessible accounts</strong><small>{connection.platform === 'google' ? 'Manager accounts organize the hierarchy; select client accounts to import.' : 'Select the ad accounts to import.'}</small></div><button className="ops-primary" disabled={!hasClients || !selectedCount || assign.isPending} onClick={() => assign.mutate(connection)}><Check size={14}/> Assign {selectedCount || ''}</button></div>
          {!hasClients && <div className="account-picker-empty">Create a client before assigning discovered ad accounts.</div>}
          <div className="account-picker-list">{accounts.map(account => account.is_manager
            ? <div className="manager-account" key={account.external_id} style={{ paddingLeft: `${12 + account.level * 18}px` }}><span>Manager</span><strong>{account.name}</strong><small>{account.external_id}</small></div>
            : <label className="discovered-account" key={account.external_id} style={{ paddingLeft: `${12 + account.level * 18}px` }}>
              <input type="checkbox" disabled={!hasClients} checked={Boolean(selection[account.external_id]?.selected)} onChange={event => updateSelection(account.external_id, { selected: event.target.checked })}/>
              <span><strong>{account.name}</strong><small>{account.external_id} · {account.currency || 'uses client currency'} · {account.timezone || 'uses client timezone'}{account.assigned_client ? ` · assigned to ${account.assigned_client.name}` : ''}</small></span>
              <select disabled={!hasClients} aria-label={`Client for ${account.name}`} value={selection[account.external_id]?.client_id || ''} onChange={event => updateSelection(account.external_id, { client_id: event.target.value })}>{clientList.map(client => <option key={client.id} value={client.id}>{client.name}</option>)}</select>
            </label>
          )}</div>
        </div>}
      </section>
    })}</div>
  </Page>
}

function Field({ label, children }) { return <label><span>{label}</span>{children}</label> }
