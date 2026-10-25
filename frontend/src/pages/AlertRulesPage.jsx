import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Trash2 } from 'lucide-react'
import { api } from '../api/client'
import { AsyncStateNotice } from '../components/ui/AsyncState'
import { useAuth } from '../context/AuthContext'
import { Page } from './ClientsPage'

export default function AlertRulesPage() {
  const { user } = useAuth()
  const qc = useQueryClient()
  const [clientId, setClientId] = useState('')
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState({ name: '', metric: 'cpl', operator: 'above', threshold: 0, severity: 'warning', window_days: 1 })
  const clients = useQuery({ queryKey: ['clients'], queryFn: () => api.getClients().then(r => r.data) })
  const selectedClientId = clientId || (clients.data?.[0]?.id ? String(clients.data[0].id) : '')
  const rules = useQuery({ queryKey: ['rules', selectedClientId], queryFn: () => api.getAlertRules({ client_id: selectedClientId }).then(r => r.data), enabled: Boolean(selectedClientId) })
  const templates = useQuery({ queryKey: ['alert-templates'], queryFn: () => api.getAlertTemplates().then(r => r.data) })
  const create = useMutation({ mutationFn: () => api.createAlertRule({ ...form, client_id: Number(selectedClientId) }), onSuccess: () => { qc.invalidateQueries({ queryKey: ['rules', selectedClientId] }); setOpen(false) } })
  const remove = useMutation({ mutationFn: id => api.deleteAlertRule(id), onSuccess: () => qc.invalidateQueries({ queryKey: ['rules', selectedClientId] }) })
  const canManage = user?.role !== 'viewer'

  return <Page title="Alert policies" subtitle="Global Brandshark templates with client, account and campaign overrides.">
    <AsyncStateNotice queries={[clients, rules, templates]}/>
    <div className="ops-toolbar"><select className="ops-select" value={selectedClientId} onChange={e => setClientId(e.target.value)}>{(clients.data || []).map(client => <option key={client.id} value={client.id}>{client.name}</option>)}</select>{canManage && <button className="ops-primary" onClick={() => setOpen(value => !value)}><Plus size={14}/> Add override</button>}</div>
    {open && <form className="ops-form compact" onSubmit={event => { event.preventDefault(); create.mutate() }}><label><span>Start from template</span><select onChange={event => { const template = templates.data?.find(item => String(item.id) === event.target.value); if (template) setForm({ ...form, name: template.name, metric: template.metric, operator: template.operator, threshold: template.threshold ?? '', severity: template.severity, alert_template_id: template.id }) }}><option value="">Custom</option>{(templates.data || []).map(template => <option key={template.id} value={template.id}>{template.name}</option>)}</select></label><label><span>Name</span><input required value={form.name} onChange={event => setForm({ ...form, name: event.target.value })}/></label><label><span>Metric</span><select value={form.metric} onChange={event => setForm({ ...form, metric: event.target.value })}>{['spend', 'impressions', 'clicks', 'conversions', 'leads', 'purchases', 'ctr', 'cpc', 'cpm', 'cpa', 'cpl', 'conversion_rate', 'roas', 'frequency'].map(value => <option key={value}>{value}</option>)}</select></label><label><span>Operator</span><select value={form.operator} onChange={event => setForm({ ...form, operator: event.target.value })}>{['below', 'above', 'percentage_drop', 'percentage_increase', 'no_spend', 'no_impressions', 'no_conversions_with_spend', 'budget_under_pacing', 'budget_over_pacing', 'data_stale', 'connection_error'].map(value => <option key={value}>{value}</option>)}</select></label><label><span>Threshold</span><input type="number" value={form.threshold} onChange={event => setForm({ ...form, threshold: event.target.value === '' ? null : Number(event.target.value) })}/></label><button className="ops-primary" disabled={create.isPending}>{create.isPending ? 'Saving…' : 'Save rule'}</button></form>}
    <div className="ops-list">{(rules.data || []).map(rule => <div className="ops-list-row" key={rule.id}><i className="rule-dot" data-severity={rule.severity}/><div><strong>{rule.name}</strong><small>{rule.metric} · {rule.operator} {rule.threshold ?? ''} · {rule.client?.name || 'Global template'}</small></div><span>{rule.enabled ? 'Active' : 'Paused'}</span>{canManage && rule.client_id && <button onClick={() => window.confirm('Delete this override?') && remove.mutate(rule.id)}><Trash2 size={14}/></button>}</div>)}</div>
  </Page>
}
