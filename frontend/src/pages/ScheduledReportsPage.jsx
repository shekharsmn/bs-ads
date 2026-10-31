import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { BellRing, CalendarClock, ExternalLink, FileCheck, FileText, LoaderCircle, Palette, Pencil, Plus, Send, Trash2, X } from 'lucide-react'
import { api } from '../api/client'
import { useAuth } from '../context/AuthContext'
import { Page } from './ClientsPage'
import { useToast } from '../context/ToastContext'
import { AsyncStateNotice } from '../components/ui/AsyncState'
import ScopeFilterBar from '../components/ScopeFilterBar'

const PERIODS = [{ value: 'last_7d', label: 'Last 7 days' }, { value: 'last_30d', label: 'Last 30 days' }, { value: 'month_to_date', label: 'Month to date' }]
const PULSE_PERIODS = [{ value: 'yesterday', label: 'Yesterday' }, { value: 'last_7_days', label: 'Last 7 completed days' }, { value: 'month_to_date', label: 'MTD through yesterday' }, { value: 'last_30_days', label: 'Last 30 completed days' }]

export default function ScheduledReportsPage() {
  const { user } = useAuth()
  const [search, setSearch] = useSearchParams()
  const toast = useToast()
  const qc = useQueryClient()
  const [period, setPeriod] = useState('last_30d')
  const [templateId, setTemplateId] = useState('')
  const [showTemplate, setShowTemplate] = useState(false)
  const [template, setTemplate] = useState({ name: '', brand_name: 'SharkAds', brand_color: '#2563eb', logo_url: '', sections: ['summary', 'trends', 'campaigns', 'alerts'] })
  const [notice, setNotice] = useState('')
  const view = search.get('view') || 'performance'
  const clientId = search.get('client_id') || ''
  const accountId = search.get('ad_account_id') || ''
  const scope = { client_id: clientId || undefined, ad_account_id: accountId || undefined }
  const templates = useQuery({ queryKey: ['report-templates', clientId], queryFn: () => api.getReportTemplates({ client_id: clientId || undefined }).then(response => response.data) })
  const reports = useQuery({ queryKey: ['report-schedules', scope], queryFn: () => api.getReportSchedules(scope).then(response => response.data), refetchInterval: query => query.state.data?.runs?.some(run => ['pending', 'running'].includes(run.status)) ? 3000 : false })
  const audits = useQuery({ queryKey: ['account-audits', scope], queryFn: () => api.getReports({ ...scope, per_page: 25 }).then(response => response.data) })
  const canManage = user?.role === 'admin' || user?.role === 'marketer'
  const setFilter = (key, value) => setSearch(current => { const next = new URLSearchParams(current); value ? next.set(key, value) : next.delete(key); return next }, { replace: true })
  const setScope = (nextClientId, nextAccountId) => { const next = new URLSearchParams(search); nextClientId ? next.set('client_id', nextClientId) : next.delete('client_id'); nextAccountId ? next.set('ad_account_id', nextAccountId) : next.delete('ad_account_id'); setSearch(next, { replace: true }) }
  const save = useMutation({ mutationFn: data => api.saveReportSchedule(data), onSuccess: () => qc.invalidateQueries({ queryKey: ['report-schedules'] }) })
  const generate = useMutation({ mutationFn: () => api.generateReport(Number(clientId), { frequency: 'instant', preset: period, ad_account_id: accountId ? Number(accountId) : null, report_template_id: templateId ? Number(templateId) : null }), onSuccess: async () => { setNotice('Report queued. The share link will appear when the snapshot is ready.'); await qc.invalidateQueries({ queryKey: ['report-schedules'] }) }, onError: error => setNotice(error.response?.data?.message || 'Could not generate the report.') })
  const saveTemplate = useMutation({ mutationFn: () => api.createReportTemplate({ ...template, client_id: Number(clientId), logo_url: template.logo_url || null }), onSuccess: async response => { setTemplateId(String(response.data.id)); setShowTemplate(false); await qc.invalidateQueries({ queryKey: ['report-templates'] }); toast.success('Report template saved.') }, onError: error => toast.error(error.response?.data?.message || 'Template could not be saved.') })
  const add = frequency => save.mutate({ client_id: Number(clientId), ad_account_id: accountId ? Number(accountId) : null, report_template_id: templateId ? Number(templateId) : null, frequency, weekday: frequency === 'weekly' ? 1 : null, day_of_month: frequency === 'monthly' ? 1 : null, local_time: '09:00', enabled: true })

  return <Page title="Reports" subtitle="Performance snapshots, Performance Pulse, account audits, schedules, and reusable templates.">
    <AsyncStateNotice queries={[templates, reports, audits]}/>
    <ScopeFilterBar clientId={clientId} accountId={accountId} onScopeChange={setScope} onAccountChange={value => setFilter('ad_account_id', value)}/>
    <nav className="report-view-tabs" aria-label="Report types"><button className={view === 'performance' ? 'active' : ''} onClick={() => setFilter('view', 'performance')}><FileText size={14}/> Performance reports</button><button className={view === 'pulse' ? 'active' : ''} onClick={() => setFilter('view', 'pulse')}><BellRing size={14}/> Performance Pulse</button><button className={view === 'audits' ? 'active' : ''} onClick={() => setFilter('view', 'audits')}><FileCheck size={14}/> Account audits</button><button className={view === 'templates' ? 'active' : ''} onClick={() => setFilter('view', 'templates')}><Palette size={14}/> Templates</button></nav>

    {view === 'performance' && <>
      {canManage && clientId && <section className="report-now"><div><span className="report-now-icon"><FileText size={17}/></span><div><strong>Generate now</strong><small>{accountId ? 'Create an account-scoped snapshot.' : 'Create a client-wide snapshot.'}</small></div></div><TemplateSelect templates={templates.data || []} value={templateId} onChange={setTemplateId}/><select className="ops-select" aria-label="Reporting period" value={period} onChange={event => setPeriod(event.target.value)}>{PERIODS.map(option => <option key={option.value} value={option.value}>{option.label}</option>)}</select><button className="ops-primary" disabled={generate.isPending} onClick={() => { setNotice(''); generate.mutate() }}>{generate.isPending ? <LoaderCircle className="spin" size={14}/> : <FileText size={14}/>} {generate.isPending ? 'Queuing…' : 'Generate report'}</button></section>}
      {canManage && !clientId && <p className="report-notice">Choose a client to generate or schedule a performance report.</p>}{notice && <p className={`report-notice ${generate.isError ? 'error' : ''}`}>{notice}</p>}
      <div className="report-section-head"><div><h2>Schedules</h2><p>Recurring snapshots run in the client’s timezone.</p></div>{canManage && clientId && <div className="ops-toolbar"><button className="ops-primary secondary" onClick={() => add('weekly')} disabled={save.isPending}><Plus size={14}/> Weekly</button><button className="ops-primary secondary" onClick={() => add('monthly')} disabled={save.isPending}><Plus size={14}/> Monthly</button></div>}</div>
      <div className="ops-list">{(reports.data?.schedules || []).map(schedule => <div className="ops-list-row" key={schedule.id}><CalendarClock size={16}/><div><strong>{schedule.ad_account?.name || schedule.client.name} · {schedule.frequency}</strong><small>{schedule.local_time} client local time · {schedule.ad_account ? 'Account scope' : 'Client scope'}</small></div><span>{schedule.enabled ? 'Active' : 'Paused'}</span></div>)}</div>
      <div className="report-section-head"><div><h2>Generated snapshots</h2><p>Completed links expire 30 days after generation.</p></div></div>
      <div className="ops-list">{(reports.data?.runs || []).map(run => <div className="ops-list-row" key={run.id}><span className={`report-run-state ${run.status}`}>{['pending', 'running'].includes(run.status) ? <LoaderCircle className="spin" size={14}/> : <FileText size={14}/>}</span><div><strong>{run.ad_account?.name || run.client.name} · {run.frequency === 'instant' ? 'Instant' : run.frequency}</strong><small>{run.period_start?.slice(0, 10)} — {run.period_end?.slice(0, 10)} · {run.status} · {run.ad_account ? 'Account scope' : 'Client scope'}</small></div>{run.share_url && <a href={run.share_url} target="_blank" rel="noreferrer">Open <ExternalLink size={13}/></a>}</div>)}</div>
    </>}

    {view === 'pulse' && <PerformancePulsePanel key={`${clientId}:${accountId}`} clientId={clientId} accountId={accountId} canManage={canManage}/>}

    {view === 'audits' && <section><div className="report-section-head"><div><h2>Account audits</h2><p>Manual platform audits and their health grades.</p></div>{canManage && <Link className="ops-primary" to={`/reports/new?client_id=${clientId}&ad_account_id=${accountId}`}>Run account audit</Link>}</div><div className="ops-list">{(audits.data?.data || []).map(report => <Link className="ops-list-row" key={report.id} to={`/reports/${report.id}`}><FileCheck size={16}/><div><strong>{report.ad_account?.name || `${report.platform} account`} · {report.grade || 'Not graded'}</strong><small>{report.period} · {report.status}{report.health_score != null ? ` · ${report.health_score}/100` : ''}</small></div><span>{report.platform}</span></Link>)}</div></section>}

    {view === 'templates' && <section><div className="report-template-toolbar"><div><Palette size={15}/><span><strong>Reusable report templates</strong><small>Workspace defaults and client-specific branding.</small></span></div>{canManage && clientId && <button onClick={() => setShowTemplate(value => !value)}>{showTemplate ? <X size={13}/> : <Plus size={13}/>} {showTemplate ? 'Close' : 'New template'}</button>}</div>{showTemplate && <TemplateEditor value={template} onChange={setTemplate} onSave={() => saveTemplate.mutate()} saving={saveTemplate.isPending}/>}<div className="ops-list">{(templates.data || []).map(item => <div className="ops-list-row" key={item.id}><Palette size={16}/><div><strong>{item.name}</strong><small>{item.client?.name || 'Workspace-wide'} · {(item.sections || []).join(', ')}</small></div><span>{item.is_default ? 'Default' : 'Available'}</span></div>)}</div></section>}
  </Page>
}

function TemplateSelect({ templates, value, onChange }) { return <select className="ops-select" aria-label="Report template" value={value} onChange={event => onChange(event.target.value)}><option value="">Standard template</option>{templates.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select> }
function TemplateEditor({ value, onChange, onSave, saving }) { return <section className="report-template-editor"><div className="report-template-fields"><label><span>Name</span><input value={value.name} onChange={event => onChange({ ...value, name: event.target.value })}/></label><label><span>Report brand</span><input value={value.brand_name} onChange={event => onChange({ ...value, brand_name: event.target.value })}/></label><label><span>Accent</span><input type="color" value={value.brand_color} onChange={event => onChange({ ...value, brand_color: event.target.value })}/></label><label><span>Logo URL</span><input type="url" value={value.logo_url} onChange={event => onChange({ ...value, logo_url: event.target.value })}/></label></div><div className="report-template-sections">{['summary', 'trends', 'campaigns', 'alerts'].map(section => <label key={section}><input type="checkbox" checked={value.sections.includes(section)} onChange={event => onChange({ ...value, sections: event.target.checked ? [...value.sections, section] : value.sections.filter(item => item !== section) })}/><span>{section}</span></label>)}</div><button className="ops-primary" disabled={!value.name.trim() || !value.sections.length || saving} onClick={onSave}>{saving ? 'Saving…' : 'Save template'}</button></section> }

const emptyPulse = { campaign_id: '', periods: ['yesterday', 'month_to_date'], local_time: '09:00', include_campaign_breakdown: false, destination_ids: null, enabled: false }
const emptyPulseDestination = { name: '', channel: 'google_chat', endpoint: '', secret: '' }

function PerformancePulsePanel({ clientId, accountId, canManage }) {
  const qc = useQueryClient(); const toast = useToast(); const [editingId, setEditingId] = useState(null); const [form, setForm] = useState(emptyPulse)
  const [showDestinationForm, setShowDestinationForm] = useState(false); const [destinationForm, setDestinationForm] = useState(emptyPulseDestination)
  const scope = { client_id: clientId || undefined, ad_account_id: accountId || undefined }
  const hasCreationScope = Boolean(clientId && accountId)
  const pulses = useQuery({ queryKey: ['performance-pulses', scope], queryFn: () => api.getPerformancePulses(scope).then(response => response.data), refetchInterval: query => (query.state.data || []).some(pulse => pulse.runs?.some(run => ['pending', 'generating', 'queued'].includes(run.status))) ? 3000 : false })
  const options = useQuery({ queryKey: ['performance-pulse-options', clientId, accountId], queryFn: () => api.getPerformancePulseOptions(scope).then(response => response.data), enabled: hasCreationScope })
  const refresh = () => qc.invalidateQueries({ queryKey: ['performance-pulses'] })
  const preferredDestination = options.data?.destinations?.find(item => item.channel === 'google_chat') || options.data?.destinations?.[0]
  const destinationIds = form.destination_ids ?? (preferredDestination ? [preferredDestination.id] : [])
  const canUseLegacyDefault = !options.data?.destinations?.length && options.data?.legacy_google_chat_configured
  const usesLegacyDefault = canUseLegacyDefault && form.destination_ids === null
  const hasSelectedDestination = destinationIds.length > 0 || usesLegacyDefault
  const save = useMutation({
    mutationFn: payload => editingId ? api.updatePerformancePulse(editingId, payload) : api.createPerformancePulse(payload),
    onSuccess: () => { refresh(); setEditingId(null); setForm(emptyPulse); toast.success('Performance Pulse saved.') },
    onError: error => toast.error(error.response?.data?.message || Object.values(error.response?.data?.errors || {})[0]?.[0] || 'Unable to save Performance Pulse.'),
  })
  const send = useMutation({ mutationFn: id => api.sendPerformancePulse(id), onSuccess: () => { refresh(); toast.success('Performance Pulse queued.') }, onError: error => toast.error(error.response?.data?.message || 'Unable to send Performance Pulse.') })
  const update = useMutation({ mutationFn: ({ id, data }) => api.updatePerformancePulse(id, data), onSuccess: refresh, onError: error => toast.error(error.response?.data?.message || 'Unable to update Performance Pulse.') })
  const remove = useMutation({ mutationFn: id => api.deletePerformancePulse(id), onSuccess: () => { refresh(); toast.success('Performance Pulse deleted.') } })
  const createDestination = useMutation({
    mutationFn: () => {
      const configuration = destinationForm.channel === 'email' ? { address: destinationForm.endpoint.trim() } : { url: destinationForm.endpoint.trim() }
      if (destinationForm.channel === 'webhook' && destinationForm.secret.trim()) configuration.secret = destinationForm.secret.trim()
      return api.createNotificationDestination({ client_id: Number(clientId), name: destinationForm.name.trim(), channel: destinationForm.channel, configuration, events: [], enabled: true })
    },
    onSuccess: response => {
      const destination = response.data
      qc.setQueryData(['performance-pulse-options', clientId, accountId], current => ({ ...current, destinations: [...(current?.destinations || []).filter(item => item.id !== destination.id), destination] }))
      setForm(current => ({ ...current, destination_ids: [...new Set([...(current.destination_ids ?? destinationIds), destination.id])] }))
      setDestinationForm(emptyPulseDestination); setShowDestinationForm(false); toast.success('Destination created and selected.')
    },
    onError: error => toast.error(Object.values(error.response?.data?.errors || {})[0]?.[0] || error.response?.data?.message || 'Unable to create destination.'),
  })
  const edit = pulse => { setEditingId(pulse.id); setForm({ campaign_id: pulse.campaign_id || '', periods: pulse.periods, local_time: pulse.local_time.slice(0, 5), include_campaign_breakdown: pulse.include_campaign_breakdown, destination_ids: pulse.destinations.map(item => item.id), enabled: pulse.enabled }) }
  const submit = event => {
    event.preventDefault()
    const payload = { ...form, client_id: Number(clientId), ad_account_id: Number(accountId), campaign_id: form.campaign_id ? Number(form.campaign_id) : null }
    if (form.destination_ids !== null) payload.destination_ids = destinationIds.map(Number)
    else if (destinationIds.length) payload.destination_ids = destinationIds.map(Number)
    else delete payload.destination_ids
    save.mutate(payload)
  }
  const toggleDestination = (id, checked) => setForm(current => ({ ...current, destination_ids: checked ? [...new Set([...destinationIds, id])] : destinationIds.filter(value => value !== id) }))
  const destinationReady = destinationForm.name.trim() && destinationForm.endpoint.trim()

  return <section className="pulse-panel">
    <AsyncStateNotice queries={hasCreationScope ? [pulses, options] : [pulses]}/>
    {canManage && !hasCreationScope && <p className="report-notice">Showing configured Performance Pulses. Choose a client and ad account to create a new one.</p>}
    {canManage && hasCreationScope && <form className="pulse-editor" onSubmit={submit}>
      <div className="report-section-head"><div><h2>{editingId ? 'Edit Performance Pulse' : 'New Performance Pulse'}</h2><p>Daily delivery uses the ad account timezone. Scheduling is off until you enable it.</p></div>{editingId && <button type="button" onClick={() => { setEditingId(null); setForm(emptyPulse) }}><X size={13}/> Cancel</button>}</div>
      <div className="pulse-fields">
        <label><span>Scope</span><select value={form.campaign_id} onChange={event => setForm({ ...form, campaign_id: event.target.value, include_campaign_breakdown: event.target.value ? false : form.include_campaign_breakdown })}><option value="">Ad account</option>{(options.data?.campaigns || []).map(campaign => <option key={campaign.id} value={campaign.id}>{campaign.name}</option>)}</select></label>
        {[0, 1].map(index => <label key={index}><span>Period {index + 1}</span><select value={form.periods[index]} onChange={event => setForm({ ...form, periods: form.periods.map((value, itemIndex) => itemIndex === index ? event.target.value : value) })}>{PULSE_PERIODS.map(period => <option key={period.value} value={period.value} disabled={form.periods[1 - index] === period.value}>{period.label}</option>)}</select></label>)}
        <label><span>Daily time</span><input type="time" required value={form.local_time} onChange={event => setForm({ ...form, local_time: event.target.value })}/></label>
      </div>
      {!form.campaign_id && <label className="ops-check"><input type="checkbox" checked={form.include_campaign_breakdown} onChange={event => setForm({ ...form, include_campaign_breakdown: event.target.checked })}/><span>Include campaign breakdown after account totals</span></label>}
      <fieldset className="pulse-destinations"><legend>Destinations</legend>{(options.data?.destinations || []).map(destination => <label key={destination.id}><input type="checkbox" checked={destinationIds.includes(destination.id)} onChange={event => toggleDestination(destination.id, event.target.checked)}/><span>{destination.name}<small>{destination.channel.replaceAll('_', ' ')}</small></span></label>)}{canUseLegacyDefault && <label><input type="checkbox" checked={usesLegacyDefault} onChange={event => setForm({ ...form, destination_ids: event.target.checked ? null : [] })}/><span>Client default Google Chat<small>google chat</small></span></label>}{!options.data?.destinations?.length && !canUseLegacyDefault && <p>No destinations yet. Add one here, or save this pulse while paused.</p>}<button type="button" className="pulse-add-destination" onClick={() => setShowDestinationForm(value => !value)}><Plus size={13}/>{showDestinationForm ? 'Close' : 'Add destination'}</button></fieldset>
      {showDestinationForm && <section className="pulse-destination-creator" onKeyDown={event => { if (event.key === 'Enter') { event.preventDefault(); if (destinationReady && !createDestination.isPending) createDestination.mutate() } }}>
        <header><div><strong>Add a delivery destination</strong><small>Saved securely for this client and selected for this pulse.</small></div></header>
        <div><label><span>Name</span><input autoFocus value={destinationForm.name} placeholder="e.g. Daily performance chat" onChange={event => setDestinationForm({ ...destinationForm, name: event.target.value })}/></label><label><span>Channel</span><select value={destinationForm.channel} onChange={event => setDestinationForm({ ...destinationForm, channel: event.target.value, endpoint: '', secret: '' })}><option value="google_chat">Google Chat</option><option value="email">Email</option><option value="slack">Slack</option><option value="teams">Microsoft Teams</option><option value="webhook">Generic webhook</option></select></label><label className="pulse-destination-endpoint"><span>{destinationForm.channel === 'email' ? 'Email address' : 'HTTPS webhook URL'}</span><input type={destinationForm.channel === 'email' ? 'email' : 'url'} value={destinationForm.endpoint} placeholder={destinationForm.channel === 'email' ? 'performance@example.com' : 'https://…'} onChange={event => setDestinationForm({ ...destinationForm, endpoint: event.target.value })}/></label>{destinationForm.channel === 'webhook' && <label><span>Signing secret (optional)</span><input type="password" value={destinationForm.secret} onChange={event => setDestinationForm({ ...destinationForm, secret: event.target.value })}/></label>}</div>
        <footer><button type="button" onClick={() => { setShowDestinationForm(false); setDestinationForm(emptyPulseDestination) }}>Cancel</button><button type="button" className="ops-primary" disabled={!destinationReady || createDestination.isPending} onClick={() => createDestination.mutate()}>{createDestination.isPending ? 'Creating…' : 'Create and select'}</button></footer>
      </section>}
      <label className="ops-check"><input type="checkbox" checked={form.enabled} onChange={event => setForm({ ...form, enabled: event.target.checked })}/><span>Enable daily schedule</span></label>
      {!hasSelectedDestination && <p className="pulse-destination-warning">No destination selected. You can save this pulse while paused, then add a destination before enabling or sending it.</p>}
      <button className="ops-primary" disabled={save.isPending || form.periods[0] === form.periods[1] || (form.enabled && !hasSelectedDestination)}>{save.isPending ? 'Saving…' : editingId ? 'Save changes' : 'Create Performance Pulse'}</button>
    </form>}
    <div className="report-section-head"><div><h2>{clientId || accountId ? 'Configured pulses' : 'All configured pulses'}</h2><p>Send instantly or manage the daily schedule independently.</p></div></div>
    <div className="pulse-list">{(pulses.data || []).map(pulse => <article key={pulse.id} className="pulse-card">
      <header><span><BellRing size={16}/></span><div><strong>{pulse.campaign?.name || pulse.ad_account.name}</strong><small>{pulse.client.name} · {pulse.ad_account.platform} · {pulse.local_time.slice(0, 5)} {pulse.ad_account.timezone} · {pulse.periods.map(value => PULSE_PERIODS.find(item => item.value === value)?.label).join(' + ')}</small></div><em data-status={pulse.enabled ? 'active' : 'paused'}>{pulse.enabled ? 'Active' : 'Paused'}</em></header>
      <div className="destination-events">{pulse.destinations.map(item => <small key={item.id}>{item.name} · {item.channel.replaceAll('_', ' ')}</small>)}{!pulse.destinations.length && <small>No destination selected</small>}</div>
      {canManage && <div className="pulse-actions"><button onClick={() => send.mutate(pulse.id)} disabled={send.isPending || !pulse.destinations.length} title={!pulse.destinations.length ? 'Add a destination before sending.' : undefined}><Send size={13}/> Send now</button><button onClick={() => update.mutate({ id: pulse.id, data: { enabled: !pulse.enabled } })} disabled={!pulse.enabled && !pulse.destinations.length} title={!pulse.enabled && !pulse.destinations.length ? 'Add a destination before enabling.' : undefined}>{pulse.enabled ? 'Pause' : 'Enable'}</button><button onClick={() => edit(pulse)}><Pencil size={13}/> Edit</button><button className="icon-danger" aria-label={`Delete ${pulse.campaign?.name || pulse.ad_account.name}`} onClick={() => remove.mutate(pulse.id)}><Trash2 size={13}/></button></div>}
      <div className="pulse-history">{(pulse.runs || []).map(run => <div key={run.id}><span><strong>{run.trigger === 'manual' ? 'Sent now' : 'Scheduled'} · {run.reporting_date?.slice(0, 10)}</strong><small>{new Date(run.created_at).toLocaleString()}{run.warning ? ` · ${run.warning}` : ''}</small></span><em data-status={run.status}>{run.status}</em><div>{(run.deliveries || []).map(delivery => <small key={delivery.id}>{delivery.destination?.name} · part {delivery.part_number || 1}/{delivery.part_count || 1} · {delivery.status}</small>)}</div></div>)}</div>
    </article>)}{!pulses.isLoading && !(pulses.data || []).length && <p className="scoped-empty">No Performance Pulses are configured for this scope.</p>}</div>
  </section>
}
