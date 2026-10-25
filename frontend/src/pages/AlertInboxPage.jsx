import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { Check, ChevronLeft, ChevronRight, Clock, MessageSquare, Search, UserCheck, X } from 'lucide-react'
import { api } from '../api/client'
import { Page } from './ClientsPage'
import { useAuth } from '../context/AuthContext'
import { useWorkspace } from '../context/WorkspaceContext'
import { useToast } from '../context/ToastContext'
import ConfirmDialog from '../components/ui/ConfirmDialog'
import { AsyncStateNotice } from '../components/ui/AsyncState'

export default function AlertInboxPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const toast = useToast()
  const { user } = useAuth()
  const { clientId } = useWorkspace()
  const [search, setSearch] = useSearchParams()
  const [selected, setSelected] = useState([])
  const [noteOpen, setNoteOpen] = useState(false)
  const [note, setNote] = useState('')
  const savedViewKey = `pulse:alert-views:${user?.id || 'guest'}`
  const [savedViews, setSavedViews] = useState(() => { try { return JSON.parse(localStorage.getItem(savedViewKey) || '[]') } catch { return [] } })
  const page = Number(search.get('page') || 1)
  const filters = { client_id: clientId || undefined, status: search.get('status') || undefined, severity: search.get('severity') || undefined, q: search.get('q') || undefined, page, per_page: 25 }
  const alerts = useQuery({ queryKey: ['alerts', filters], queryFn: () => api.getAlerts(filters).then(r => r.data), refetchInterval: query => query.state.data?.data?.some(row => row.status !== 'resolved') ? 30_000 : 90_000 })
  const detail = useQuery({ queryKey: ['alert', id], queryFn: () => api.getAlert(id).then(r => r.data), enabled: Boolean(id) })
  const finish = message => { qc.invalidateQueries({ queryKey: ['alerts'] }); qc.invalidateQueries({ queryKey: ['alert', id] }); setSelected([]); toast.success(message) }
  const action = useMutation({ mutationFn: ({ action, data }) => api.alertAction(id, action, data), onSuccess: () => finish('Incident updated.'), onError: error => toast.error(error.response?.data?.message || 'Incident could not be updated.') })
  const bulk = useMutation({ mutationFn: ({ action, data = {} }) => api.bulkAlertAction(selected, action, data), onSuccess: () => finish('Selected incidents updated.'), onError: error => toast.error(error.response?.data?.message || 'Incidents could not be updated.') })
  const operate = user?.role !== 'viewer'
  const rows = alerts.data?.data || []
  const setFilter = (key, value) => setSearch(current => { const next = new URLSearchParams(current); value ? next.set(key, value) : next.delete(key); next.delete('page'); return next }, { replace: true })
  const saveView = () => {
    const query = search.toString()
    if (!query) return
    const label = [search.get('severity'), search.get('status'), search.get('q')].filter(Boolean).join(' · ') || 'Custom view'
    const next = [...savedViews.filter(view => view.query !== query), { id: Date.now(), label, query }].slice(-8)
    localStorage.setItem(savedViewKey, JSON.stringify(next)); setSavedViews(next); toast.success('View saved in this browser.')
  }

  return <Page title="Alert inbox" subtitle="Triage, assign and resolve performance exceptions across clients.">
    <AsyncStateNotice queries={[alerts, detail]}/>
    <div className="alert-toolbar">
      <label className="alert-search"><Search size={14}/><input value={search.get('q') || ''} onChange={event => setFilter('q', event.target.value)} placeholder="Search incidents"/></label>
      <select value={search.get('status') || ''} onChange={event => setFilter('status', event.target.value)}><option value="">All states</option><option value="open">Open</option><option value="acknowledged">Acknowledged</option><option value="snoozed">Snoozed</option><option value="resolved">Resolved</option></select>
      <select value={search.get('severity') || ''} onChange={event => setFilter('severity', event.target.value)}><option value="">All severities</option><option value="critical">Critical</option><option value="warning">Warning</option><option value="info">Info</option></select>
      {savedViews.length > 0 && <select aria-label="Saved views" value="" onChange={event => event.target.value && setSearch(new URLSearchParams(event.target.value), { replace: true })}><option value="">Saved views</option>{savedViews.map(view => <option value={view.query} key={view.id}>{view.label}</option>)}</select>}
      {search.toString() && <button className="alert-clear" onClick={saveView}>Save view</button>}
      {(search.get('q') || search.get('status') || search.get('severity')) && <button className="alert-clear" onClick={() => setSearch({}, { replace: true })}><X size={13}/> Clear</button>}
    </div>
    {operate && selected.length > 0 && <div className="alert-bulk"><strong>{selected.length} selected</strong><button onClick={() => bulk.mutate({ action: 'acknowledge' })}><Check size={13}/> Acknowledge</button><button onClick={() => bulk.mutate({ action: 'snooze', data: { snoozed_until: new Date(Date.now() + 6 * 3600000).toISOString() } })}><Clock size={13}/> Snooze 6h</button><button onClick={() => bulk.mutate({ action: 'resolve', data: { note: 'Bulk resolved' } })}><UserCheck size={13}/> Resolve</button></div>}
    <div className={`ops-split ${id ? 'show-detail' : ''}`}><section className="ops-inbox">
      {rows.map(alert => <div className={`alert-select-row ${id === alert.id ? 'active' : ''}`} key={alert.id}>{operate && <input type="checkbox" checked={selected.includes(alert.id)} onChange={event => setSelected(current => event.target.checked ? [...current, alert.id] : current.filter(value => value !== alert.id))} aria-label={`Select ${alert.title}`}/>}<button className={`ops-inbox-row ${id === alert.id ? 'active' : ''}`} onClick={() => navigate(`/alerts/${alert.id}?${search}`)}><i data-severity={alert.severity}/><span><strong>{alert.title}</strong><small>{alert.client.name} · {alert.campaign?.name || alert.ad_account?.name || 'Account'}{alert.assignee ? ` · ${alert.assignee.name}` : ''}</small></span><em>{alert.status}</em></button></div>)}
      {!rows.length && <div className="alert-empty">No incidents match this view.</div>}
      {alerts.data?.last_page > 1 && <nav className="alert-pagination"><button disabled={page <= 1} onClick={() => setFilter('page', String(page - 1))}><ChevronLeft size={13}/></button><span>{page} / {alerts.data.last_page}</span><button disabled={page >= alerts.data.last_page} onClick={() => setFilter('page', String(page + 1))}><ChevronRight size={13}/></button></nav>}
    </section>
    <aside className="ops-inspector">{id && <button className="alert-detail-back" onClick={() => navigate(`/alerts?${search}`)}><ChevronLeft size={13}/> Back to inbox</button>}{detail.data ? <><p className="ops-eyebrow">{detail.data.severity} · {detail.data.status}</p><h2>{detail.data.title}</h2><p>{detail.data.summary}</p><dl><dt>Observed</dt><dd>{detail.data.observed_value ?? '—'}</dd><dt>Baseline</dt><dd>{detail.data.baseline_value ?? '—'}</dd><dt>Threshold</dt><dd>{detail.data.threshold_value ?? '—'}</dd></dl>{operate && <div className="ops-actions"><button onClick={() => action.mutate({ action: 'acknowledge', data: {} })}><Check size={14}/> Acknowledge</button><button onClick={() => setNoteOpen(true)}><MessageSquare size={14}/> Note</button><button onClick={() => action.mutate({ action: 'snooze', data: { snoozed_until: new Date(Date.now() + 6 * 3600000).toISOString() } })}><Clock size={14}/> Snooze 6h</button><button onClick={() => action.mutate({ action: 'resolve', data: { note: 'Manually resolved' } })}><UserCheck size={14}/> Resolve</button></div>}<h3>Activity</h3><ol className="ops-timeline">{detail.data.events?.map(event => <li key={event.id}><strong>{event.type}</strong><span>{event.actor?.name || 'System'} · {new Date(event.created_at).toLocaleString()}</span>{event.note && <p>{event.note}</p>}</li>)}</ol></> : <div className="alert-empty">Select an incident to inspect its history.</div>}</aside></div>
    <ConfirmDialog open={noteOpen} title="Add incident note" description={<textarea className="dialog-textarea" autoFocus rows="4" value={note} onChange={event => setNote(event.target.value)} placeholder="Add context for the next person…"/>} confirmLabel="Add note" tone="primary" busy={action.isPending} onClose={() => setNoteOpen(false)} onConfirm={() => { if (!note.trim()) return; action.mutate({ action: 'note', data: { note: note.trim() } }); setNote(''); setNoteOpen(false) }}/>
  </Page>
}
