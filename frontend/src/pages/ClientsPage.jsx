import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Building2, Plus, Send } from 'lucide-react'
import { api } from '../api/client'
import { useAuth } from '../context/AuthContext'

export default function ClientsPage() {
  const { user } = useAuth(); const qc = useQueryClient(); const [open, setOpen] = useState(false)
  const [form, setForm] = useState({ name: '', timezone: 'Asia/Kolkata', currency: 'INR', objective: 'both', chat_visibility: 'internal', google_chat_webhook: '' })
  const clients = useQuery({ queryKey: ['clients'], queryFn: () => api.getClients().then(r => r.data) })
  const create = useMutation({ mutationFn: () => api.createClient(form), onSuccess: () => { qc.invalidateQueries({ queryKey: ['clients'] }); setOpen(false) } })
  return <Page title="Clients" subtitle="Reporting boundaries, goals, access and Google Chat delivery.">
    <div className="ops-toolbar">{user?.role === 'admin' && <button className="ops-primary" onClick={() => setOpen(v => !v)}><Plus size={15}/> New client</button>}</div>
    {open && <form className="ops-form" onSubmit={e => { e.preventDefault(); create.mutate() }}>
      <Field label="Client name"><input required value={form.name} onChange={e => setForm({...form,name:e.target.value})}/></Field>
      <Field label="Timezone"><input required value={form.timezone} onChange={e => setForm({...form,timezone:e.target.value})}/></Field>
      <Field label="Currency"><input required maxLength={3} value={form.currency} onChange={e => setForm({...form,currency:e.target.value.toUpperCase()})}/></Field>
      <Field label="Objective"><select value={form.objective} onChange={e => setForm({...form,objective:e.target.value})}><option value="lead_gen">Lead generation</option><option value="ecommerce">Ecommerce</option><option value="both">Both</option></select></Field>
      <Field label="Chat visibility"><select value={form.chat_visibility} onChange={e => setForm({...form,chat_visibility:e.target.value})}><option value="internal">Internal</option><option value="client">Client visible</option></select></Field>
      <Field label="Google Chat webhook"><input type="url" value={form.google_chat_webhook} onChange={e => setForm({...form,google_chat_webhook:e.target.value})} placeholder="https://chat.googleapis.com/..."/></Field>
      <button className="ops-primary" disabled={create.isPending}>Create client</button>
    </form>}
    <div className="ops-list">{(clients.data || []).map(client => <div className="ops-list-row" key={client.id}><Building2 size={17}/><div><strong>{client.name}</strong><small>{client.timezone} · {client.currency} · {client.objective.replace('_',' ')}</small></div><span>{client.ad_accounts_count} accounts</span><span className={client.incidents_count ? 'ops-danger' : 'ops-ok'}>{client.incidents_count} open alerts</span>{client.google_chat_webhook_configured && <button title="Test Google Chat" onClick={() => api.testClientChat(client.id)}><Send size={14}/></button>}</div>)}</div>
  </Page>
}

export function Page({ title, subtitle, children }) { return <div style={{ padding: '30px 34px 48px', maxWidth: 1200, margin: '0 auto' }}><header style={{ marginBottom: 28 }}><p className="ops-eyebrow">Brandshark Pulse</p><h1 className="ops-title">{title}</h1><p className="ops-subtitle">{subtitle}</p></header>{children}</div> }
function Field({ label, children }) { return <label><span>{label}</span>{children}</label> }
