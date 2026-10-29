import { createElement, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { BookOpenCheck, Download, History, Send, ShieldCheck, Trash2 } from 'lucide-react'
import { api } from '../api/client'
import { useToast } from '../context/ToastContext'
import ConfirmDialog from '../components/ui/ConfirmDialog'

const tabs = [
  ['audit', 'Audit trail', History], ['notifications', 'Destinations', Send], ['methodologies', 'Methodologies', BookOpenCheck],
]

export default function GovernancePage() {
  const [tab, setTab] = useState('audit')
  return <main className="governance-page">
    <header className="governance-header"><span><ShieldCheck size={18}/></span><div><h1>Governance</h1><p>Access, traceability, delivery controls, and methodology history.</p></div></header>
    <nav className="governance-tabs" aria-label="Governance sections">{tabs.map(([key, label, icon]) => <button key={key} className={tab === key ? 'active' : ''} onClick={() => setTab(key)}>{createElement(icon, { size: 14 })}{label}</button>)}</nav>
    {tab === '__legacy_access' && <AccessPanel/>}
    {tab === 'audit' && <AuditPanel/>}
    {tab === 'notifications' && <NotificationPanel/>}
    {tab === 'methodologies' && <MethodologyPanel/>}
  </main>
}

function AccessPanel() {
  const [search, setSearch] = useState(''); const [status, setStatus] = useState('')
  const users = useQuery({ queryKey: ['governance-users', search, status], queryFn: () => api.getGovernanceUsers({ search: search || undefined, status: status || undefined }).then(r => r.data) })
  const clients = useQuery({ queryKey: ['clients'], queryFn: () => api.getClients().then(r => r.data) })
  return <section className="governance-panel">
    <div className="governance-toolbar"><input aria-label="Search users" placeholder="Search name or email" value={search} onChange={e => setSearch(e.target.value)}/><select aria-label="User status" value={status} onChange={e => setStatus(e.target.value)}><option value="">All access</option><option value="active">Active</option><option value="disabled">Disabled</option></select></div>
    <div className="governance-table-wrap"><table><thead><tr><th>User</th><th>Role</th><th>Client access</th><th>Last login</th><th>Status</th></tr></thead><tbody>
      {(users.data?.data || []).map(user => <AccessRow key={`${user.id}:${user.role}:${Boolean(user.disabled_at)}:${user.clients.map(item => item.id).sort((a, b) => a - b).join(',')}`} user={user} clients={clients.data || []}/>)}
    </tbody></table></div>
  </section>
}

function AccessRow({ user, clients }) {
  const queryClient = useQueryClient(); const toast = useToast()
  const savedIds = user.clients.map(item => item.id).sort((a, b) => a - b)
  const [draftIds, setDraftIds] = useState(savedIds)
  const dirty = JSON.stringify(draftIds) !== JSON.stringify(savedIds)
  const mutation = useMutation({
    mutationFn: data => api.updateGovernanceUser(user.id, data),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['governance-users'] }); toast.success('Access updated.') },
    onError: error => toast.error(error.response?.data?.message || 'Unable to update access.'),
  })
  const toggleClient = (clientId, checked) => setDraftIds(current => (checked ? [...new Set([...current, clientId])] : current.filter(id => id !== clientId)).sort((a, b) => a - b))

  return <tr><td><strong>{user.name}</strong><small>{user.email}</small></td><td><select value={user.role} disabled={mutation.isPending} onChange={e => mutation.mutate({ role: e.target.value })}><option value="admin">Admin</option><option value="marketer">Marketer</option><option value="viewer">Viewer</option></select></td><td><details><summary>{user.role === 'admin' ? 'All clients' : `${user.clients_count} clients`}</summary><div className="access-client-list">{clients.map(client => <label key={client.id}><input type="checkbox" checked={user.role === 'admin' || draftIds.includes(client.id)} disabled={user.role === 'admin' || mutation.isPending} onChange={e => toggleClient(client.id, e.target.checked)}/>{client.name}</label>)}{user.role !== 'admin' && dirty && <div className="access-client-actions"><button disabled={mutation.isPending} onClick={() => setDraftIds(savedIds)}>Cancel</button><button disabled={mutation.isPending} onClick={() => mutation.mutate({ client_ids: draftIds })}>{mutation.isPending ? 'Saving…' : 'Save'}</button></div>}</div></details></td><td>{user.last_login_at ? new Date(user.last_login_at).toLocaleString() : 'Never'}</td><td><button className={`status-toggle ${user.disabled_at ? 'disabled' : 'active'}`} disabled={mutation.isPending} onClick={() => mutation.mutate({ disabled: !user.disabled_at })}>{user.disabled_at ? 'Disabled' : 'Active'}</button></td></tr>
}

function AuditPanel() {
  const [action, setAction] = useState('')
  const events = useQuery({ queryKey: ['audit-events', action], queryFn: () => api.getAuditEvents({ action: action || undefined }).then(r => r.data) })
  return <section className="governance-panel"><div className="governance-toolbar"><input aria-label="Filter audit actions" placeholder="Filter by action" value={action} onChange={e => setAction(e.target.value)}/><a className="governance-export" href={api.getAuditEventsExportUrl()}><Download size={14}/>Export CSV</a></div>
    <div className="governance-table-wrap"><table><thead><tr><th>Time</th><th>Actor</th><th>Action</th><th>Client</th><th>Target</th><th>Correlation</th></tr></thead><tbody>{(events.data?.data || []).map(event => <tr key={event.id}><td>{new Date(event.created_at).toLocaleString()}</td><td>{event.actor?.name || 'System'}<small>{event.actor?.email}</small></td><td><code>{event.action}</code></td><td>{event.client?.name || 'Organization'}</td><td>{event.entity_type ? `${event.entity_type.split('\\').pop()} #${event.entity_id}` : '—'}</td><td><small className="mono">{event.correlation_id}</small></td></tr>)}</tbody></table></div>
  </section>
}

const defaultDestination = { client_id: '', name: '', channel: 'email', endpoint: '', events: ['alert', 'report_delivery'], enabled: true }
function NotificationPanel() {
  const queryClient = useQueryClient(); const toast = useToast(); const [form, setForm] = useState(defaultDestination); const [deleting, setDeleting] = useState(null)
  const destinations = useQuery({ queryKey: ['notification-destinations'], queryFn: () => api.getNotificationDestinations().then(r => r.data) })
  const clients = useQuery({ queryKey: ['clients'], queryFn: () => api.getClients().then(r => r.data) })
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['notification-destinations'] })
  const create = useMutation({ mutationFn: data => api.createNotificationDestination(data), onSuccess: () => { refresh(); setForm(defaultDestination); toast.success('Destination created.') }, onError: e => toast.error(e.response?.data?.message || 'Unable to create destination.') })
  const remove = useMutation({ mutationFn: id => api.deleteNotificationDestination(id), onSuccess: () => { refresh(); setDeleting(null); toast.success('Destination removed.') } })
  const test = useMutation({ mutationFn: id => api.testNotificationDestination(id), onSuccess: () => { refresh(); toast.success('Test delivered.') }, onError: e => toast.error(e.response?.data?.message || 'Test delivery failed.') })
  const submit = e => { e.preventDefault(); const configuration = form.channel === 'email' ? { address: form.endpoint } : { url: form.endpoint }; create.mutate({ ...form, configuration }) }
  return <section className="governance-panel notification-layout"><form className="destination-form" onSubmit={submit}><h2>Add destination</h2><label>Client<select required value={form.client_id} onChange={e => setForm({ ...form, client_id: e.target.value })}><option value="">Choose client</option>{(clients.data || []).map(c => <option key={c.id} value={c.id}>{c.name}</option>)}</select></label><label>Name<input required value={form.name} onChange={e => setForm({ ...form, name: e.target.value })}/></label><label>Channel<select value={form.channel} onChange={e => setForm({ ...form, channel: e.target.value, endpoint: '' })}><option value="email">Email</option><option value="google_chat">Google Chat</option><option value="slack">Slack</option><option value="teams">Microsoft Teams</option><option value="webhook">Generic webhook</option></select></label><label>{form.channel === 'email' ? 'Email address' : 'HTTPS webhook URL'}<input required type={form.channel === 'email' ? 'email' : 'url'} value={form.endpoint} onChange={e => setForm({ ...form, endpoint: e.target.value })}/></label><fieldset><legend>Events</legend>{['alert', 'data_quality', 'report_delivery', 'workflow_failure', 'ai_budget'].map(event => <label key={event}><input type="checkbox" checked={form.events.includes(event)} onChange={e => setForm({ ...form, events: e.target.checked ? [...form.events, event] : form.events.filter(item => item !== event) })}/>{event.replaceAll('_', ' ')}</label>)}</fieldset><button disabled={create.isPending || !form.events.length}>Create destination</button></form>
    <div className="destination-list">{(destinations.data || []).map(destination => <article key={destination.id}><div><strong>{destination.name}</strong><span>{destination.channel.replaceAll('_', ' ')} · {destination.client?.name}</span></div><div className="destination-events">{destination.subscriptions.map(item => <small key={item.id}>{item.event_type.replaceAll('_', ' ')}</small>)}</div><div><button onClick={() => test.mutate(destination.id)} disabled={test.isPending}>Test</button><button className="icon-danger" aria-label={`Delete ${destination.name}`} onClick={() => setDeleting(destination)}><Trash2 size={14}/></button></div></article>)}</div>
    <ConfirmDialog open={Boolean(deleting)} title="Delete destination?" description="Future notifications will no longer be sent here." confirmLabel="Delete" tone="danger" busy={remove.isPending} onClose={() => setDeleting(null)} onConfirm={() => remove.mutate(deleting.id)}/>
  </section>
}

function MethodologyPanel() {
  const query = useQuery({ queryKey: ['methodologies'], queryFn: () => api.getMethodologies().then(r => r.data) })
  return <section className="methodology-grid">{(query.data || []).map(item => <article key={item.key}><header><strong>{item.key.replaceAll('-', ' ')}</strong><span>v{item.version}</span></header><p>{item.summary}</p><small>Released {item.released_at} · from {item.previous_version}</small><ul>{item.checks.map(check => <li key={check}>{check.replaceAll('_', ' ')}</li>)}</ul><details><summary>What changed</summary>{item.changes.map(change => <p key={change}>{change}</p>)}</details><code title={item.checksum}>{item.checksum.slice(0, 14)}…</code></article>)}</section>
}
