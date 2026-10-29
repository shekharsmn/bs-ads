import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Building2, Pencil, Plus, Send, Trash2, X } from 'lucide-react'
import { Link } from 'react-router-dom'
import { api } from '../api/client'
import ConfirmDialog from '../components/ui/ConfirmDialog'
import { useAuth } from '../context/AuthContext'
import { useToast } from '../context/ToastContext'

const emptyForm = {
  name: '',
  timezone: 'Asia/Kolkata',
  currency: 'INR',
  objective: 'both',
  chat_visibility: 'internal',
  google_chat_webhook: '',
  remove_google_chat_webhook: false,
  active: true,
}

export default function ClientsPage() {
  const { user } = useAuth()
  const toast = useToast()
  const qc = useQueryClient()
  const [editingId, setEditingId] = useState(null)
  const [pendingDelete, setPendingDelete] = useState(null)
  const [form, setForm] = useState(emptyForm)
  const clients = useQuery({ queryKey: ['clients'], queryFn: () => api.getClients().then(r => r.data) })

  const closeForm = () => {
    setEditingId(null)
    setForm(emptyForm)
  }

  const save = useMutation({
    mutationFn: () => {
      const { remove_google_chat_webhook: removeWebhook, ...values } = form
      const payload = { ...values }

      if (editingId && !payload.google_chat_webhook) delete payload.google_chat_webhook
      if (editingId && removeWebhook) payload.google_chat_webhook = null

      return editingId === 'new' ? api.createClient(payload) : api.updateClient(editingId, payload)
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['clients'] })
      closeForm()
    },
  })

  const remove = useMutation({
    mutationFn: target => api.deleteClient(target.id),
    onSuccess: (_, target) => {
      qc.invalidateQueries({ queryKey: ['clients'] })
      qc.invalidateQueries({ queryKey: ['performance-accounts'] })
      qc.invalidateQueries({ queryKey: ['connections'] })
      if (editingId === target.id) closeForm()
      setPendingDelete(null)
      toast.success(`${target.name} was deleted.`)
    },
    onError: error => toast.error(error?.response?.data?.message || 'Could not delete the client.'),
  })

  const openCreate = () => {
    setEditingId('new')
    setForm(emptyForm)
  }

  const openEdit = client => {
    setEditingId(client.id)
    setForm({
      name: client.name,
      timezone: client.timezone,
      currency: client.currency,
      objective: client.objective,
      chat_visibility: client.chat_visibility,
      google_chat_webhook: '',
      remove_google_chat_webhook: false,
      active: client.active,
    })
  }

  const isCreating = editingId === 'new'
  const canManage = ['admin', 'marketer'].includes(user?.role)

  return <Page title="Clients" subtitle="Reporting boundaries, goals, access and Google Chat delivery.">
    <div className="ops-toolbar">
      {user?.role === 'admin' && !editingId && <button className="ops-primary" onClick={openCreate}><Plus size={15}/> New client</button>}
    </div>

    {editingId && <form className="ops-form" onSubmit={event => { event.preventDefault(); save.mutate() }}>
      <Field label="Client name"><input required value={form.name} onChange={event => setForm({ ...form, name: event.target.value })}/></Field>
      <Field label="Timezone"><input required value={form.timezone} onChange={event => setForm({ ...form, timezone: event.target.value })}/></Field>
      <Field label="Currency"><input required maxLength={3} value={form.currency} onChange={event => setForm({ ...form, currency: event.target.value.toUpperCase() })}/></Field>
      <Field label="Objective"><select value={form.objective} onChange={event => setForm({ ...form, objective: event.target.value })}><option value="lead_gen">Lead generation</option><option value="ecommerce">Ecommerce</option><option value="both">Both</option></select></Field>
      <Field label="Chat visibility"><select value={form.chat_visibility} onChange={event => setForm({ ...form, chat_visibility: event.target.value })}><option value="internal">Internal</option><option value="client">Client visible</option></select></Field>
      <Field label="Google Chat webhook">
        <input
          type="url"
          value={form.google_chat_webhook}
          disabled={form.remove_google_chat_webhook}
          onChange={event => setForm({ ...form, google_chat_webhook: event.target.value })}
          placeholder={isCreating ? 'https://chat.googleapis.com/...' : 'Leave blank to keep current webhook'}
        />
      </Field>
      {!isCreating && <label className="ops-check"><input type="checkbox" checked={form.remove_google_chat_webhook} onChange={event => setForm({ ...form, remove_google_chat_webhook: event.target.checked, google_chat_webhook: '' })}/><span>Remove saved webhook</span></label>}
      {!isCreating && <label className="ops-check"><input type="checkbox" checked={form.active} onChange={event => setForm({ ...form, active: event.target.checked })}/><span>Client is active</span></label>}
      <div className="ops-form-actions">
        <button type="button" onClick={closeForm}><X size={14}/> Cancel</button>
        <button className="ops-primary" disabled={save.isPending}>{save.isPending ? 'Saving…' : isCreating ? 'Create client' : 'Save changes'}</button>
      </div>
      {save.isError && <p className="ops-form-error">{save.error?.response?.data?.message || 'Could not save the client.'}</p>}
    </form>}

    <div className="ops-list">{(clients.data || []).map(client => <div className="ops-list-row" key={client.id}>
      <Building2 size={17}/>
      <div><Link className="client-name-link" to={`/clients/${client.id}`}><strong>{client.name}</strong></Link><small>{client.timezone} · {client.currency} · {client.objective.replace('_', ' ')} · {client.active ? 'active' : 'paused'}</small></div>
      <span>{client.ad_accounts_count} accounts</span>
      <span className={client.incidents_count ? 'ops-danger' : 'ops-ok'}>{client.incidents_count} open alerts</span>
      {client.google_chat_webhook_configured && <button title="Test Google Chat" onClick={() => api.testClientChat(client.id)}><Send size={14}/></button>}
      {canManage && <button title={`Edit ${client.name}`} onClick={() => openEdit(client)}><Pencil size={14}/></button>}
      {user?.role === 'admin' && <button className="client-delete-button" title={`Delete ${client.name}`} aria-label={`Delete ${client.name}`} onClick={() => setPendingDelete(client)}><Trash2 size={14}/></button>}
    </div>)}</div>
    <ConfirmDialog
      open={Boolean(pendingDelete)}
      title={`Delete ${pendingDelete?.name || 'client'}?`}
      description="This permanently deletes the client and its ad accounts, collected performance data, alerts, data-health records, report schedules, and client-specific templates. Connections and research records are retained but unlinked from this client. This cannot be undone."
      confirmLabel="Delete client"
      busy={remove.isPending}
      onClose={() => setPendingDelete(null)}
      onConfirm={() => pendingDelete && remove.mutate(pendingDelete)}
    />
  </Page>
}

export function Page({ title, subtitle, children }) { return <div style={{ padding: '30px 34px 48px', maxWidth: 1200, margin: '0 auto' }}><header style={{ marginBottom: 28 }}><p className="ops-eyebrow">SharkAds</p><h1 className="ops-title">{title}</h1><p className="ops-subtitle">{subtitle}</p></header>{children}</div> }
function Field({ label, children }) { return <label><span>{label}</span>{children}</label> }
