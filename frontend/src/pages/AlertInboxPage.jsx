import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { Check, ChevronLeft, ChevronRight, Clock, ExternalLink, MessageSquare, Search, UserCheck, X } from 'lucide-react'
import { api } from '../api/client'
import { Page } from './ClientsPage'
import { useAuth } from '../context/AuthContext'
import { useToast } from '../context/ToastContext'
import ConfirmDialog from '../components/ui/ConfirmDialog'
import { AsyncStateNotice } from '../components/ui/AsyncState'
import ScopeFilterBar from '../components/ScopeFilterBar'
import { accountPerformancePath, adGroupPerformancePath, adPerformancePath, campaignPerformancePath } from '../lib/performanceRoutes'

function formatMoney(value, currency = 'USD') {
  if (value === null || value === undefined) return '—'
  try { return new Intl.NumberFormat(undefined, { style: 'currency', currency, maximumFractionDigits: 2 }).format(Number(value)) } catch { return `${currency} ${Number(value).toFixed(2)}` }
}

function formatNumber(value, maximumFractionDigits = 2) {
  if (value === null || value === undefined) return '—'
  return new Intl.NumberFormat(undefined, { maximumFractionDigits }).format(Number(value))
}

function formatPercent(value) {
  return value === null || value === undefined ? '—' : `${formatNumber(value)}%`
}

const MONEY_METRICS = new Set(['spend', 'conversion_value', 'cpc', 'cpm', 'cpa', 'cpl'])
const PERCENT_METRICS = new Set(['ctr', 'conversion_rate'])
const METRIC_LABELS = { cpc: 'CPC', cpm: 'CPM', cpa: 'CPA', cpl: 'CPL', ctr: 'CTR', roas: 'ROAS', conversion_rate: 'conversion rate', conversion_value: 'conversion value' }

function metricLabel(metric) {
  return METRIC_LABELS[metric] || String(metric || 'value').replaceAll('_', ' ')
}

function formatMetric(value, metric, currency) {
  if (MONEY_METRICS.has(metric)) return formatMoney(value, currency)
  if (PERCENT_METRICS.has(metric)) return formatPercent(value)
  if (metric === 'roas') return value === null || value === undefined ? '—' : `${formatNumber(value)}×`
  return formatNumber(value)
}

function formatDateTime(value, timezone) {
  if (!value) return '—'
  const options = { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit', timeZoneName: 'short', ...(timezone ? { timeZone: timezone } : {}) }
  return new Intl.DateTimeFormat(undefined, options).format(new Date(value))
}

function formatPeriod(start, end, timezone) {
  if (!start || !end) return '—'
  return `${formatDateTime(start, timezone)} – ${formatDateTime(end, timezone)}`
}

function IncidentMetrics({ alert }) {
  const metric = alert.rule?.metric || 'value'
  const operator = alert.rule?.operator
  const currency = alert.currency || alert.ad_account?.currency
  const percentageComparison = operator === 'percentage_drop' || operator === 'percentage_increase'
  const legacyPercentageAlert = percentageComparison && (alert.comparison_value === null || alert.comparison_value === undefined)
  const comparisonValue = alert.comparison_value ?? (legacyPercentageAlert ? alert.observed_value : null)
  const legacyObservedValue = legacyPercentageAlert && alert.baseline_value !== null && alert.baseline_value !== undefined
    ? Number(alert.baseline_value) * (1 + (operator === 'percentage_drop' ? -1 : 1) * Number(comparisonValue) / 100)
    : null
  const threshold = percentageComparison || operator === 'budget_under_pacing' || operator === 'budget_over_pacing'
    ? formatPercent(alert.threshold_value)
    : operator === 'data_stale'
      ? `${formatNumber(alert.threshold_value)} hours`
      : formatMetric(alert.threshold_value, metric, currency)
  const observed = legacyPercentageAlert
    ? formatMetric(legacyObservedValue, metric, currency)
    : operator === 'budget_under_pacing' || operator === 'budget_over_pacing'
      ? formatPercent(alert.observed_value)
      : operator === 'data_stale'
        ? `${formatNumber(alert.observed_value)} hours`
        : operator === 'connection_error'
          ? Number(alert.observed_value) === 1 ? 'Connection error' : 'Healthy'
          : formatMetric(alert.observed_value, metric, currency)
  const comparisonLabel = operator === 'percentage_drop' ? 'Drop vs baseline' : 'Increase vs baseline'

  return <dl className="alert-metric-grid">
    <div><dt>Observed {metricLabel(metric)}</dt><dd>{observed}</dd></div>
    <div><dt>Baseline {metricLabel(metric)}</dt><dd>{formatMetric(alert.baseline_value, metric, currency)}</dd></div>
    {percentageComparison && <div><dt>{comparisonLabel}</dt><dd>{formatPercent(comparisonValue)}</dd></div>}
    <div><dt>Threshold</dt><dd>{threshold}</dd></div>
    <div><dt>Observed spend</dt><dd>{formatMoney(alert.observed_spend, currency)}</dd></div>
    <div><dt>Baseline spend</dt><dd>{formatMoney(alert.baseline_spend, currency)}</dd></div>
  </dl>
}

function RelatedLinks({ alert }) {
  const clientId = alert.client?.id || alert.client_id
  const accountId = alert.ad_account?.id || alert.ad_account_id
  const campaignId = alert.campaign?.id || alert.campaign_id
  const groupId = alert.ad_group?.id || alert.ad_group_id
  const adId = alert.ad?.id || alert.ad_id
  const links = [
    clientId && { label: 'Client', name: alert.client?.name, to: `/clients/${clientId}` },
    clientId && accountId && { label: 'Ad account', name: alert.ad_account?.name, to: accountPerformancePath(clientId, accountId) },
    clientId && accountId && campaignId && { label: 'Campaign', name: alert.campaign?.name, to: campaignPerformancePath(clientId, accountId, campaignId) },
    clientId && accountId && campaignId && groupId && { label: alert.ad_account?.platform === 'meta' ? 'Ad set' : 'Ad group', name: alert.ad_group?.name, to: adGroupPerformancePath(clientId, accountId, campaignId, groupId) },
    clientId && accountId && campaignId && groupId && adId && { label: 'Ad', name: alert.ad?.name, to: adPerformancePath(clientId, accountId, campaignId, groupId, adId) },
  ].filter(Boolean)
  if (!links.length) return null

  return <nav className="alert-related" aria-label="Related records">{links.map(link => <Link key={link.label} to={link.to}><span>{link.label}</span><strong>{link.name || `Open ${link.label.toLowerCase()}`}</strong><ExternalLink size={12}/></Link>)}</nav>
}

export default function AlertInboxPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const toast = useToast()
  const { user } = useAuth()
  const [search, setSearch] = useSearchParams()
  const [selected, setSelected] = useState([])
  const [noteOpen, setNoteOpen] = useState(false)
  const [note, setNote] = useState('')
  const savedViewKey = `pulse:alert-views:${user?.id || 'guest'}`
  const [savedViews, setSavedViews] = useState(() => { try { return JSON.parse(localStorage.getItem(savedViewKey) || '[]') } catch { return [] } })
  const page = Number(search.get('page') || 1)
  const filters = { client_id: search.get('client_id') || undefined, ad_account_id: search.get('ad_account_id') || undefined, status: search.get('status') || undefined, severity: search.get('severity') || undefined, q: search.get('q') || undefined, page, per_page: 25 }
  const alerts = useQuery({ queryKey: ['alerts', filters], queryFn: () => api.getAlerts(filters).then(r => r.data), refetchInterval: query => query.state.data?.data?.some(row => row.status !== 'resolved') ? 30_000 : 90_000 })
  const detail = useQuery({ queryKey: ['alert', id], queryFn: () => api.getAlert(id).then(r => r.data), enabled: Boolean(id) })
  const finish = message => { qc.invalidateQueries({ queryKey: ['alerts'] }); qc.invalidateQueries({ queryKey: ['alert', id] }); setSelected([]); toast.success(message) }
  const action = useMutation({ mutationFn: ({ action, data }) => api.alertAction(id, action, data), onSuccess: () => finish('Incident updated.'), onError: error => toast.error(error.response?.data?.message || 'Incident could not be updated.') })
  const bulk = useMutation({ mutationFn: ({ action, data = {} }) => api.bulkAlertAction(selected, action, data), onSuccess: () => finish('Selected incidents updated.'), onError: error => toast.error(error.response?.data?.message || 'Incidents could not be updated.') })
  const operate = user?.role !== 'viewer'
  const rows = alerts.data?.data || []
  const setFilter = (key, value) => setSearch(current => { const next = new URLSearchParams(current); value ? next.set(key, value) : next.delete(key); next.delete('page'); return next }, { replace: true })
  const setScope = (clientId, accountId) => { const next = new URLSearchParams(search); clientId ? next.set('client_id', clientId) : next.delete('client_id'); accountId ? next.set('ad_account_id', accountId) : next.delete('ad_account_id'); next.delete('page'); setSearch(next, { replace: true }) }
  const saveView = () => {
    const query = search.toString()
    if (!query) return
    const label = [search.get('severity'), search.get('status'), search.get('q')].filter(Boolean).join(' · ') || 'Custom view'
    const next = [...savedViews.filter(view => view.query !== query), { id: Date.now(), label, query }].slice(-8)
    localStorage.setItem(savedViewKey, JSON.stringify(next)); setSavedViews(next); toast.success('View saved in this browser.')
  }

  return <Page title="Alert inbox" subtitle="Triage, assign and resolve performance exceptions across clients.">
    <AsyncStateNotice queries={[alerts, detail]}/>
    <ScopeFilterBar clientId={search.get('client_id') || ''} accountId={search.get('ad_account_id') || ''} onScopeChange={setScope} onAccountChange={value => setFilter('ad_account_id', value)}/>
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
    <aside className="ops-inspector">{id && <button className="alert-detail-back" onClick={() => navigate(`/alerts?${search}`)}><ChevronLeft size={13}/> Back to inbox</button>}{detail.data ? <><p className="ops-eyebrow">{detail.data.severity} · {detail.data.status}</p><h2>{detail.data.title}</h2><p>{detail.data.summary}</p><RelatedLinks alert={detail.data}/><IncidentMetrics alert={detail.data}/><section className="alert-periods"><h3>Measurement windows</h3><dl><div><dt>Observed</dt><dd>{formatPeriod(detail.data.observed_period_start, detail.data.observed_period_end, detail.data.ad_account?.timezone)}</dd></div><div><dt>Baseline</dt><dd>{formatPeriod(detail.data.baseline_period_start, detail.data.baseline_period_end, detail.data.ad_account?.timezone)}</dd></div></dl></section>{operate && <div className="ops-actions"><button onClick={() => action.mutate({ action: 'acknowledge', data: {} })}><Check size={14}/> Acknowledge</button><button onClick={() => setNoteOpen(true)}><MessageSquare size={14}/> Note</button><button onClick={() => action.mutate({ action: 'snooze', data: { snoozed_until: new Date(Date.now() + 6 * 3600000).toISOString() } })}><Clock size={14}/> Snooze 6h</button><button onClick={() => action.mutate({ action: 'resolve', data: { note: 'Manually resolved' } })}><UserCheck size={14}/> Resolve</button></div>}<h3>Activity</h3><ol className="ops-timeline">{detail.data.events?.map(event => <li key={event.id}><strong>{event.type}</strong><span>{event.actor?.name || 'System'} · {formatDateTime(event.created_at, detail.data.ad_account?.timezone)}</span>{event.note && <p>{event.note}</p>}</li>)}</ol></> : <div className="alert-empty">Select an incident to inspect its history.</div>}</aside></div>
    <ConfirmDialog open={noteOpen} title="Add incident note" description={<textarea className="dialog-textarea" autoFocus rows="4" value={note} onChange={event => setNote(event.target.value)} placeholder="Add context for the next person…"/>} confirmLabel="Add note" tone="primary" busy={action.isPending} onClose={() => setNoteOpen(false)} onConfirm={() => { if (!note.trim()) return; action.mutate({ action: 'note', data: { note: note.trim() } }); setNote(''); setNoteOpen(false) }}/>
  </Page>
}
