import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { CalendarClock, ExternalLink, FileCheck, FileText, LoaderCircle, Palette, Plus, X } from 'lucide-react'
import { api } from '../api/client'
import { useAuth } from '../context/AuthContext'
import { Page } from './ClientsPage'
import { useToast } from '../context/ToastContext'
import { AsyncStateNotice } from '../components/ui/AsyncState'
import ScopeFilterBar from '../components/ScopeFilterBar'

const PERIODS = [{ value: 'last_7d', label: 'Last 7 days' }, { value: 'last_30d', label: 'Last 30 days' }, { value: 'month_to_date', label: 'Month to date' }]

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

  return <Page title="Reports" subtitle="Performance snapshots, account audits, schedules, and reusable templates.">
    <AsyncStateNotice queries={[templates, reports, audits]}/>
    <ScopeFilterBar clientId={clientId} accountId={accountId} onScopeChange={setScope} onAccountChange={value => setFilter('ad_account_id', value)}/>
    <nav className="report-view-tabs" aria-label="Report types"><button className={view === 'performance' ? 'active' : ''} onClick={() => setFilter('view', 'performance')}><FileText size={14}/> Performance reports</button><button className={view === 'audits' ? 'active' : ''} onClick={() => setFilter('view', 'audits')}><FileCheck size={14}/> Account audits</button><button className={view === 'templates' ? 'active' : ''} onClick={() => setFilter('view', 'templates')}><Palette size={14}/> Templates</button></nav>

    {view === 'performance' && <>
      {canManage && clientId && <section className="report-now"><div><span className="report-now-icon"><FileText size={17}/></span><div><strong>Generate now</strong><small>{accountId ? 'Create an account-scoped snapshot.' : 'Create a client-wide snapshot.'}</small></div></div><TemplateSelect templates={templates.data || []} value={templateId} onChange={setTemplateId}/><select className="ops-select" aria-label="Reporting period" value={period} onChange={event => setPeriod(event.target.value)}>{PERIODS.map(option => <option key={option.value} value={option.value}>{option.label}</option>)}</select><button className="ops-primary" disabled={generate.isPending} onClick={() => { setNotice(''); generate.mutate() }}>{generate.isPending ? <LoaderCircle className="spin" size={14}/> : <FileText size={14}/>} {generate.isPending ? 'Queuing…' : 'Generate report'}</button></section>}
      {canManage && !clientId && <p className="report-notice">Choose a client to generate or schedule a performance report.</p>}{notice && <p className={`report-notice ${generate.isError ? 'error' : ''}`}>{notice}</p>}
      <div className="report-section-head"><div><h2>Schedules</h2><p>Recurring snapshots run in the client’s timezone.</p></div>{canManage && clientId && <div className="ops-toolbar"><button className="ops-primary secondary" onClick={() => add('weekly')} disabled={save.isPending}><Plus size={14}/> Weekly</button><button className="ops-primary secondary" onClick={() => add('monthly')} disabled={save.isPending}><Plus size={14}/> Monthly</button></div>}</div>
      <div className="ops-list">{(reports.data?.schedules || []).map(schedule => <div className="ops-list-row" key={schedule.id}><CalendarClock size={16}/><div><strong>{schedule.ad_account?.name || schedule.client.name} · {schedule.frequency}</strong><small>{schedule.local_time} client local time · {schedule.ad_account ? 'Account scope' : 'Client scope'}</small></div><span>{schedule.enabled ? 'Active' : 'Paused'}</span></div>)}</div>
      <div className="report-section-head"><div><h2>Generated snapshots</h2><p>Completed links expire 30 days after generation.</p></div></div>
      <div className="ops-list">{(reports.data?.runs || []).map(run => <div className="ops-list-row" key={run.id}><span className={`report-run-state ${run.status}`}>{['pending', 'running'].includes(run.status) ? <LoaderCircle className="spin" size={14}/> : <FileText size={14}/>}</span><div><strong>{run.ad_account?.name || run.client.name} · {run.frequency === 'instant' ? 'Instant' : run.frequency}</strong><small>{run.period_start?.slice(0, 10)} — {run.period_end?.slice(0, 10)} · {run.status} · {run.ad_account ? 'Account scope' : 'Client scope'}</small></div>{run.share_url && <a href={run.share_url} target="_blank" rel="noreferrer">Open <ExternalLink size={13}/></a>}</div>)}</div>
    </>}

    {view === 'audits' && <section><div className="report-section-head"><div><h2>Account audits</h2><p>Manual platform audits and their health grades.</p></div>{canManage && <Link className="ops-primary" to={`/reports/new?client_id=${clientId}&ad_account_id=${accountId}`}>Run account audit</Link>}</div><div className="ops-list">{(audits.data?.data || []).map(report => <Link className="ops-list-row" key={report.id} to={`/reports/${report.id}`}><FileCheck size={16}/><div><strong>{report.ad_account?.name || `${report.platform} account`} · {report.grade || 'Not graded'}</strong><small>{report.period} · {report.status}{report.health_score != null ? ` · ${report.health_score}/100` : ''}</small></div><span>{report.platform}</span></Link>)}</div></section>}

    {view === 'templates' && <section><div className="report-template-toolbar"><div><Palette size={15}/><span><strong>Reusable report templates</strong><small>Workspace defaults and client-specific branding.</small></span></div>{canManage && clientId && <button onClick={() => setShowTemplate(value => !value)}>{showTemplate ? <X size={13}/> : <Plus size={13}/>} {showTemplate ? 'Close' : 'New template'}</button>}</div>{showTemplate && <TemplateEditor value={template} onChange={setTemplate} onSave={() => saveTemplate.mutate()} saving={saveTemplate.isPending}/>}<div className="ops-list">{(templates.data || []).map(item => <div className="ops-list-row" key={item.id}><Palette size={16}/><div><strong>{item.name}</strong><small>{item.client?.name || 'Workspace-wide'} · {(item.sections || []).join(', ')}</small></div><span>{item.is_default ? 'Default' : 'Available'}</span></div>)}</div></section>}
  </Page>
}

function TemplateSelect({ templates, value, onChange }) { return <select className="ops-select" aria-label="Report template" value={value} onChange={event => onChange(event.target.value)}><option value="">Standard template</option>{templates.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select> }
function TemplateEditor({ value, onChange, onSave, saving }) { return <section className="report-template-editor"><div className="report-template-fields"><label><span>Name</span><input value={value.name} onChange={event => onChange({ ...value, name: event.target.value })}/></label><label><span>Report brand</span><input value={value.brand_name} onChange={event => onChange({ ...value, brand_name: event.target.value })}/></label><label><span>Accent</span><input type="color" value={value.brand_color} onChange={event => onChange({ ...value, brand_color: event.target.value })}/></label><label><span>Logo URL</span><input type="url" value={value.logo_url} onChange={event => onChange({ ...value, logo_url: event.target.value })}/></label></div><div className="report-template-sections">{['summary', 'trends', 'campaigns', 'alerts'].map(section => <label key={section}><input type="checkbox" checked={value.sections.includes(section)} onChange={event => onChange({ ...value, sections: event.target.checked ? [...value.sections, section] : value.sections.filter(item => item !== section) })}/><span>{section}</span></label>)}</div><button className="ops-primary" disabled={!value.name.trim() || !value.sections.length || saving} onClick={onSave}>{saving ? 'Saving…' : 'Save template'}</button></section> }
