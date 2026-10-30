import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { BellRing, Check, Info, Pencil, Plus, Trash2, X } from 'lucide-react'
import { api } from '../api/client'
import { AsyncStateNotice } from '../components/ui/AsyncState'
import ConfirmDialog from '../components/ui/ConfirmDialog'
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
const defaultRule = { name: '', metric: 'cpl', operator: 'percentage_increase', threshold: 25, severity: 'warning', window_days: 7, minimum_spend: null, minimum_impressions: null, enabled: true }
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
  const [editingPolicy, setEditingPolicy] = useState(null)
  const [templateOpen, setTemplateOpen] = useState(false)
  const [editingTemplate, setEditingTemplate] = useState(null)
  const [pendingRemoval, setPendingRemoval] = useState(null)
  const [form, setForm] = useState(defaultRule)
  const [templateForm, setTemplateForm] = useState(defaultTemplate)
  const requestedView = search.get('view') || 'templates'
  const view = requestedView === 'overrides' ? 'policies' : requestedView === 'workspace' && user?.role !== 'admin' ? 'templates' : requestedView
  const clientId = search.get('client_id') || ''
  const accountId = search.get('ad_account_id') || ''
  const filters = { client_id: clientId || undefined, ad_account_id: accountId || undefined }
  const ruleFilters = view === 'workspace' ? { scope: 'workspace' } : filters
  const rules = useQuery({ queryKey: ['rules', ruleFilters], queryFn: () => api.getAlertRules(ruleFilters).then(response => response.data), enabled: view === 'policies' || view === 'workspace' })
  const templates = useQuery({ queryKey: ['alert-templates'], queryFn: () => api.getAlertTemplates().then(response => response.data) })
  const setFilter = (key, value) => setSearch(current => { const next = new URLSearchParams(current); value ? next.set(key, value) : next.delete(key); return next }, { replace: true })
  const setScope = (nextClientId, nextAccountId) => { const next = new URLSearchParams(search); nextClientId ? next.set('client_id', nextClientId) : next.delete('client_id'); nextAccountId ? next.set('ad_account_id', nextAccountId) : next.delete('ad_account_id'); setSearch(next, { replace: true }) }
  const savePolicy = useMutation({
    mutationFn: () => {
      const payload = { ...form, client_id: view === 'workspace' ? null : clientId ? Number(clientId) : null, ad_account_id: view === 'workspace' ? null : accountId ? Number(accountId) : null, campaign_id: null }
      return editingPolicy ? api.updateAlertRule(editingPolicy.id, payload) : api.createAlertRule(payload)
    },
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['rules'] }); setPolicyOpen(false); setEditingPolicy(null); setForm(defaultRule); toast.success(editingPolicy ? 'Policy updated.' : 'Policy created.') },
    onError: error => toast.error(error.response?.data?.message || 'Policy could not be saved.'),
  })
  const openPolicy = policy => {
    setEditingPolicy(policy || null)
    setForm(policy ? { name: policy.name, metric: policy.metric, operator: policy.operator, threshold: policy.threshold, severity: policy.severity, window_days: policy.window_days || 1, minimum_spend: policy.minimum_spend, minimum_impressions: policy.minimum_impressions, enabled: policy.enabled, alert_template_id: policy.alert_template_id } : defaultRule)
    savePolicy.reset()
    setPolicyOpen(true)
  }
  const closePolicy = () => { if (savePolicy.isPending) return; setPolicyOpen(false); setEditingPolicy(null); setForm(defaultRule); savePolicy.reset() }
  const saveTemplate = useMutation({
    mutationFn: () => editingTemplate ? api.updateAlertTemplate(editingTemplate.id, templateForm) : api.createAlertTemplate(templateForm),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['alert-templates'] }); setTemplateOpen(false); setEditingTemplate(null); setTemplateForm(defaultTemplate); toast.success(editingTemplate ? 'Alert template updated.' : 'Alert template created.') },
  })
  const openTemplate = template => {
    setEditingTemplate(template || null)
    setTemplateForm(template ? { key: template.key, name: template.name, description: template.description || '', metric: template.metric, operator: template.operator, threshold: template.threshold, severity: template.severity, active: template.active } : defaultTemplate)
    saveTemplate.reset()
    setTemplateOpen(true)
  }
  const closeTemplate = () => { if (saveTemplate.isPending) return; setTemplateOpen(false); setEditingTemplate(null); setTemplateForm(defaultTemplate); saveTemplate.reset() }
  const removePolicy = useMutation({ mutationFn: id => api.deleteAlertRule(id), onSuccess: () => { qc.invalidateQueries({ queryKey: ['rules'] }); setPendingRemoval(null); toast.success('Scoped policy removed.') }, onError: error => toast.error(error.response?.data?.message || 'Policy could not be removed.') })
  const removeWorkspacePolicy = useMutation({ mutationFn: id => api.deleteWorkspaceAlertRule(id), onSuccess: () => { qc.invalidateQueries({ queryKey: ['rules'] }); setPendingRemoval(null); toast.success('Workspace policy deleted.') }, onError: error => toast.error(error.response?.data?.message || 'Workspace policy could not be deleted.') })
  const removeTemplate = useMutation({ mutationFn: id => api.deleteAlertTemplate(id), onSuccess: () => { qc.invalidateQueries({ queryKey: ['alert-templates'] }); setPendingRemoval(null); toast.success('Alert template deleted.') }, onError: error => toast.error(error.response?.data?.message || 'Template could not be deleted.') })
  const canManage = user?.role !== 'viewer'
  const removing = removePolicy.isPending || removeWorkspacePolicy.isPending || removeTemplate.isPending

  return <Page title="Alert policies & templates" subtitle="Templates are reusable blueprints; scoped policies are the live rules that evaluate performance.">
    <AsyncStateNotice queries={[rules, templates]}/>
    <nav className="report-view-tabs" aria-label="Alert policy views"><button className={view === 'templates' ? 'active' : ''} onClick={() => setFilter('view', 'templates')}>Workspace templates</button>{user?.role === 'admin' && <button className={view === 'workspace' ? 'active' : ''} onClick={() => setFilter('view', 'workspace')}>Workspace policies</button>}<button className={view === 'policies' ? 'active' : ''} onClick={() => setFilter('view', 'policies')}>Scoped policies</button></nav>

    {view === 'templates' && <>
      <div className="template-kind-note"><Info size={15}/><div><strong>Templates do not generate alerts by themselves.</strong><span>They provide reusable starting settings. Create a scoped policy to apply a template to a client or ad account.</span></div></div>
      <div className="ops-toolbar">{user?.role === 'admin' && <button className="ops-primary" onClick={() => openTemplate()}><Plus size={14}/> Add template</button>}</div>
      <div className="policy-template-grid">{(templates.data || []).map(template => <article key={template.id}>
        <header><i className="rule-dot" data-severity={template.severity}/><div><h2>{template.name}</h2><p>{template.description || 'Reusable alert blueprint'}</p></div><span>{template.active ? 'Available' : 'Paused'}</span></header>
        <dl><div><dt>Metric</dt><dd>{metric(template.metric).label}</dd></div><div><dt>Condition</dt><dd>{operator(template.operator).label} {template.threshold ?? ''}</dd></div><div><dt>Severity</dt><dd>{template.severity}</dd></div><div><dt>Key</dt><dd className="mono">{template.key}</dd></div></dl>
        {user?.role === 'admin' && <footer className="template-card-actions"><button onClick={() => openTemplate(template)} aria-label={`Edit ${template.name}`}><Pencil size={13}/> Edit</button><button className="danger" onClick={() => setPendingRemoval({ type: 'template', item: template })} aria-label={`Delete ${template.name}`}><Trash2 size={13}/> Delete</button></footer>}
      </article>)}</div>
    </>}

    {view === 'workspace' && <>
      <div className="template-kind-note workspace-policy-note"><Info size={15}/><div><strong>Workspace policies are live defaults for every client.</strong><span>More-specific client, account, or campaign policies override a matching workspace policy. Changes here can affect the entire workspace.</span></div></div>
      <div className="ops-toolbar"><button className="ops-primary" onClick={() => openPolicy()}><Plus size={14}/> Add workspace policy</button></div>
      {policyOpen && <section className="workspace-policy-editor"><header><div><strong>{editingPolicy ? 'Edit workspace policy' : 'New workspace policy'}</strong><small>Define the live rule, comparison window, and data guardrails used across all clients.</small></div><button aria-label="Close policy editor" onClick={closePolicy}><X size={14}/></button></header><PolicyForm value={form} onChange={setForm} templates={templates.data || []} onSubmit={() => savePolicy.mutate()} onCancel={closePolicy} pending={savePolicy.isPending}/></section>}
      <div className="ops-list">{(rules.data || []).map(rule => <div className="ops-list-row" key={rule.id}><i className="rule-dot" data-severity={rule.severity}/><div><strong>{rule.name}</strong><small>{metric(rule.metric).label} · {operator(rule.operator).label} {rule.threshold ?? ''} · {rule.window_days} day window</small></div><span>{rule.enabled ? 'Active' : 'Paused'}</span><div className="workspace-policy-actions"><button aria-label={`Edit ${rule.name}`} onClick={() => openPolicy(rule)}><Pencil size={13}/> Edit</button><button className="policy-remove-button" aria-label={`Delete ${rule.name}`} onClick={() => setPendingRemoval({ type: 'workspace-policy', item: rule })}><Trash2 size={13}/> Delete</button></div></div>)}</div>
      {!rules.isPending && !(rules.data || []).length && <p className="workspace-policy-empty">No workspace-wide policies are defined. Client and account policies continue to work independently.</p>}
    </>}

    {view === 'policies' && <>
      <ScopeFilterBar clientId={clientId} accountId={accountId} onScopeChange={setScope} onAccountChange={value => setFilter('ad_account_id', value)}/>
      {clientId && <div className="template-kind-note policy-note"><Info size={15}/><div><strong>These are live policies available to the selected client.</strong><span>Client and account policies can be removed here. Workspace-wide policies are inherited and cannot be removed from a client.</span></div></div>}
      <div className="ops-toolbar">{canManage && clientId && <button className="ops-primary" onClick={() => openPolicy()}><Plus size={14}/> Add policy</button>}</div>
      {policyOpen && canManage && clientId && <PolicyForm value={form} onChange={setForm} templates={templates.data || []} onSubmit={() => savePolicy.mutate()} onCancel={closePolicy} pending={savePolicy.isPending}/>}<div className="ops-list">{(rules.data || []).map(rule => <div className="ops-list-row" key={rule.id}><i className="rule-dot" data-severity={rule.severity}/><div><strong>{rule.name}</strong><small>{metric(rule.metric).label} · {operator(rule.operator).label} {rule.threshold ?? ''} · {rule.ad_account?.name || rule.client?.name || 'Workspace-wide'}</small></div><span>{rule.client_id ? (rule.enabled ? 'Active' : 'Paused') : 'Inherited'}</span>{canManage && rule.client_id && <button className="policy-remove-button" aria-label={`Remove ${rule.name}`} onClick={() => setPendingRemoval({ type: 'policy', item: rule })}><Trash2 size={13}/> Remove</button>}</div>)}</div>
    </>}

    <TemplateDialog editing={Boolean(editingTemplate)} open={templateOpen} value={templateForm} onChange={setTemplateForm} onClose={closeTemplate} onSubmit={() => saveTemplate.mutate()} pending={saveTemplate.isPending} error={saveTemplate.error}/>
    <ConfirmDialog open={Boolean(pendingRemoval)} title={pendingRemoval?.type === 'template' ? 'Delete alert template?' : pendingRemoval?.type === 'workspace-policy' ? 'Delete workspace policy?' : 'Remove scoped policy?'} description={pendingRemoval?.type === 'template' ? `Existing policies created from “${pendingRemoval.item.name}” will continue running with their copied settings. The template will no longer be available for new policies.` : pendingRemoval?.type === 'workspace-policy' ? `Delete “${pendingRemoval.item.name}” for the entire workspace? It will stop future evaluations wherever a more-specific policy does not override it. Existing incident history remains available.` : `Remove “${pendingRemoval?.item?.name}” from this client? Future evaluations will stop, while existing incident history remains available.`} confirmLabel={pendingRemoval?.type === 'template' ? 'Delete template' : pendingRemoval?.type === 'workspace-policy' ? 'Delete workspace policy' : 'Remove policy'} tone="danger" busy={removing} onClose={() => !removing && setPendingRemoval(null)} onConfirm={() => pendingRemoval?.type === 'template' ? removeTemplate.mutate(pendingRemoval.item.id) : pendingRemoval?.type === 'workspace-policy' ? removeWorkspacePolicy.mutate(pendingRemoval.item.id) : removePolicy.mutate(pendingRemoval.item.id)}/>
  </Page>
}

function TemplateDialog({ editing, open, value, onChange, onClose, onSubmit, pending, error }) {
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
        <div className="alert-template-title"><span><BellRing size={17}/></span><div><p>Reusable blueprint</p><h2 id="template-dialog-title">{editing ? 'Edit alert template' : 'Create alert template'}</h2><small>Template changes affect future policy creation only.</small></div></div>
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
      <footer><button type="button" disabled={pending} onClick={onClose}>Cancel</button><button className="ops-primary" disabled={!valid || pending}>{pending ? 'Saving…' : <><Check size={14}/> {editing ? 'Save changes' : 'Create template'}</>}</button></footer>
    </form>
  </div>
}

function Field({ label, help, children }) {
  return <label className="template-field"><span>{label}</span>{children}<small>{help}</small></label>
}

function PolicyForm({ value, onChange, templates = [], onSubmit, onCancel, pending }) {
  const needsThreshold = !thresholdlessOperators.has(value.operator)
  const forcedMetric = operatorMetrics[value.operator]
  const changeOperator = nextOperator => onChange({ ...value, operator: nextOperator, metric: operatorMetrics[nextOperator] || value.metric, threshold: thresholdlessOperators.has(nextOperator) ? null : value.threshold ?? 25 })
  return <form className="ops-form compact" onSubmit={event => { event.preventDefault(); onSubmit() }}>
    <label title="Start with reusable metric, condition, threshold, and severity settings."><span>Start from template</span><select value={value.alert_template_id || ''} onChange={event => { const selected = templates.find(item => String(item.id) === event.target.value); if (selected) onChange({ ...value, name: selected.name, metric: selected.metric, operator: selected.operator, threshold: selected.threshold ?? '', severity: selected.severity, alert_template_id: selected.id }); else onChange({ ...value, alert_template_id: null }) }}><option value="">Custom</option>{templates.filter(item => item.active).map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
    <label title="A clear operational name shown in the alert inbox."><span>Policy name</span><input required value={value.name} onChange={event => onChange({ ...value, name: event.target.value })} placeholder="e.g. Workspace CPL increased"/></label>
    <label title={metric(value.metric).help}><span>Metric</span><select disabled={Boolean(forcedMetric)} value={value.metric} onChange={event => onChange({ ...value, metric: event.target.value })}>{metrics.map(item => <option value={item.value} key={item.value}>{item.label}</option>)}</select></label>
    <label title={operator(value.operator).help}><span>Condition</span><select value={value.operator} onChange={event => changeOperator(event.target.value)}>{operators.map(item => <option value={item.value} key={item.value}>{item.label}</option>)}</select></label>
    <label title={needsThreshold ? `Trigger point in ${thresholdUnit(value.operator, value.metric)}.` : 'This condition does not use a threshold.'}><span>Threshold {needsThreshold ? `(${thresholdUnit(value.operator, value.metric)})` : ''}</span><input required={needsThreshold} disabled={!needsThreshold} min="0" step="any" type="number" value={value.threshold ?? ''} onChange={event => onChange({ ...value, threshold: event.target.value === '' ? null : Number(event.target.value) })}/></label>
    <label title="Number of calendar days in the observed period. Percentage comparisons use the equal-length preceding period as baseline."><span>Window (days)</span><input required min="1" max="90" type="number" value={value.window_days} onChange={event => onChange({ ...value, window_days: Number(event.target.value) })}/></label>
    <label title="Optional guardrail. The policy does not trigger until this much spend is recorded."><span>Minimum spend</span><input min="0" step="any" type="number" value={value.minimum_spend ?? ''} placeholder="Optional" onChange={event => onChange({ ...value, minimum_spend: event.target.value === '' ? null : Number(event.target.value) })}/></label>
    <label title="Optional guardrail. The policy does not trigger until this many impressions are recorded."><span>Minimum impressions</span><input min="0" step="1" type="number" value={value.minimum_impressions ?? ''} placeholder="Optional" onChange={event => onChange({ ...value, minimum_impressions: event.target.value === '' ? null : Number(event.target.value) })}/></label>
    <label title="Controls triage priority in the alert inbox."><span>Severity</span><select value={value.severity} onChange={event => onChange({ ...value, severity: event.target.value })}><option value="info">Info</option><option value="warning">Warning</option><option value="critical">Critical</option></select></label>
    <label className="ops-check"><input type="checkbox" checked={value.enabled} onChange={event => onChange({ ...value, enabled: event.target.checked })}/><span>Policy enabled</span></label>
    <div className="ops-form-actions"><button type="button" onClick={onCancel} disabled={pending}>Cancel</button><button className="ops-primary" disabled={pending}>{pending ? 'Saving…' : 'Save policy'}</button></div>
  </form>
}
