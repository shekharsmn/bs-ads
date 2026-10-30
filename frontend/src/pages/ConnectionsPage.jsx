import { useCallback, useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { ArrowLeft, ArrowRight, BookOpen, Cable, Check, ChevronDown, Eye, EyeOff, ExternalLink, KeyRound, Pencil, Plus, RefreshCw, RotateCcw, Search, ShieldCheck, Trash2, X } from 'lucide-react'
import { api } from '../api/client'
import ConfirmDialog from '../components/ui/ConfirmDialog'
import { AsyncStateNotice } from '../components/ui/AsyncState'
import ScopeFilterBar from '../components/ScopeFilterBar'
import { useAuth } from '../context/AuthContext'
import { buildAccountAssignmentPayload, hasCompleteAccountAssignments } from '../lib/accountAssignments'
import { discoveryErrorMessage } from '../lib/connectionErrors'
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
  const [search, setSearch] = useSearchParams()
  const oauthDiscoveryStarted = useRef(false)
  const [showConnect, setShowConnect] = useState(false)
  const [setupStep, setSetupStep] = useState(1)
  const [showToken, setShowToken] = useState(false)
  const [metaChecks, setMetaChecks] = useState({ systemUser: false, assets: false, permission: false })
  const [form, setForm] = useState(emptyForm)
  const [activeConnectionId, setActiveConnectionId] = useState(null)
  const [discovered, setDiscovered] = useState({})
  const [selection, setSelection] = useState({})
  const [busyConnectionId, setBusyConnectionId] = useState(null)
  const [pendingDelete, setPendingDelete] = useState(null)
  const [editingConnection, setEditingConnection] = useState(null)
  const [editMode, setEditMode] = useState('edit')
  const [editForm, setEditForm] = useState({ name: '', scope: 'agency', client_id: '', access_token: '', app_id: '', app_secret: '' })
  const [error, setError] = useState('')
  const [warning, setWarning] = useState('')

  const clients = useQuery({ queryKey: ['clients'], queryFn: () => api.getClients().then(response => response.data) })
  const filters = { client_id: search.get('client_id') || undefined, ad_account_id: search.get('ad_account_id') || undefined, platform: search.get('platform') || undefined, status: search.get('status') || undefined }
  const connections = useQuery({ queryKey: ['connections', filters], queryFn: () => api.getConnections(filters).then(response => response.data) })
  const clientList = clients.data || []
  const hasClients = clientList.length > 0
  const setFilter = (key, value) => setSearch(current => { const next = new URLSearchParams(current); value ? next.set(key, value) : next.delete(key); return next }, { replace: true })
  const setScope = (clientId, accountId) => { const next = new URLSearchParams(search); clientId ? next.set('client_id', clientId) : next.delete('client_id'); accountId ? next.set('ad_account_id', accountId) : next.delete('ad_account_id'); setSearch(next, { replace: true }) }

  const initializeSelection = useCallback((connection, accounts) => {
    const fallbackClientId = connection.client_id || ''
    setSelection(Object.fromEntries(accounts.filter(account => account.selectable).map(account => [account.external_id, {
      selected: false,
      client_id: account.assigned_client?.id || fallbackClientId,
    }])))
  }, [])

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
      setSetupStep(1)
      setShowToken(false)
      setMetaChecks({ systemUser: false, assets: false, permission: false })
      setForm(emptyForm)
      await qc.invalidateQueries({ queryKey: ['connections'] })
      await discover(connection)
    },
    onError: requestError => setError(requestError.response?.data?.message || 'Could not save the Meta connection.'),
  })

  const assign = useMutation({
    mutationFn: async connection => {
      const accounts = discovered[connection.id] || []
      const payload = buildAccountAssignmentPayload(accounts, selection, clientList)
      return api.assignDiscoveredAccounts(connection.id, payload)
    },
    onSuccess: async (_, connection) => {
      await qc.invalidateQueries({ queryKey: ['connections'] })
      await discover(connection)
    },
    onError: requestError => setError(requestError.response?.data?.message || requestError.message || 'Could not assign the selected accounts.'),
  })

  const remove = useMutation({
    mutationFn: connection => api.deleteConnection(connection.id),
    onSuccess: async (_, connection) => {
      setPendingDelete(null)
      if (editingConnection?.id === connection.id) setEditingConnection(null)
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

  const update = useMutation({
    mutationFn: ({ connection, data }) => api.updateConnection(connection.id, data),
    onSuccess: async (response, { reconnect: shouldDiscover }) => {
      setEditingConnection(null)
      setError('')
      await qc.invalidateQueries({ queryKey: ['connections'] })
      if (shouldDiscover) await discover(response.data)
    },
    onError: requestError => setError(requestError.response?.data?.message || 'Unable to update the connection.'),
  })

  const openConnectionEditor = (connection, mode = 'edit') => {
    setEditingConnection(connection)
    setEditMode(mode)
    setEditForm({ name: connection.name, scope: connection.scope, client_id: connection.client_id || '', access_token: '', app_id: '', app_secret: '' })
    setShowToken(false)
    setError('')
  }

  const reconnect = connection => {
    if (connection.platform === 'google') {
      window.location.href = api.googleConnectionUrl({ connection_id: connection.id, name: connection.name, scope: connection.scope, client_id: connection.client_id })
      return
    }
    openConnectionEditor(connection, 'reconnect')
  }

  const saveConnection = event => {
    event.preventDefault()
    if (editForm.scope === 'client' && !editForm.client_id) {
      setError('Choose the client that owns this connection.')
      return
    }
    if (editMode === 'reconnect' && editForm.access_token.trim().length < 20) {
      setError('Paste the complete Meta System User access token before reconnecting.')
      return
    }
    const data = { name: editForm.name, scope: editForm.scope, client_id: editForm.scope === 'client' ? Number(editForm.client_id) : null }
    if (editMode === 'reconnect') data.credentials = { access_token: editForm.access_token, app_id: editForm.app_id || null, app_secret: editForm.app_secret || null }
    update.mutate({ connection: editingConnection, data, reconnect: editMode === 'reconnect' })
  }

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

  const closeSetup = () => {
    setShowConnect(false)
    setSetupStep(1)
    setShowToken(false)
    setMetaChecks({ systemUser: false, assets: false, permission: false })
    setForm(emptyForm)
    setError('')
  }

  const nextSetupStep = () => {
    setError('')
    if (setupStep === 1 && form.scope === 'client' && !form.client_id) {
      setError('Choose the client that owns this connection before continuing.')
      return
    }
    if (setupStep === 2 && form.platform === 'meta') {
      if (!Object.values(metaChecks).every(Boolean)) {
        setError('Complete the Meta readiness checklist before continuing.')
        return
      }
      if (form.access_token.trim().length < 20) {
        setError('Paste the complete Meta System User access token before continuing.')
        return
      }
    }
    setSetupStep(current => Math.min(3, current + 1))
  }

  const updateSelection = (externalId, patch) => {
    setSelection(current => ({ ...current, [externalId]: { ...current[externalId], ...patch } }))
  }

  const selectedCount = Object.values(selection).filter(item => item.selected).length
  const assignmentsComplete = hasCompleteAccountAssignments(selection, clientList)

  return <Page title="Ad platforms" subtitle="Connect once, discover accessible ad accounts, then assign them to clients.">
    <AsyncStateNotice queries={[clients, connections]}/>
    <ScopeFilterBar clientId={search.get('client_id') || ''} accountId={search.get('ad_account_id') || ''} onScopeChange={setScope} onAccountChange={value => setFilter('ad_account_id', value)}><select value={search.get('platform') || ''} onChange={event => setFilter('platform', event.target.value)}><option value="">All platforms</option><option value="google">Google Ads</option><option value="meta">Meta</option></select><select value={search.get('status') || ''} onChange={event => setFilter('status', event.target.value)}><option value="">All statuses</option><option value="healthy">Healthy</option><option value="pending">Pending</option><option value="error">Error</option><option value="disconnected">Disconnected</option></select></ScopeFilterBar>
    <div className="ops-toolbar">
      {user?.role === 'admin' && !showConnect && <button className="ops-primary" onClick={() => { setShowConnect(true); setSetupStep(1); setError('') }}><Plus size={15}/> Connect platform</button>}
    </div>

    {showConnect && <form className="connection-setup" onSubmit={connect}>
      <div className="connection-wizard-head">
        <div><strong>Add an ad platform</strong><p>{form.platform === 'meta' ? 'Set up secure Meta reporting access, then discover and assign ad accounts.' : 'Authorize Google Ads, then discover and assign accessible accounts.'}</p></div>
        <button type="button" aria-label="Close connection setup" onClick={closeSetup}><X size={16}/></button>
      </div>
      <ol className="connection-progress" aria-label="Connection setup progress">
        {['Connection details', 'Authorize access', 'Review and connect'].map((label, index) => <li key={label} className={setupStep === index + 1 ? 'active' : setupStep > index + 1 ? 'complete' : ''}><span>{setupStep > index + 1 ? <Check size={12}/> : index + 1}</span><b>{label}</b></li>)}
      </ol>

      {setupStep === 1 && <div className="connection-wizard-body">
        <div className="connection-step"><b>1</b><span><strong>Choose the platform and scope</strong><small>The scope controls which clients can use this connection inside the app.</small></span></div>
        <div className="connection-fields two">
          <Field label="Platform" hint="Meta uses a System User token; Google uses OAuth."><select value={form.platform} onChange={event => { setForm({ ...form, platform: event.target.value, access_token: '', app_id: '', app_secret: '' }); setMetaChecks({ systemUser: false, assets: false, permission: false }) }}><option value="google">Google Ads</option><option value="meta">Meta Ads</option></select></Field>
          <Field label="Connection name" hint="Use a name your team will recognize later."><input value={form.name} onChange={event => setForm({ ...form, name: event.target.value })} placeholder={form.platform === 'google' ? 'SharkAds Google Ads' : 'SharkAds Meta Ads'}/></Field>
          <Field label="Use connection for" hint={form.scope === 'agency' ? 'Recommended when one business portfolio manages several clients.' : 'Limits account assignment to one client.'}><select value={form.scope} onChange={event => setForm({ ...form, scope: event.target.value, client_id: event.target.value === 'agency' ? '' : form.client_id })}><option value="agency">Multiple clients (agency)</option><option value="client">One client only</option></select></Field>
          {form.scope === 'client' && <Field label="Client" hint="Only this client can receive accounts from the connection."><select required value={form.client_id} onChange={event => setForm({ ...form, client_id: event.target.value })}><option value="">Choose client</option>{clientList.map(client => <option key={client.id} value={client.id}>{client.name}</option>)}</select></Field>}
        </div>
      </div>}

      {setupStep === 2 && <div className="connection-wizard-body">
        <div className="connection-step"><b>2</b><span><strong>{form.platform === 'meta' ? 'Prepare and enter Meta access' : 'Authorize Google access'}</strong><small>{form.platform === 'meta' ? 'Complete each prerequisite in Meta Business settings before entering the token.' : 'Use a Google login that already has access to the required Ads accounts.'}</small></span></div>
        <ConnectionSetupGuide key={form.platform} platform={form.platform} checklist={metaChecks} onChecklistChange={(key, checked) => setMetaChecks(current => ({ ...current, [key]: checked }))}/>
        {form.platform === 'meta' && <div className="connection-credential-block">
          <div className="connection-token-row">
            <Field label="System User access token" hint="Required · stored encrypted and never shown again">
              <div className="connection-secret-input"><input required type={showToken ? 'text' : 'password'} autoComplete="off" value={form.access_token} onChange={event => setForm({ ...form, access_token: event.target.value.trim() })} placeholder="EAA…"/><button type="button" aria-label={showToken ? 'Hide access token' : 'Show access token'} onClick={() => setShowToken(current => !current)}>{showToken ? <EyeOff size={15}/> : <Eye size={15}/>}</button></div>
            </Field>
            <span className={`connection-token-state ${form.access_token.trim().length >= 20 ? 'ready' : ''}`}>{form.access_token.trim().length >= 20 ? <><Check size={13}/> Token entered</> : 'Waiting for token'}</span>
          </div>
          <details className="connection-advanced">
            <summary>Advanced app credentials <ChevronDown size={14}/></summary>
            <p>Only add these when your deployment specifically requires app-level credentials.</p>
            <div className="connection-fields two">
              <Field label="App ID" hint="Optional · Meta App dashboard → Settings → Basic"><input value={form.app_id} onChange={event => setForm({ ...form, app_id: event.target.value })} placeholder="1234567890"/></Field>
              <Field label="App secret" hint="Optional · stored encrypted"><input type="password" autoComplete="new-password" value={form.app_secret} onChange={event => setForm({ ...form, app_secret: event.target.value })} placeholder="••••••••••••"/></Field>
            </div>
          </details>
        </div>}
      </div>}

      {setupStep === 3 && <div className="connection-wizard-body">
        <div className="connection-step"><b>3</b><span><strong>Review before connecting</strong><small>No ad accounts are imported until you choose them after discovery.</small></span></div>
        <dl className="connection-review">
          <div><dt>Platform</dt><dd>{form.platform === 'meta' ? 'Meta Ads' : 'Google Ads'}</dd></div>
          <div><dt>Connection</dt><dd>{form.name || (form.platform === 'meta' ? 'Meta Ads' : 'Google Ads')}</dd></div>
          <div><dt>Scope</dt><dd>{form.scope === 'agency' ? 'Multiple clients' : clientList.find(client => String(client.id) === String(form.client_id))?.name || 'One client'}</dd></div>
          <div><dt>Authorization</dt><dd>{form.platform === 'meta' ? 'System User token ready' : 'Google OAuth on next screen'}</dd></div>
        </dl>
        <div className="connection-next-note"><ShieldCheck size={17}/><span><strong>What happens next</strong><small>{form.platform === 'meta' ? 'We will verify the token, list accessible ad accounts, and let you assign each account to a client.' : 'Google will ask you to approve access. After returning, choose which discovered accounts to assign.'}</small></span></div>
      </div>}

      {error && <div className="connection-error in-form" role="alert">{error}</div>}
      <div className="connection-actions">
        <button type="button" onClick={setupStep === 1 ? closeSetup : () => { setError(''); setSetupStep(current => current - 1) }}>{setupStep === 1 ? <X size={14}/> : <ArrowLeft size={14}/>} {setupStep === 1 ? 'Cancel' : 'Back'}</button>
        {setupStep < 3
          ? <button key="next-setup-step" type="button" className="ops-primary" onClick={nextSetupStep}>Continue <ArrowRight size={14}/></button>
          : <button key="submit-connection" type="submit" className="ops-primary" disabled={createMeta.isPending}><KeyRound size={14}/>{form.platform === 'google' ? 'Continue with Google' : createMeta.isPending ? 'Verifying Meta access…' : 'Verify and connect Meta'}</button>}
      </div>
    </form>}

    {!showConnect && !editingConnection && error && <div className="connection-error">{error}</div>}
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
          {user?.role === 'admin' && <div className="connection-row-actions"><button className="connection-manage" onClick={() => isOpen && accounts ? setActiveConnectionId(null) : discover(connection)} disabled={busyConnectionId === connection.id || remove.isPending}>{busyConnectionId === connection.id ? <RefreshCw className="spin" size={14}/> : accounts && isOpen ? <ChevronDown size={14}/> : <Search size={14}/>} {busyConnectionId === connection.id ? 'Discovering…' : isOpen ? 'Close' : 'Accounts'}</button><button className="connection-icon-action" aria-label={`Edit ${connection.name}`} title="Edit connection" onClick={() => openConnectionEditor(connection)} disabled={update.isPending}><Pencil size={14}/></button><button className="connection-icon-action" aria-label={`Reconnect ${connection.name}`} title="Reconnect" onClick={() => reconnect(connection)} disabled={update.isPending}><RotateCcw size={14}/></button><button className="connection-remove" aria-label={`Remove ${connection.name}`} title="Remove connection" onClick={() => setPendingDelete(connection)} disabled={remove.isPending}><Trash2 size={14}/></button></div>}
        </div>

        {editingConnection?.id === connection.id && <form className="connection-editor" onSubmit={saveConnection}>
          <div className="connection-editor-head"><div><strong>{editMode === 'reconnect' ? `Reconnect ${connection.name}` : `Edit ${connection.name}`}</strong><small>{editMode === 'reconnect' ? 'Enter a fresh Meta token. Existing assigned accounts will be kept.' : 'Update the label or who can use this connection.'}</small></div><button type="button" aria-label="Close connection editor" onClick={() => { setEditingConnection(null); setError('') }}><X size={15}/></button></div>
          <div className="connection-fields three">
            <Field label="Connection name"><input required value={editForm.name} onChange={event => setEditForm({ ...editForm, name: event.target.value })}/></Field>
            <Field label="Use connection for"><select value={editForm.scope} onChange={event => setEditForm({ ...editForm, scope: event.target.value, client_id: event.target.value === 'agency' ? '' : editForm.client_id })}><option value="agency">Multiple clients (agency)</option><option value="client">One client only</option></select></Field>
            {editForm.scope === 'client' && <Field label="Client"><select required value={editForm.client_id} onChange={event => setEditForm({ ...editForm, client_id: event.target.value })}><option value="">Choose client</option>{clientList.map(client => <option key={client.id} value={client.id}>{client.name}</option>)}</select></Field>}
          </div>
          {editMode === 'reconnect' && <div className="connection-fields three connection-reconnect-fields"><Field label="New System User access token" hint="Required · verified before the old token is replaced"><div className="connection-secret-input"><input required type={showToken ? 'text' : 'password'} autoComplete="off" value={editForm.access_token} onChange={event => setEditForm({ ...editForm, access_token: event.target.value.trim() })}/><button type="button" aria-label={showToken ? 'Hide access token' : 'Show access token'} onClick={() => setShowToken(current => !current)}>{showToken ? <EyeOff size={15}/> : <Eye size={15}/>}</button></div></Field><Field label="App ID" hint="Optional"><input value={editForm.app_id} onChange={event => setEditForm({ ...editForm, app_id: event.target.value })}/></Field><Field label="App secret" hint="Optional"><input type="password" autoComplete="new-password" value={editForm.app_secret} onChange={event => setEditForm({ ...editForm, app_secret: event.target.value })}/></Field></div>}
          {error && <div className="connection-error" role="alert">{error}</div>}
          <div className="connection-editor-actions"><button type="button" onClick={() => { setEditingConnection(null); setError('') }}>Cancel</button><button type="submit" className="ops-primary" disabled={update.isPending}><Check size={14}/>{update.isPending ? 'Saving…' : editMode === 'reconnect' ? 'Verify and reconnect' : 'Save changes'}</button></div>
        </form>}

        {connection.ad_accounts?.length > 0 && <div className="assigned-strip">{connection.ad_accounts.map(account => <span key={account.id}><i data-platform={connection.platform}/>{account.name}<small>{account.client?.name}</small></span>)}</div>}

        {isOpen && accounts && <div className="account-picker">
          <div className="account-picker-head"><div><strong>Accessible accounts</strong><small>{connection.platform === 'google' ? 'Manager accounts organize the hierarchy; select client accounts to import.' : 'Select the ad accounts to import.'}</small></div><button className="ops-primary" disabled={!hasClients || !assignmentsComplete || assign.isPending} onClick={() => assign.mutate(connection)}><Check size={14}/> Assign {selectedCount || ''}</button></div>
          {!hasClients && <div className="account-picker-empty">Create a client before assigning discovered ad accounts.</div>}
          <div className="account-picker-list">{accounts.map(account => account.is_manager
            ? <div className="manager-account" key={account.external_id} style={{ paddingLeft: `${12 + account.level * 18}px` }}><span>Manager</span><strong>{account.name}</strong><small>{account.external_id}</small></div>
            : <label className="discovered-account" key={account.external_id} style={{ paddingLeft: `${12 + account.level * 18}px` }}>
              <input type="checkbox" disabled={!hasClients} checked={Boolean(selection[account.external_id]?.selected)} onChange={event => updateSelection(account.external_id, { selected: event.target.checked })}/>
              <span><strong>{account.name}</strong><small>{account.external_id} · {account.currency || 'uses client currency'} · {account.timezone || 'uses client timezone'}{account.assigned_client ? ` · assigned to ${account.assigned_client.name}` : ''}</small></span>
              <select disabled={!hasClients || connection.scope === 'client'} aria-label={`Client for ${account.name}`} value={selection[account.external_id]?.client_id || ''} onChange={event => updateSelection(account.external_id, { client_id: event.target.value })}><option value="">Choose client</option>{assignableClients.map(client => <option key={client.id} value={client.id}>{client.name}</option>)}</select>
            </label>
          )}</div>
        </div>}
      </section>
    })}</div>
    <ConfirmDialog open={Boolean(pendingDelete)} title={`Remove ${pendingDelete?.name || 'connection'}?`} description={`This permanently removes the connection${pendingDelete?.ad_accounts_count ? `, its ${pendingDelete.ad_accounts_count} assigned account${pendingDelete.ad_accounts_count === 1 ? '' : 's'}, and their collected operational data` : ''}. It does not delete anything from ${pendingDelete?.platform === 'meta' ? 'Meta Ads' : 'Google Ads'}.`} confirmLabel="Remove connection" busy={remove.isPending} onClose={() => setPendingDelete(null)} onConfirm={() => remove.mutate(pendingDelete)}/>
  </Page>
}

export function ConnectionSetupGuide({ platform, checklist, onChecklistChange }) {
  const isGoogle = platform === 'google'

  return <aside className={`connection-setup-guide ${platform}`} aria-label={`${isGoogle ? 'Google Ads' : 'Meta Ads'} connection instructions`}>
    <div className="connection-guide-intro">
      <span className="connection-guide-icon">{isGoogle ? <ShieldCheck size={17}/> : <BookOpen size={17}/>}</span>
      <div>
        <strong>{isGoogle ? 'Use a Google login with Ads access' : 'Prepare a dedicated Meta System User token'}</strong>
        <p>{isGoogle
          ? 'The accounts discovered here are the accounts that the Google user can already access.'
          : 'A System User keeps reporting access independent of an employee’s personal Facebook login.'}</p>
      </div>
      <span className="connection-guide-time">{isGoogle ? '2–3 min' : '5–10 min'}</span>
    </div>

    <div className="connection-guide-checks">
      <span><Check size={13}/>{isGoogle ? 'Access to the Google Ads manager or client account' : 'Full control of the Meta business portfolio'}</span>
      <span><Check size={13}/>{isGoogle ? 'The correct Google email ready for sign-in' : 'A Meta app connected to that business portfolio'}</span>
    </div>

    <details open>
      <summary>Setup steps <ChevronDown size={14}/></summary>
      <ol>
        {isGoogle ? <>
          <li><b>Check access.</b> In Google Ads, the email must be listed under <em>Admin → Access and security</em> for the manager or client account.</li>
          <li><b>Continue with Google.</b> Choose that email and approve Google Ads access. No Google password is shared with this app.</li>
          <li><b>Assign accounts.</b> After returning here, select the discovered client accounts and assign each one to a client. Manager accounts appear only to show the hierarchy.</li>
        </> : <>
          <li><b>Open the correct portfolio.</b> In Meta Business settings, confirm the portfolio switcher shows the business that owns or has partner access to the ad accounts.</li>
          <li><b>Create or select a System User.</b> Go to <em>Users → System users</em>. Use a dedicated system user for reporting instead of a person’s login.</li>
          <li><b>Assign the required ad accounts.</b> Select the system user, choose <em>Assign assets → Ad accounts</em>, and enable permission to view account performance for every account you need.</li>
          <li><b>Generate a token.</b> Choose the app owned by the same portfolio, enable <code>ads_read</code>, choose an expiration appropriate for your security policy, and generate the token.</li>
          <li><b>Copy it immediately.</b> Meta only shows the complete token at generation time. Paste the full value into the field below.</li>
          <li><b>Discover and assign.</b> After verification, select each accessible ad account and map it to the correct client in this app.</li>
        </>}
      </ol>
      <a href={isGoogle ? 'https://developers.google.com/google-ads/api/docs/oauth/overview' : 'https://business.facebook.com/settings/system-users'} target="_blank" rel="noreferrer">
        {isGoogle ? 'Google Ads access guide' : 'Open Meta Business settings'} <ExternalLink size={12}/>
      </a>
    </details>
    {!isGoogle && onChecklistChange && <div className="connection-readiness">
      <strong>Confirm before entering the token</strong>
      <label><input type="checkbox" checked={Boolean(checklist?.systemUser)} onChange={event => onChecklistChange('systemUser', event.target.checked)}/><span>I selected a dedicated System User in the correct business portfolio.</span></label>
      <label><input type="checkbox" checked={Boolean(checklist?.assets)} onChange={event => onChecklistChange('assets', event.target.checked)}/><span>I assigned every ad account that should be discoverable.</span></label>
      <label><input type="checkbox" checked={Boolean(checklist?.permission)} onChange={event => onChecklistChange('permission', event.target.checked)}/><span>The generated token includes <code>ads_read</code>.</span></label>
    </div>}
  </aside>
}

function Field({ label, hint, children }) { return <label><span>{label}</span>{children}{hint && <small className="connection-field-hint">{hint}</small>}</label> }
