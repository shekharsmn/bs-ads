import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarClock, ExternalLink, FileText, LoaderCircle, Palette, Plus, X } from 'lucide-react'
import { api } from '../api/client'
import { useAuth } from '../context/AuthContext'
import { Page } from './ClientsPage'
import { useWorkspace } from '../context/WorkspaceContext'
import { useToast } from '../context/ToastContext'
import { AsyncStateNotice } from '../components/ui/AsyncState'

const PERIODS = [
  { value: 'last_7d', label: 'Last 7 days' },
  { value: 'last_30d', label: 'Last 30 days' },
  { value: 'month_to_date', label: 'Month to date' },
]

export default function ScheduledReportsPage() {
  const { user } = useAuth()
  const workspace = useWorkspace()
  const toast = useToast()
  const qc = useQueryClient()
  const [period, setPeriod] = useState('last_30d')
  const [templateId, setTemplateId] = useState('')
  const [showTemplate, setShowTemplate] = useState(false)
  const [template, setTemplate] = useState({ name: '', brand_name: 'Brandshark Pulse', brand_color: '#2563eb', logo_url: '', sections: ['summary', 'trends', 'campaigns', 'alerts'] })
  const [notice, setNotice] = useState('')
  const clients = useQuery({ queryKey: ['clients'], queryFn: () => api.getClients().then(response => response.data) })
  const selectedClientId = workspace.clientId || (clients.data?.[0]?.id ? String(clients.data[0].id) : '')
  const templates = useQuery({ queryKey: ['report-templates'], queryFn: () => api.getReportTemplates().then(response => response.data) })
  const reports = useQuery({
    queryKey: ['report-schedules'],
    queryFn: () => api.getReportSchedules().then(response => response.data),
    refetchInterval: query => query.state.data?.runs?.some(run => ['pending', 'running'].includes(run.status)) ? 3000 : false,
  })
  const canManage = user?.role === 'admin' || user?.role === 'marketer'
  const save = useMutation({ mutationFn: data => api.saveReportSchedule(data), onSuccess: () => qc.invalidateQueries({ queryKey: ['report-schedules'] }) })
  const generate = useMutation({
    mutationFn: () => api.generateReport(Number(selectedClientId), { frequency: 'instant', preset: period, report_template_id: templateId ? Number(templateId) : null }),
    onSuccess: async () => {
      setNotice('Report queued. The share link will appear when the snapshot is ready.')
      await qc.invalidateQueries({ queryKey: ['report-schedules'] })
    },
    onError: error => setNotice(error.response?.data?.message || 'Could not generate the report.'),
  })
  const saveTemplate = useMutation({ mutationFn: () => api.createReportTemplate({ ...template, client_id: Number(selectedClientId), logo_url: template.logo_url || null }), onSuccess: async response => { setTemplateId(String(response.data.id)); setShowTemplate(false); await qc.invalidateQueries({ queryKey: ['report-templates'] }); toast.success('Report template saved.') }, onError: error => toast.error(error.response?.data?.message || 'Template could not be saved.') })
  const add = frequency => save.mutate({ client_id: Number(selectedClientId), report_template_id: templateId ? Number(templateId) : null, frequency, weekday: frequency === 'weekly' ? 1 : null, day_of_month: frequency === 'monthly' ? 1 : null, local_time: '09:00', enabled: true })

  return <Page title="Reports" subtitle="Generate a client-safe snapshot now or automate weekly and monthly delivery.">
    <AsyncStateNotice queries={[clients, templates, reports]}/>
    {canManage && <section className="report-now">
      <div><span className="report-now-icon"><FileText size={17}/></span><div><strong>Generate now</strong><small>Create an immutable snapshot from the latest collected metrics.</small></div></div>
      <select className="ops-select" aria-label="Report template" value={templateId} onChange={event => setTemplateId(event.target.value)}><option value="">Standard template</option>{(templates.data || []).filter(item => !item.client_id || String(item.client_id) === String(selectedClientId)).map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select>
      <select className="ops-select" aria-label="Reporting period" value={period} onChange={event => setPeriod(event.target.value)}>{PERIODS.map(option => <option key={option.value} value={option.value}>{option.label}</option>)}</select>
      <button className="ops-primary" disabled={!selectedClientId || generate.isPending} onClick={() => { setNotice(''); generate.mutate() }}>{generate.isPending ? <LoaderCircle className="spin" size={14}/> : <FileText size={14}/>} {generate.isPending ? 'Queuing…' : 'Generate report'}</button>
    </section>}
    {notice && <p className={`report-notice ${generate.isError ? 'error' : ''}`}>{notice}</p>}

    {canManage && <div className="report-template-toolbar"><div><Palette size={15}/><span><strong>Reusable report templates</strong><small>Choose approved sections and client-facing branding.</small></span></div><button onClick={() => setShowTemplate(value => !value)}>{showTemplate ? <X size={13}/> : <Plus size={13}/>} {showTemplate ? 'Close' : 'New template'}</button></div>}
    {showTemplate && <section className="report-template-editor"><div className="report-template-fields"><label><span>Name</span><input value={template.name} onChange={event => setTemplate({ ...template, name: event.target.value })}/></label><label><span>Report brand</span><input value={template.brand_name} onChange={event => setTemplate({ ...template, brand_name: event.target.value })}/></label><label><span>Accent</span><input type="color" value={template.brand_color} onChange={event => setTemplate({ ...template, brand_color: event.target.value })}/></label><label><span>Logo URL</span><input type="url" value={template.logo_url} onChange={event => setTemplate({ ...template, logo_url: event.target.value })}/></label></div><div className="report-template-sections">{['summary', 'trends', 'campaigns', 'alerts'].map(section => <label key={section}><input type="checkbox" checked={template.sections.includes(section)} onChange={event => setTemplate(current => ({ ...current, sections: event.target.checked ? [...current.sections, section] : current.sections.filter(value => value !== section) }))}/><span>{section}</span></label>)}</div><button className="ops-primary" disabled={!template.name.trim() || !template.sections.length || saveTemplate.isPending} onClick={() => saveTemplate.mutate()}>{saveTemplate.isPending ? 'Saving…' : 'Save template'}</button></section>}

    <div className="report-section-head"><div><h2>Schedules</h2><p>Recurring snapshots run in the client’s timezone.</p></div>{canManage && <div className="ops-toolbar"><button className="ops-primary secondary" onClick={() => add('weekly')} disabled={!selectedClientId || save.isPending}><Plus size={14}/> Weekly</button><button className="ops-primary secondary" onClick={() => add('monthly')} disabled={!selectedClientId || save.isPending}><Plus size={14}/> Monthly</button></div>}</div>
    <div className="ops-list">{(reports.data?.schedules || []).map(schedule => <div className="ops-list-row" key={schedule.id}><CalendarClock size={16}/><div><strong>{schedule.client.name} · {schedule.frequency}</strong><small>{schedule.local_time} client local time · {schedule.frequency === 'weekly' ? `weekday ${schedule.weekday}` : `day ${schedule.day_of_month}`}</small></div><span>{schedule.enabled ? 'Active' : 'Paused'}</span></div>)}</div>

    <div className="report-section-head"><div><h2>Generated snapshots</h2><p>Completed links expire 30 days after generation.</p></div></div>
    <div className="ops-list">{(reports.data?.runs || []).map(run => <div className="ops-list-row" key={run.id}><span className={`report-run-state ${run.status}`}>{['pending', 'running'].includes(run.status) ? <LoaderCircle className="spin" size={14}/> : <FileText size={14}/>}</span><div><strong>{run.client.name} · {run.frequency === 'instant' ? 'Instant' : run.frequency}</strong><small>{run.period_start} — {run.period_end} · {run.status}{run.error_message ? ` · ${run.error_message}` : ''} · Delivery: {String(run.delivery_status || 'pending').replaceAll('_', ' ')}{run.delivery_error ? ` (${run.delivery_error})` : ''}</small></div>{run.share_url && <a href={run.share_url} target="_blank" rel="noreferrer">Open <ExternalLink size={13}/></a>}</div>)}</div>
  </Page>
}
