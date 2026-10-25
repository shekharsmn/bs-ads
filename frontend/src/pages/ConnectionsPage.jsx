import { useCallback, useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { BookOpen, Cable, Check, ChevronDown, ExternalLink, KeyRound, Plus, RefreshCw, Search, ShieldCheck, Trash2, X } from 'lucide-react'
import { api } from '../api/client'
import ConfirmDialog from '../components/ui/ConfirmDialog'
import { AsyncStateNotice } from '../components/ui/AsyncState'
import { useAuth } from '../context/AuthContext'
import { discoveryErrorMessage } from '../lib/connectionErrors'
import { Page } from './ClientsPage'
import { useWorkspace } from '../context/WorkspaceContext'

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
  const { clientId: workspaceClientId } = useWorkspace()
  const qc = useQueryClient()
  const oauthDiscoveryStarted = useRef(false)
  const [showConnect, setShowConnect] = useState(false)
  const [form, setForm] = useState(emptyForm)
  const [activeConnectionId, setActiveConnectionId] = useState(null)
  const [discovered, setDiscovered] = useState({})
  const [selection, setSelection] = useState({})
  const [busyConnectionId, setBusyConnectionId] = useState(null)
  const [pendingDelete, setPendingDelete] = useState(null)
  const [error, setError] = useState('')
  const [warning, setWarning] = useState('')

  const clients = useQuery({ queryKey: ['clients'], queryFn: () => api.getClients().then(response => response.data) })
  const connections = useQuery({ queryKey: ['connections'], queryFn: () => api.getConnections().then(response => response.data) })
  const clientList = clients.data || []
  const hasClients = clientList.length > 0

  const initializeSelection = useCallback((connection, accounts) => {
    const fallbackClientId = connection.client_id || workspaceClientId || clients.data?.[0]?.id || ''
    setSelection(Object.fromEntries(accounts.filter(account => account.selectable).map(account => [account.external_id, {
      selected: false,
      client_id: account.assigned_client?.id || fallbackClientId,
    }])))
  }, [clients.data, workspaceClientId])

  const discover = useCallback(async connection => {
    setError('')
    setWarning('')
    setBusyConnectionId(connection.id)
    try {
      const response = await api.discoverAccounts(connection.id)
      const accounts = response.data.accounts || []
      const warnings = response.data.warnings || []
      setDiscovered(current => ({ ...current, [connection.id]: accounts }))
      setActiveConnectionId(connection.id)
      setWarning(warnings.join(' '))
      initializeSelection(connection, accounts)
      await qc.invalidateQueries({ queryKey: ['connections'] })
    } catch (requestError) {
      setActiveConnectionId(current => current === connection.id ? null : current)
      setError(discoveryErrorMessage(requestError))
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

  const remove = useMutation({
    mutationFn: connection => api.deleteConnection(connection.id),
    onSuccess: async (_, connection) => {
      setPendingDelete(null)
      setError('')
      setWarning('')
      setDiscovered(current => {
        const next = { ...current }
        delete next[connection.id]
        return next
      })
      if (activeConnectionId === connection.id) setActiveConnectionId(null)
      await qc.invalidateQueries({ queryKey: ['connections'] })
    },
    onError: requestError => setError(requestError.response?.data?.message || 'Unable to remove the connection.'),
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
    <AsyncStateNotice queries={[clients, connections]}/>
    <div className="ops-toolbar">
      {user?.role === 'admin' && !showConnect && <button className="ops-primary" onClick={() => setShowConnect(true)}><Plus size={15}/> Connect platform</button>}
    </div>

    {showConnect && <form className="connection-setup" onSubmit={connect}>
      <div className="connection-step"><b>1</b><span><strong>Platform</strong><small>Choose the ad network.</small></span></div>
      <div className="connection-fields two">
        <Field label="Platform"><select value={form.platform} onChange={event => setForm({ ...form, platform: event.target.value })}><option value="google">Google Ads</option><option value="meta">Meta Ads</option></select></Field>
        <Field label="Connection name"><input value={form.name} onChange={event => setForm({ ...form, name: event.target.value })} placeholder={form.platform === 'google' ? 'Brandshark Google Ads' : 'Brandshark Meta Ads'}/></Field>
      </div>
      <div className="connection-step"><b>2</b><span><strong>Assignment scope</strong><small>This controls where the connection can be used inside this app. Google permissions are unchanged.</small></span></div>
      <div className="connection-fields two">
        <Field label="Use connection for"><select value={form.scope} onChange={event => setForm({ ...form, scope: event.target.value })}><option value="agency">Multiple clients (manager / agency)</option><option value="client">One client only</option></select></Field>
        {form.scope === 'client' && <Field label="Client"><select required value={form.client_id} onChange={event => setForm({ ...form, client_id: event.target.value })}><option value="">Choose client</option>{clientList.map(client => <option key={client.id} value={client.id}>{client.name}</option>)}</select></Field>}
      </div>
      <div className="connection-step"><b>3</b><span><strong>Authorize</strong><small>{form.platform === 'google' ? 'Continue securely with Google OAuth.' : 'Enter a Meta system-user token with ads_read access.'}</small></span></div>
      <ConnectionSetupGuide key={form.platform} platform={form.platform}/>
      {form.platform === 'meta' && <div className="connection-fields three">
        <Field label="Access token" hint="Required · paste the token exactly as Meta shows it"><input required type="password" autoComplete="off" value={form.access_token} onChange={event => setForm({ ...form, access_token: event.target.value })} placeholder="EAAB…"/></Field>
        <Field label="App ID" hint="Optional · App settings → Basic"><input value={form.app_id} onChange={event => setForm({ ...form, app_id: event.target.value })} placeholder="1234567890"/></Field>
        <Field label="App secret" hint="Optional · stored encrypted"><input type="password" autoComplete="new-password" value={form.app_secret} onChange={event => setForm({ ...form, app_secret: event.target.value })} placeholder="••••••••••••"/></Field>
      </div>}
      <div className="connection-actions"><button type="button" onClick={() => { setShowConnect(false); setForm(emptyForm) }}><X size={14}/> Cancel</button><button className="ops-primary" disabled={createMeta.isPending}><KeyRound size={14}/>{form.platform === 'google' ? 'Continue with Google' : createMeta.isPending ? 'Connecting…' : 'Connect Meta'}</button></div>
    </form>}

    {error && <div className="connection-error">{error}</div>}
    {warning && <div className="connection-warning">{warning}</div>}

    <div className="connection-list">{(connections.data || []).map(connection => {
      const accounts = discovered[connection.id]
      const isOpen = activeConnectionId === connection.id
      const assignableClients = connection.scope === 'client' ? clientList.filter(client => client.id === connection.client_id) : clientList
      return <section className={`connection-source ${isOpen ? 'open' : ''}`} key={connection.id}>
        <div className="connection-source-row">
          <span className={`connection-platform ${connection.platform}`}><Cable size={15}/></span>
          <div><strong>{connection.name}</strong><small>{connection.platform === 'google' ? 'Google Ads' : 'Meta Ads'} · {connection.scope === 'agency' ? 'agency access' : connection.client?.name || 'client access'}{connection.last_synced_at ? ` · checked ${new Date(connection.last_synced_at).toLocaleString()}` : ' · not checked yet'}</small>{connection.status === 'error' && <em className="connection-guidance">Access needs attention. Re-authorize if discovery continues to fail.</em>}</div>
          <span className={`connection-health ${connection.status}`}>{connection.status}</span>
          <span>{connection.ad_accounts_count} assigned</span>
          {user?.role === 'admin' && <div className="connection-row-actions"><button className="connection-manage" onClick={() => isOpen && accounts ? setActiveConnectionId(null) : discover(connection)} disabled={busyConnectionId === connection.id || remove.isPending}>{busyConnectionId === connection.id ? <RefreshCw className="spin" size={14}/> : accounts && isOpen ? <ChevronDown size={14}/> : connection.status === 'error' ? <RefreshCw size={14}/> : <Search size={14}/>} {busyConnectionId === connection.id ? 'Discovering…' : isOpen ? 'Close' : connection.status === 'error' ? 'Retry connection' : 'Discover accounts'}</button><button className="connection-remove" aria-label={`Remove ${connection.name}`} title="Remove connection" onClick={() => setPendingDelete(connection)} disabled={remove.isPending}><Trash2 size={14}/></button></div>}
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
              <select disabled={!hasClients || connection.scope === 'client'} aria-label={`Client for ${account.name}`} value={selection[account.external_id]?.client_id || ''} onChange={event => updateSelection(account.external_id, { client_id: event.target.value })}>{assignableClients.map(client => <option key={client.id} value={client.id}>{client.name}</option>)}</select>
            </label>
          )}</div>
        </div>}
      </section>
    })}</div>
    <ConfirmDialog open={Boolean(pendingDelete)} title={`Remove ${pendingDelete?.name || 'connection'}?`} description={`This permanently removes the connection${pendingDelete?.ad_accounts_count ? `, its ${pendingDelete.ad_accounts_count} assigned account${pendingDelete.ad_accounts_count === 1 ? '' : 's'}, and their collected operational data` : ''}. It does not delete anything from ${pendingDelete?.platform === 'meta' ? 'Meta Ads' : 'Google Ads'}.`} confirmLabel="Remove connection" busy={remove.isPending} onClose={() => setPendingDelete(null)} onConfirm={() => remove.mutate(pendingDelete)}/>
  </Page>
}

export function ConnectionSetupGuide({ platform }) {
  const isGoogle = platform === 'google'

  return <aside className={`connection-setup-guide ${platform}`} aria-label={`${isGoogle ? 'Google Ads' : 'Meta Ads'} connection instructions`}>
    <div className="connection-guide-intro">
      <span className="connection-guide-icon">{isGoogle ? <ShieldCheck size={17}/> : <BookOpen size={17}/>}</span>
      <div>
        <strong>{isGoogle ? 'Use a Google login with Ads access' : 'Prepare a Meta System User token'}</strong>
        <p>{isGoogle
          ? 'The accounts discovered here are the accounts that the Google user can already access.'
          : 'Use a dedicated, read-only token so reporting access is not tied to a person’s login.'}</p>
      </div>
      <span className="connection-guide-time">{isGoogle ? '2–3 min' : '5–10 min'}</span>
    </div>

    <div className="connection-guide-checks">
      <span><Check size={13}/>{isGoogle ? 'Access to the Google Ads manager or client account' : 'Admin access to the Meta business portfolio'}</span>
      <span><Check size={13}/>{isGoogle ? 'The correct Google email ready for sign-in' : 'A Meta app owned by the same business portfolio'}</span>
    </div>

    <details open>
      <summary>Setup steps <ChevronDown size={14}/></summary>
      <ol>
        {isGoogle ? <>
          <li><b>Check access.</b> In Google Ads, the email must be listed under <em>Admin → Access and security</em> for the manager or client account.</li>
          <li><b>Continue with Google.</b> Choose that email and approve Google Ads access. No Google password is shared with this app.</li>
          <li><b>Assign accounts.</b> After returning here, select the discovered client accounts and assign each one to a client. Manager accounts appear only to show the hierarchy.</li>
        </> : <>
          <li><b>Create or select a System User.</b> Open Meta Business settings, then go to <em>Users → System users</em>.</li>
          <li><b>Assign ad accounts.</b> Choose <em>Assign assets → Ad accounts</em> and grant <em>View performance</em> for every account you want to import.</li>
          <li><b>Generate the token.</b> Choose your Meta app, include <code>ads_read</code>, select an appropriate expiration, and copy the token when Meta displays it.</li>
          <li><b>Connect and assign.</b> Paste the token here. After discovery, select each ad account and assign it to a client.</li>
        </>}
      </ol>
      <a href={isGoogle ? 'https://developers.google.com/google-ads/api/docs/oauth/overview' : 'https://business.facebook.com/settings/system-users'} target="_blank" rel="noreferrer">
        {isGoogle ? 'Google Ads access guide' : 'Open Meta Business settings'} <ExternalLink size={12}/>
      </a>
    </details>
  </aside>
}

function Field({ label, hint, children }) { return <label><span>{label}</span>{children}{hint && <small className="connection-field-hint">{hint}</small>}</label> }
