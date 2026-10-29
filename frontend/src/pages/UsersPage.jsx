import { createElement, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertCircle, Check, Clock3, History, MailPlus, RefreshCw, Search, ShieldCheck, UserRoundCheck, UserRoundX, Users, X } from 'lucide-react'
import { api } from '../api/client'
import { useAuth } from '../context/AuthContext'
import { useToast } from '../context/ToastContext'
import ConfirmDialog from '../components/ui/ConfirmDialog'

const emptyInvite = { name: '', email: '', role: 'viewer', client_ids: [], run_performance_audits: false, run_content_audits: false, use_data_inspector: false }

export default function UsersPage() {
  const [filters, setFilters] = useState({ search: '', status: '', role: '', permission: '' })
  const [inviteOpen, setInviteOpen] = useState(false)
  const [historyUser, setHistoryUser] = useState(null)
  const query = useQuery({ queryKey: ['user-directory', filters], queryFn: () => api.getUserDirectory(Object.fromEntries(Object.entries(filters).filter(([, value]) => value))).then(r => r.data) })
  const clients = useQuery({ queryKey: ['clients'], queryFn: () => api.getClients().then(r => r.data) })
  const summary = query.data?.summary || { active: 0, pending: 0, disabled: 0, admins: 0 }
  return <main className="users-page">
    <header className="users-header">
      <div><span><Users size={18}/></span><div><p>Administration</p><h1>Users</h1><small>Control workspace access, client visibility, and audit authority.</small></div></div>
      <button className="users-primary" onClick={() => setInviteOpen(true)}><MailPlus size={15}/> Invite user</button>
    </header>
    <section className="users-summary" aria-label="User access summary">
      <Summary label="Active" value={summary.active} icon={UserRoundCheck}/><Summary label="Pending" value={summary.pending} icon={Clock3}/><Summary label="Disabled" value={summary.disabled} icon={UserRoundX}/><Summary label="Administrators" value={summary.admins} icon={ShieldCheck}/>
    </section>
    <section className="users-workspace">
      <div className="users-filters">
        <label className="users-search"><Search size={14}/><input aria-label="Search users" placeholder="Search name or email" value={filters.search} onChange={e => setFilters({ ...filters, search: e.target.value })}/></label>
        <select aria-label="Filter status" value={filters.status} onChange={e => setFilters({ ...filters, status: e.target.value })}><option value="">All statuses</option><option value="active">Active</option><option value="pending">Pending</option><option value="disabled">Disabled</option><option value="revoked">Revoked</option></select>
        <select aria-label="Filter role" value={filters.role} onChange={e => setFilters({ ...filters, role: e.target.value })}><option value="">All roles</option><option value="admin">Admin</option><option value="marketer">Marketer</option><option value="viewer">Viewer</option></select>
        <select aria-label="Filter permission" value={filters.permission} onChange={e => setFilters({ ...filters, permission: e.target.value })}><option value="">All permissions</option><option value="account">Account audits</option><option value="ad_library">Ad Library audits</option><option value="data_inspector">Data Inspector</option></select>
      </div>
      <div className="users-table-wrap">
        <table className="users-table"><thead><tr><th>Person</th><th>Role</th><th>Client access</th><th>Audit permissions</th><th>Status</th><th/></tr></thead><tbody>
          {(query.data?.data || []).map(item => <DirectoryRow key={`${item.type}:${item.id}:${item.status}:${item.role}:${item.run_performance_audits}:${item.run_content_audits}:${item.use_data_inspector}:${item.clients.map(client => client.id).join(',')}`} item={item} clients={clients.data || []} onHistory={setHistoryUser}/>) }
        </tbody></table>
        {query.isError && <div className="users-empty users-error"><AlertCircle size={22}/><strong>Users could not be loaded</strong><span>{query.error?.response?.data?.message || 'Check that the latest database migration has been applied, then try again.'}</span></div>}
        {!query.isPending && !query.isError && !(query.data?.data || []).length && <div className="users-empty"><Users size={22}/><strong>No matching people</strong><span>Adjust the filters or invite someone to the workspace.</span></div>}
        {query.isPending && <div className="users-loading"><span/>Loading access directory…</div>}
      </div>
    </section>
    <InviteDialog open={inviteOpen} clients={clients.data || []} onClose={() => setInviteOpen(false)}/>
    <HistoryDialog user={historyUser} onClose={() => setHistoryUser(null)}/>
  </main>
}

function Summary({ label, value, icon: Icon }) {
  return <article>{createElement(Icon, { size: 15 })}<div><strong>{value}</strong><span>{label}</span></div></article>
}

function DirectoryRow({ item, clients, onHistory }) {
  const { user: currentUser } = useAuth(); const toast = useToast(); const queryClient = useQueryClient()
  const [clientIds, setClientIds] = useState(() => item.clients.map(client => client.id).sort((a, b) => a - b))
  const [confirm, setConfirm] = useState(null)
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['user-directory'] })
  const save = useMutation({
    mutationFn: data => item.type === 'user' ? api.updateGovernanceUser(item.id, data) : api.updateUserInvitation(item.id, data),
    onSuccess: () => { refresh(); toast.success('Access updated.') }, onError: error => toast.error(error.response?.data?.message || 'Unable to update access.'),
  })
  const resend = useMutation({ mutationFn: () => api.resendUserInvitation(item.id), onSuccess: () => { refresh(); toast.success('Invitation sent again.') }, onError: e => toast.error(e.response?.data?.message || 'Unable to resend invitation.') })
  const revoke = useMutation({ mutationFn: () => api.revokeUserInvitation(item.id), onSuccess: () => { refresh(); setConfirm(null); toast.success('Invitation revoked.') }, onError: e => toast.error(e.response?.data?.message || 'Unable to revoke invitation.') })
  const isAdmin = item.role === 'admin'; const pending = item.status === 'pending'; const editable = item.type === 'user' || pending
  const savedIds = useMemo(() => item.clients.map(client => client.id).sort((a, b) => a - b), [item.clients])
  const dirtyClients = JSON.stringify(clientIds) !== JSON.stringify(savedIds)
  const toggleClient = (id, checked) => setClientIds(current => (checked ? [...new Set([...current, id])] : current.filter(value => value !== id)).sort((a, b) => a - b))
  return <tr className={`user-row ${item.status}`}>
    <td><div className="user-identity"><span>{(item.name || item.email).slice(0, 1).toUpperCase()}</span><div><strong>{item.name || 'Pending user'}</strong><small>{item.email}</small></div></div></td>
    <td><select aria-label={`Role for ${item.email}`} disabled={!editable || save.isPending} value={item.role} onChange={e => save.mutate({ role: e.target.value })}><option value="admin">Admin</option><option value="marketer">Marketer</option><option value="viewer">Viewer</option></select></td>
    <td><details className="client-access"><summary>{isAdmin ? 'All clients' : item.clients_count ? `${item.clients_count} assigned` : 'No clients'}</summary><div>
      {clients.map(client => <label key={client.id}><input type="checkbox" checked={isAdmin || clientIds.includes(client.id)} disabled={isAdmin || !editable || save.isPending} onChange={e => toggleClient(client.id, e.target.checked)}/>{client.name}</label>)}
      {!clients.length && <small>No clients available.</small>}
      {dirtyClients && <footer><button onClick={() => setClientIds(savedIds)}>Cancel</button><button onClick={() => save.mutate({ client_ids: clientIds })}>Save access</button></footer>}
    </div></details></td>
    <td><div className="permission-stack">
      <Permission label="Account audits" checked={isAdmin || item.run_performance_audits} disabled={isAdmin || !editable || save.isPending} onChange={checked => save.mutate({ run_performance_audits: checked })}/>
      <Permission label="Ad Library audits" checked={isAdmin || item.run_content_audits} disabled={isAdmin || !editable || save.isPending} onChange={checked => save.mutate({ run_content_audits: checked })}/>
      <Permission label="Data Inspector" checked={isAdmin || item.use_data_inspector} disabled={isAdmin || !editable || save.isPending} onChange={checked => save.mutate({ use_data_inspector: checked })}/>
    </div></td>
    <td><span className={`user-status ${item.status}`}><i/>{item.status}</span>{item.last_login_at && <small className="last-seen">Last login {new Date(item.last_login_at).toLocaleDateString()}</small>}{pending && <small className="last-seen">Sent {new Date(item.last_sent_at).toLocaleDateString()}</small>}</td>
    <td><div className="row-actions">
      {item.type === 'user' && <button title="Access history" onClick={() => onHistory(item)}><History size={14}/></button>}
      {pending && <button title="Resend invitation" disabled={resend.isPending} onClick={() => resend.mutate()}><RefreshCw size={14}/></button>}
      {pending && <button className="danger" title="Revoke invitation" onClick={() => setConfirm('revoke')}><X size={14}/></button>}
      {item.type === 'user' && <button className={item.status === 'disabled' ? '' : 'danger'} disabled={currentUser?.id === item.id || save.isPending} onClick={() => setConfirm(item.status === 'disabled' ? 'reactivate' : 'disable')}>{item.status === 'disabled' ? <UserRoundCheck size={14}/> : <UserRoundX size={14}/>}</button>}
    </div></td>
    <ConfirmDialog open={Boolean(confirm)} title={confirm === 'revoke' ? 'Revoke invitation?' : confirm === 'disable' ? 'Disable this user?' : 'Reactivate this user?'} description={confirm === 'disable' ? 'Their active sessions will end immediately.' : confirm === 'revoke' ? 'The invited email will no longer be able to sign in until invited again.' : 'Their existing role, clients, and permissions will be restored.'} confirmLabel={confirm === 'reactivate' ? 'Reactivate' : confirm === 'revoke' ? 'Revoke' : 'Disable'} tone={confirm === 'reactivate' ? 'primary' : 'danger'} busy={save.isPending || revoke.isPending} onClose={() => setConfirm(null)} onConfirm={() => confirm === 'revoke' ? revoke.mutate() : save.mutate({ disabled: confirm === 'disable' }, { onSuccess: () => setConfirm(null) })}/>
  </tr>
}

function Permission({ label, checked, disabled, onChange }) {
  return <label className={checked ? 'permission-on' : ''}><button type="button" role="switch" aria-checked={checked} aria-label={label} disabled={disabled} onClick={() => onChange(!checked)}><span/></button>{label}</label>
}

function InviteDialog({ open, clients, onClose }) {
  const [form, setForm] = useState(emptyInvite); const toast = useToast(); const queryClient = useQueryClient()
  const mutation = useMutation({ mutationFn: data => api.inviteUser(data), onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['user-directory'] }); toast.success('Invitation sent.'); setForm(emptyInvite); onClose() }, onError: e => toast.error(e.response?.data?.message || Object.values(e.response?.data?.errors || {}).flat()[0] || 'Unable to send invitation.') })
  if (!open) return null
  const admin = form.role === 'admin'; const toggleClient = id => setForm(current => ({ ...current, client_ids: current.client_ids.includes(id) ? current.client_ids.filter(value => value !== id) : [...current.client_ids, id] }))
  return <div className="users-dialog-backdrop" onMouseDown={e => e.target === e.currentTarget && !mutation.isPending && onClose()}><form className="users-dialog" onSubmit={e => { e.preventDefault(); mutation.mutate({ ...form, run_performance_audits: admin || form.run_performance_audits, run_content_audits: admin || form.run_content_audits, use_data_inspector: admin || form.use_data_inspector }) }}>
    <header><div><span><MailPlus size={16}/></span><div><h2>Invite user</h2><p>Preconfigure access before their first Workspace sign-in.</p></div></div><button type="button" aria-label="Close" onClick={onClose}><X size={16}/></button></header>
    <div className="invite-fields"><label>Name <input value={form.name} onChange={e => setForm({ ...form, name: e.target.value })} placeholder="Optional display name"/></label><label>Workspace email <input required type="email" value={form.email} onChange={e => setForm({ ...form, email: e.target.value })} placeholder="name@company.com"/></label><label>Role <select value={form.role} onChange={e => setForm({ ...form, role: e.target.value })}><option value="viewer">Viewer</option><option value="marketer">Marketer</option><option value="admin">Admin</option></select></label></div>
    <section><h3>Client access</h3><p>Users only see assigned clients and their ad accounts.</p><div className="invite-clients">{clients.map(client => <label key={client.id}><input type="checkbox" checked={admin || form.client_ids.includes(client.id)} disabled={admin} onChange={() => toggleClient(client.id)}/><span>{client.name}</span>{(admin || form.client_ids.includes(client.id)) && <Check size={13}/>}</label>)}</div></section>
    <section><h3>Tool permissions</h3><p>Permissions apply only inside assigned clients.</p><div className="invite-permissions"><Permission label="Account audits" checked={admin || form.run_performance_audits} disabled={admin} onChange={value => setForm({ ...form, run_performance_audits: value })}/><Permission label="Ad Library audits" checked={admin || form.run_content_audits} disabled={admin} onChange={value => setForm({ ...form, run_content_audits: value })}/><Permission label="Data Inspector" checked={admin || form.use_data_inspector} disabled={admin} onChange={value => setForm({ ...form, use_data_inspector: value })}/></div></section>
    <footer><button type="button" onClick={onClose}>Cancel</button><button className="users-primary" disabled={mutation.isPending}>{mutation.isPending ? 'Sending…' : 'Send invitation'}</button></footer>
  </form></div>
}

function HistoryDialog({ user, onClose }) {
  const history = useQuery({ queryKey: ['user-history', user?.id], queryFn: () => api.getGovernanceUserHistory(user.id).then(r => r.data), enabled: Boolean(user) })
  if (!user) return null
  return <div className="users-dialog-backdrop" onMouseDown={e => e.target === e.currentTarget && onClose()}><section className="users-dialog history-dialog"><header><div><span><History size={16}/></span><div><h2>Access history</h2><p>{user.name} · {user.email}</p></div></div><button aria-label="Close" onClick={onClose}><X size={16}/></button></header><div className="history-list">{(history.data?.data || []).map(event => <article key={event.id}><i/><div><strong>{event.action.replaceAll('.', ' ')}</strong><span>{event.actor?.name || 'System'} · {new Date(event.created_at).toLocaleString()}</span></div></article>)}{!history.isPending && !(history.data?.data || []).length && <p>No access changes recorded yet.</p>}</div></section></div>
}
