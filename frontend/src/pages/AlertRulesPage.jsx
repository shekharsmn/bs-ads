import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { BellRing, Check, Info, Plus, Trash2, X } from 'lucide-react'
import { api } from '../api/client'
import { AsyncStateNotice } from '../components/ui/AsyncState'
import ScopeFilterBar from '../components/ScopeFilterBar'
import { useAuth } from '../context/AuthContext'
import { useToast } from '../context/ToastContext'
import { Page } from './ClientsPage'

const metrics = [
  { value: 'spend', label: 'Spend', help: 'Total amount spent in the measurement window.' },
  { value: 'impressions', label: 'Impressions', help: 'Number of times ads were shown.' },
  { value: 'clicks', label: 'Clicks', help: 'Total recorded ad clicks.' },
  { value: 'conversions', label: 'Conversions', help: 'All mapped conversion actions.' },
  { value: 'leads', label: 'Leads', help: 'Mapped lead conversion actions.' },
  { value: 'purchases', label: 'Purchases', help: 'Mapped purchase conversion actions.' },
  { value: 'ctr', label: 'CTR', help: 'Click-through rate: clicks divided by impressions.' },
  { value: 'cpc', label: 'CPC', help: 'Cost per click.' },
  { value: 'cpm', label: 'CPM', help: 'Cost per thousand impressions.' },
  { value: 'cpa', label: 'CPA', help: 'Cost per conversion.' },
  { value: 'cpl', label: 'CPL', help: 'Cost per lead: spend divided by leads.' },
  { value: 'conversion_rate', label: 'Conversion rate', help: 'Conversions as a percentage of clicks.' },
  { value: 'roas', label: 'ROAS', help: 'Conversion value divided by spend.' },
  { value: 'frequency', label: 'Frequency', help: 'Average times each person saw an ad.' },
]

const operators = [
  { value: 'below', label: 'Falls below', help: 'Triggers when the metric is lower than the threshold.' },
  { value: 'above', label: 'Rises above', help: 'Triggers when the metric is higher than the threshold.' },
  { value: 'percentage_drop', label: 'Drops by percentage', help: 'Compares the observed period with the immediately preceding period.' },
  { value: 'percentage_increase', label: 'Increases by percentage', help: 'Compares the observed period with the immediately preceding period.' },
  { value: 'no_spend', label: 'Has no spend', help: 'Triggers when no spend is recorded. No threshold is needed.' },
  { value: 'no_impressions', label: 'Has no impressions', help: 'Triggers when no impressions are recorded. No threshold is needed.' },
  { value: 'no_conversions_with_spend', label: 'Spends without conversions', help: 'Triggers when spend is present but conversions are zero. No threshold is needed.' },
  { value: 'budget_under_pacing', label: 'Budget is under-pacing', help: 'Compares month-to-date spend with expected budget pacing.' },
  { value: 'budget_over_pacing', label: 'Budget is over-pacing', help: 'Compares month-to-date spend with expected budget pacing.' },
  { value: 'data_stale', label: 'Data becomes stale', help: 'Triggers when data has not refreshed for the specified number of hours.' },
  { value: 'connection_error', label: 'Connection has an error', help: 'Triggers when the advertising platform connection is unhealthy. No threshold is needed.' },
]

const thresholdlessOperators = new Set(['no_spend', 'no_impressions', 'no_conversions_with_spend', 'connection_error'])
const operatorMetrics = { no_spend: 'spend', no_impressions: 'impressions', no_conversions_with_spend: 'conversions', budget_under_pacing: 'spend', budget_over_pacing: 'spend', data_stale: 'spend', connection_error: 'spend' }
const defaultRule = { name: '', metric: 'cpl', operator: 'above', threshold: 0, severity: 'warning', window_days: 1 }
const defaultTemplate = { key: '', name: '', description: '', metric: 'cpl', operator: 'percentage_increase', threshold: 25, severity: 'warning', active: true }
const metric = value => metrics.find(item => item.value === value) || metrics[0]
const operator = value => operators.find(item => item.value === value) || operators[0]
const slug = value => value.toLowerCase().trim().replace(/[^a-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '')

function thresholdUnit(value, metricValue) {
  if (['percentage_drop', 'percentage_increase', 'budget_under_pacing', 'budget_over_pacing'].includes(value)) return '%'
  if (value === 'data_stale') return 'hours'
  if (['ctr', 'conversion_rate'].includes(metricValue)) return '%'
  if (metricValue === 'roas') return '×'
  if (['spend', 'cpc', 'cpm', 'cpa', 'cpl'].includes(metricValue)) return 'account currency'
  return metric(metricValue).label
}

function previewSentence(value) {
  const metricName = metric(value.metric).label
  if (value.operator === 'percentage_increase') return `An alert opens when observed ${metricName} is ${value.threshold ?? '—'}% higher than the preceding equal-length period.`
  if (value.operator === 'percentage_drop') return `An alert opens when observed ${metricName} is ${value.threshold ?? '—'}% lower than the preceding equal-length period.`
  if (value.operator === 'above') return `An alert opens when ${metricName} rises above ${value.threshold ?? '—'} ${thresholdUnit(value.operator, value.metric)}.`
  if (value.operator === 'below') return `An alert opens when ${metricName} falls below ${value.threshold ?? '—'} ${thresholdUnit(value.operator, value.metric)}.`
  if (value.operator === 'budget_under_pacing') return `An alert opens when month-to-date spend falls below ${value.threshold ?? '—'}% of expected pacing.`
  if (value.operator === 'budget_over_pacing') return `An alert opens when month-to-date spend rises above ${value.threshold ?? '—'}% of expected pacing.`
  if (value.operator === 'data_stale') return `An alert opens when performance data is more than ${value.threshold ?? '—'} hours old.`
  return operator(value.operator).help
}

export default function AlertRulesPage() {
  const { user } = useAuth()
  const toast = useToast()
  const qc = useQueryClient()
  const [search, setSearch] = useSearchParams()
  const [policyOpen, setPolicyOpen] = useState(false)
  const [templateOpen, setTemplateOpen] = useState(false)
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
  const create = useMutation({ mutationFn: () => api.createAlertRule({ ...form, client_id: clientId ? Number(clientId) : null, ad_account_id: accountId ? Number(accountId) : null }), onSuccess: () => { qc.invalidateQueries({ queryKey: ['rules'] }); setPolicyOpen(false); setForm(defaultRule) } })
  const createTemplate = useMutation({
    mutationFn: () => api.createAlertTemplate(templateForm),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['alert-templates'] }); setTemplateOpen(false); setTemplateForm(defaultTemplate); toast.success('Alert template created.') },
  })
  const closeTemplate = () => { if (createTemplate.isPending) return; setTemplateOpen(false); setTemplateForm(defaultTemplate); createTemplate.reset() }
  const remove = useMutation({ mutationFn: id => api.deleteAlertRule(id), onSuccess: () => qc.invalidateQueries({ queryKey: ['rules'] }) })
  const canManage = user?.role !== 'viewer'

  return <Page title="Alert policies & templates" subtitle="Inspect workspace defaults and create client- or account-scoped policies.">
    <AsyncStateNotice queries={[rules, templates]}/>
    <nav className="report-view-tabs" aria-label="Alert policy views"><button className={view === 'templates' ? 'active' : ''} onClick={() => setFilter('view', 'templates')}>Workspace templates</button><button className={view === 'policies' ? 'active' : ''} onClick={() => setFilter('view', 'policies')}>Scoped policies</button></nav>

    {view === 'templates' && <><div className="ops-toolbar">{user?.role === 'admin' && <button className="ops-primary" onClick={() => setTemplateOpen(true)}><Plus size={14}/> Add template</button>}</div><div className="policy-template-grid">{(templates.data || []).map(template => <article key={template.id}><header><i className="rule-dot" data-severity={template.severity}/><div><h2>{template.name}</h2><p>{template.description || 'Workspace alert template'}</p></div><span>{template.active ? 'Active' : 'Paused'}</span></header><dl><div><dt>Metric</dt><dd>{metric(template.metric).label}</dd></div><div><dt>Condition</dt><dd>{operator(template.operator).label} {template.threshold ?? ''}</dd></div><div><dt>Severity</dt><dd>{template.severity}</dd></div><div><dt>Key</dt><dd className="mono">{template.key}</dd></div></dl></article>)}</div></>}

    {view === 'policies' && <><ScopeFilterBar clientId={clientId} accountId={accountId} onScopeChange={setScope} onAccountChange={value => setFilter('ad_account_id', value)}/><div className="ops-toolbar">{canManage && clientId && <button className="ops-primary" onClick={() => setPolicyOpen(value => !value)}><Plus size={14}/> Add policy</button>}</div>{policyOpen && canManage && <PolicyForm value={form} onChange={setForm} templates={templates.data || []} onSubmit={() => create.mutate()} pending={create.isPending}/>}<div className="ops-list">{(rules.data || []).map(rule => <div className="ops-list-row" key={rule.id}><i className="rule-dot" data-severity={rule.severity}/><div><strong>{rule.name}</strong><small>{metric(rule.metric).label} · {operator(rule.operator).label} {rule.threshold ?? ''} · {rule.ad_account?.name || rule.client?.name || 'Workspace-wide'}</small></div><span>{rule.enabled ? 'Active' : 'Paused'}</span>{canManage && rule.client_id && <button onClick={() => window.confirm('Delete this policy?') && remove.mutate(rule.id)}><Trash2 size={14}/></button>}</div>)}</div></>}

    <TemplateDialog open={templateOpen} value={templateForm} onChange={setTemplateForm} onClose={closeTemplate} onSubmit={() => createTemplate.mutate()} pending={createTemplate.isPending} error={createTemplate.error}/>
  </Page>
}

function TemplateDialog({ open, value, onChange, onClose, onSubmit, pending, error }) {
  useEffect(() => {
    if (!open) return undefined
    const closeOnEscape = event => { if (event.key === 'Escape' && !pending) onClose() }
    document.addEventListener('keydown', closeOnEscape)
    return () => document.removeEventListener('keydown', closeOnEscape)
  }, [open, onClose, pending])

  if (!open) return null
  const selectedMetric = metric(value.metric)
  const selectedOperator = operator(value.operator)
  const needsThreshold = !thresholdlessOperators.has(value.operator)
  const forcedMetric = operatorMetrics[value.operator]
  const valid = value.name.trim() && value.key.trim() && (!needsThreshold || value.threshold !== null && value.threshold !== '')
  const errorMessage = error?.response?.data?.message || Object.values(error?.response?.data?.errors || {}).flat()[0] || (error ? 'Template could not be saved.' : '')
  const updateName = name => onChange({ ...value, name, key: !value.key || value.key === slug(value.name) ? slug(name) : value.key })
  const updateOperator = nextOperator => onChange({ ...value, operator: nextOperator, metric: operatorMetrics[nextOperator] || value.metric, threshold: thresholdlessOperators.has(nextOperator) ? null : value.threshold ?? 25 })

  return <div className="alert-template-backdrop" onMouseDown={event => event.target === event.currentTarget && onClose()}>
    <form className="alert-template-dialog" role="dialog" aria-modal="true" aria-labelledby="template-dialog-title" onSubmit={event => { event.preventDefault(); if (valid) onSubmit() }}>
      <header>
        <div className="alert-template-title"><span><BellRing size={17}/></span><div><p>Workspace template</p><h2 id="template-dialog-title">Create alert template</h2><small>Define reusable detection logic for scoped alert policies.</small></div></div>
        <button type="button" aria-label="Close template dialog" disabled={pending} onClick={onClose}><X size={17}/></button>
      </header>

      <div className="alert-template-layout">
        <div className="alert-template-fields">
          <section>
            <div className="alert-template-section-head"><span>1</span><div><h3>Name the template</h3><p>Use a name that tells operators exactly what needs attention.</p></div></div>
            <div className="alert-template-grid two">
              <Field label="Template name" help="Shown when someone creates a policy from this template."><input required autoFocus value={value.name} onChange={event => updateName(event.target.value)} placeholder="e.g. Cost per lead increased"/></Field>
              <Field label="Template key" help="Stable internal identifier. Lowercase letters, numbers, hyphens and underscores only."><input required pattern="[a-z0-9_-]+" value={value.key} onChange={event => onChange({ ...value, key: slug(event.target.value) })} placeholder="cost-per-lead-increased"/></Field>
            </div>
            <Field label="Description" help="Explain why this alert matters or what the operator should check first."><textarea rows="2" value={value.description} onChange={event => onChange({ ...value, description: event.target.value })} placeholder="CPL is materially higher than the preceding comparison period."/></Field>
          </section>

          <section>
            <div className="alert-template-section-head"><span>2</span><div><h3>Set the detection logic</h3><p>Choose what is measured, how it is compared, and when it becomes an alert.</p></div></div>
            <div className="alert-template-grid three">
              <Field label="Metric" help={`${selectedMetric.help}${forcedMetric ? ' Selected automatically for this condition.' : ''}`}><select disabled={Boolean(forcedMetric)} value={value.metric} onChange={event => onChange({ ...value, metric: event.target.value })}>{metrics.map(item => <option value={item.value} key={item.value}>{item.label}</option>)}</select></Field>
              <Field label="Condition" help={selectedOperator.help}><select value={value.operator} onChange={event => updateOperator(event.target.value)}>{operators.map(item => <option value={item.value} key={item.value}>{item.label}</option>)}</select></Field>
              <Field label="Threshold" help={needsThreshold ? `Enter the trigger point in ${thresholdUnit(value.operator, value.metric)}.` : 'This condition triggers from state alone, so no threshold is used.'}><div className="threshold-input"><input required={needsThreshold} disabled={!needsThreshold} min="0" step="any" type="number" value={value.threshold ?? ''} onChange={event => onChange({ ...value, threshold: event.target.value === '' ? null : Number(event.target.value) })}/>{needsThreshold && <span>{thresholdUnit(value.operator, value.metric)}</span>}</div></Field>
            </div>
          </section>

          <section>
            <div className="alert-template-section-head"><span>3</span><div><h3>Choose urgency and availability</h3><p>Severity controls triage priority. Paused templates stay saved but cannot be used for new policies.</p></div></div>
            <div className="alert-template-grid two response-grid">
              <Field label="Severity" help="Info is low urgency, warning needs review, and critical needs prompt action."><select value={value.severity} onChange={event => onChange({ ...value, severity: event.target.value })}><option value="info">Info — monitor</option><option value="warning">Warning — investigate</option><option value="critical">Critical — act promptly</option></select></Field>
              <label className="template-active"><button type="button" role="switch" aria-checked={value.active} onClick={() => onChange({ ...value, active: !value.active })}><span/></button><span><strong>Available for new policies</strong><small>{value.active ? 'Admins and marketers can use this template.' : 'The template will be saved in a paused state.'}</small></span></label>
            </div>
          </section>
        </div>

        <aside className="alert-template-preview">
          <p>Rule preview</p>
          <div className="template-preview-rule"><i className="rule-dot" data-severity={value.severity}/><span><strong>{value.name || 'Untitled alert template'}</strong><small>{selectedMetric.label} · {selectedOperator.label}</small></span></div>
          <p className="template-preview-copy">{previewSentence(value)}</p>
          <div className="template-guide"><Info size={14}/><div><strong>How templates work</strong><ul><li>Templates define reusable alert logic.</li><li>A policy supplies the client or account scope and measurement window.</li><li>Percentage comparisons use equal, consecutive periods.</li></ul></div></div>
        </aside>
      </div>

      {errorMessage && <p className="alert-template-error" role="alert">{errorMessage}</p>}
      <footer><button type="button" disabled={pending} onClick={onClose}>Cancel</button><button className="ops-primary" disabled={!valid || pending}>{pending ? 'Creating…' : <><Check size={14}/> Create template</>}</button></footer>
    </form>
  </div>
}

function Field({ label, help, children }) {
  return <label className="template-field"><span>{label}</span>{children}<small>{help}</small></label>
}

function PolicyForm({ value, onChange, templates = [], onSubmit, pending }) {
  return <form className="ops-form compact" onSubmit={event => { event.preventDefault(); onSubmit() }}>
    <label><span>Start from template</span><select onChange={event => { const selected = templates.find(item => String(item.id) === event.target.value); if (selected) onChange({ ...value, name: selected.name, metric: selected.metric, operator: selected.operator, threshold: selected.threshold ?? '', severity: selected.severity, alert_template_id: selected.id }) }}><option value="">Custom</option>{templates.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
    <label><span>Name</span><input required value={value.name} onChange={event => onChange({ ...value, name: event.target.value })}/></label>
    <label><span>Metric</span><select value={value.metric} onChange={event => onChange({ ...value, metric: event.target.value })}>{metrics.map(item => <option value={item.value} key={item.value}>{item.label}</option>)}</select></label>
    <label><span>Operator</span><select value={value.operator} onChange={event => onChange({ ...value, operator: event.target.value })}>{operators.map(item => <option value={item.value} key={item.value}>{item.label}</option>)}</select></label>
    <label><span>Threshold</span><input type="number" value={value.threshold ?? ''} onChange={event => onChange({ ...value, threshold: event.target.value === '' ? null : Number(event.target.value) })}/></label>
    <label><span>Severity</span><select value={value.severity} onChange={event => onChange({ ...value, severity: event.target.value })}><option value="info">Info</option><option value="warning">Warning</option><option value="critical">Critical</option></select></label>
    <button className="ops-primary" disabled={pending}>{pending ? 'Saving…' : 'Save policy'}</button>
  </form>
}
