import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { Plus, Trash2 } from 'lucide-react'
import { api } from '../api/client'
import { AsyncStateNotice } from '../components/ui/AsyncState'
import ScopeFilterBar from '../components/ScopeFilterBar'
import { useAuth } from '../context/AuthContext'
import { Page } from './ClientsPage'

const metrics = ['spend', 'impressions', 'clicks', 'conversions', 'leads', 'purchases', 'ctr', 'cpc', 'cpm', 'cpa', 'cpl', 'conversion_rate', 'roas', 'frequency']
const operators = ['below', 'above', 'percentage_drop', 'percentage_increase', 'no_spend', 'no_impressions', 'no_conversions_with_spend', 'budget_under_pacing', 'budget_over_pacing', 'data_stale', 'connection_error']
const defaultRule = { name: '', metric: 'cpl', operator: 'above', threshold: 0, severity: 'warning', window_days: 1 }
const defaultTemplate = { key: '', name: '', description: '', metric: 'cpl', operator: 'above', threshold: 0, severity: 'warning', active: true }

export default function AlertRulesPage() {
  const { user } = useAuth()
  const qc = useQueryClient()
  const [search, setSearch] = useSearchParams()
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState(defaultRule)
  const [templateForm, setTemplateForm] = useState(defaultTemplate)
  const requestedView = search.get('view') || 'templates'
  const view = requestedView === 'overrides' ? 'policies' : requestedView
  const clientId = search.get('client_id') || ''
  const accountId = search.get('ad_account_id') || ''
  const filters = { client_id: clientId || undefined, ad_account_id: accountId || undefined }
  const rules = useQuery({ queryKey: ['rules', filters], queryFn: () => api.getAlertRules(filters).then(response => response.data), enabled: view === 'policies' })
  const templates = useQuery({ queryKey: ['alert-templates'], queryFn: () => api.getAlertTemplates().then(response => response.data) })
  const setFilter = (key, value) => setSearch(current => { const next = new URLSearchParams(current); value ? next.set(key, value) : next.delete(key); return next }, { replace: true })
  const setScope = (nextClientId, nextAccountId) => { const next = new URLSearchParams(search); nextClientId ? next.set('client_id', nextClientId) : next.delete('client_id'); nextAccountId ? next.set('ad_account_id', nextAccountId) : next.delete('ad_account_id'); setSearch(next, { replace: true }) }
  const create = useMutation({ mutationFn: () => api.createAlertRule({ ...form, client_id: clientId ? Number(clientId) : null, ad_account_id: accountId ? Number(accountId) : null }), onSuccess: () => { qc.invalidateQueries({ queryKey: ['rules'] }); setOpen(false); setForm(defaultRule) } })
  const createTemplate = useMutation({ mutationFn: () => api.createAlertTemplate(templateForm), onSuccess: () => { qc.invalidateQueries({ queryKey: ['alert-templates'] }); setOpen(false); setTemplateForm(defaultTemplate) } })
  const remove = useMutation({ mutationFn: id => api.deleteAlertRule(id), onSuccess: () => qc.invalidateQueries({ queryKey: ['rules'] }) })
  const canManage = user?.role !== 'viewer'

  return <Page title="Alert policies & templates" subtitle="Inspect workspace defaults and create client- or account-scoped policies.">
    <AsyncStateNotice queries={[rules, templates]}/>
    <nav className="report-view-tabs" aria-label="Alert policy views"><button className={view === 'templates' ? 'active' : ''} onClick={() => setFilter('view', 'templates')}>Workspace templates</button><button className={view === 'policies' ? 'active' : ''} onClick={() => setFilter('view', 'policies')}>Scoped policies</button></nav>

    {view === 'templates' && <><div className="ops-toolbar">{user?.role === 'admin' && <button className="ops-primary" onClick={() => setOpen(value => !value)}><Plus size={14}/> Add template</button>}</div>{open && user?.role === 'admin' && <PolicyForm value={templateForm} onChange={setTemplateForm} template onSubmit={() => createTemplate.mutate()} pending={createTemplate.isPending}/>}<div className="policy-template-grid">{(templates.data || []).map(template => <article key={template.id}><header><i className="rule-dot" data-severity={template.severity}/><div><h2>{template.name}</h2><p>{template.description || 'Workspace alert template'}</p></div><span>{template.active ? 'Active' : 'Paused'}</span></header><dl><div><dt>Metric</dt><dd>{template.metric}</dd></div><div><dt>Condition</dt><dd>{template.operator.replaceAll('_', ' ')} {template.threshold ?? ''}</dd></div><div><dt>Severity</dt><dd>{template.severity}</dd></div><div><dt>Key</dt><dd className="mono">{template.key}</dd></div></dl></article>)}</div></>}

    {view === 'policies' && <><ScopeFilterBar clientId={clientId} accountId={accountId} onScopeChange={setScope} onAccountChange={value => setFilter('ad_account_id', value)}/><div className="ops-toolbar">{canManage && clientId && <button className="ops-primary" onClick={() => setOpen(value => !value)}><Plus size={14}/> Add policy</button>}</div>{open && canManage && <PolicyForm value={form} onChange={setForm} templates={templates.data || []} onSubmit={() => create.mutate()} pending={create.isPending}/>}<div className="ops-list">{(rules.data || []).map(rule => <div className="ops-list-row" key={rule.id}><i className="rule-dot" data-severity={rule.severity}/><div><strong>{rule.name}</strong><small>{rule.metric} · {rule.operator.replaceAll('_', ' ')} {rule.threshold ?? ''} · {rule.ad_account?.name || rule.client?.name || 'Workspace-wide'}</small></div><span>{rule.enabled ? 'Active' : 'Paused'}</span>{canManage && rule.client_id && <button onClick={() => window.confirm('Delete this policy?') && remove.mutate(rule.id)}><Trash2 size={14}/></button>}</div>)}</div></>}
  </Page>
}

function PolicyForm({ value, onChange, templates = [], template = false, onSubmit, pending }) {
  return <form className="ops-form compact" onSubmit={event => { event.preventDefault(); onSubmit() }}>
    {!template && <label><span>Start from template</span><select onChange={event => { const selected = templates.find(item => String(item.id) === event.target.value); if (selected) onChange({ ...value, name: selected.name, metric: selected.metric, operator: selected.operator, threshold: selected.threshold ?? '', severity: selected.severity, alert_template_id: selected.id }) }}><option value="">Custom</option>{templates.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>}
    {template && <label><span>Key</span><input required pattern="[a-z0-9_-]+" value={value.key} onChange={event => onChange({ ...value, key: event.target.value.toLowerCase().replaceAll(' ', '_') })}/></label>}
    <label><span>Name</span><input required value={value.name} onChange={event => onChange({ ...value, name: event.target.value })}/></label>
    {template && <label><span>Description</span><input value={value.description} onChange={event => onChange({ ...value, description: event.target.value })}/></label>}
    <label><span>Metric</span><select value={value.metric} onChange={event => onChange({ ...value, metric: event.target.value })}>{metrics.map(item => <option key={item}>{item}</option>)}</select></label>
    <label><span>Operator</span><select value={value.operator} onChange={event => onChange({ ...value, operator: event.target.value })}>{operators.map(item => <option key={item}>{item}</option>)}</select></label>
    <label><span>Threshold</span><input type="number" value={value.threshold ?? ''} onChange={event => onChange({ ...value, threshold: event.target.value === '' ? null : Number(event.target.value) })}/></label>
    <label><span>Severity</span><select value={value.severity} onChange={event => onChange({ ...value, severity: event.target.value })}><option value="info">Info</option><option value="warning">Warning</option><option value="critical">Critical</option></select></label>
    <button className="ops-primary" disabled={pending}>{pending ? 'Saving…' : template ? 'Save template' : 'Save policy'}</button>
  </form>
}
