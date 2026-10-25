import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { AlertTriangle, ArrowUpRight, BellRing, Clock3, DatabaseZap, Plus, RefreshCw } from 'lucide-react'
import { Area, AreaChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { api } from '../api/client'
import { AsyncStateNotice } from '../components/ui/AsyncState'
import { useAuth } from '../context/AuthContext'
import { useWorkspace } from '../context/WorkspaceContext'

const fmt = (value, options = {}) => value == null ? '—' : new Intl.NumberFormat('en-IN', { maximumFractionDigits: 2, ...options }).format(value)
const money = (value, currency) => value == null ? '—' : new Intl.NumberFormat('en-IN', { style: 'currency', currency: currency || 'INR', maximumFractionDigits: 0 }).format(value)
const metricDefs = [
  ['Spend', 'spend', 'money'], ['Leads', 'leads'], ['Purchases', 'purchases'], ['ROAS', 'roas'], ['CTR', 'ctr', 'percent'], ['CPL', 'cpl', 'money'],
]

export default function Dashboard() {
  const { user } = useAuth()
  const { clients: workspaceClients, clientId: selectedClientId, startDate, endDate } = useWorkspace()
  const navigate = useNavigate()
  const clients = useQuery({ queryKey: ['clients'], queryFn: () => api.getClients().then(r => r.data) })
  const dates = { start_date: startDate, end_date: endDate }
  const params = { client_id: selectedClientId, ...dates }
  const enabled = Boolean(selectedClientId)
  const summary = useQuery({ queryKey: ['metrics-summary', params], queryFn: () => api.getMetricSummary(params).then(r => r.data), enabled })
  const trends = useQuery({ queryKey: ['metrics-trends', params], queryFn: () => api.getMetricTrends(params).then(r => r.data), enabled })
  const campaigns = useQuery({ queryKey: ['campaign-metrics', params], queryFn: () => api.getCampaignMetrics(params).then(r => r.data), enabled })
  const alerts = useQuery({ queryKey: ['alerts-open', selectedClientId], queryFn: () => api.getAlerts({ client_id: selectedClientId, status: 'open' }).then(r => r.data), enabled, refetchInterval: 60_000 })
  const connections = useQuery({ queryKey: ['connections'], queryFn: () => api.getConnections().then(r => r.data), enabled })
  const reportRuns = useQuery({ queryKey: ['report-schedules'], queryFn: () => api.getReportSchedules().then(r => r.data), enabled })
  const client = workspaceClients.find(c => String(c.id) === selectedClientId)
  const currencyBlock = summary.data?.currencies?.[0]
  const currency = currencyBlock?.currency || client?.currency || 'INR'
  const metrics = currencyBlock?.metrics || {}
  const chartData = (trends.data?.series || []).filter(row => row.currency === currency).map(row => ({ date: row.date.slice(5), spend: row.metrics.spend, conversions: row.metrics.conversions }))
  const dashboardQueries = [summary, trends, campaigns, alerts]
  const freshness = summary.isError ? 'Unavailable' : summary.isPending ? 'Loading…' : summary.data?.freshness_timestamp ? new Date(summary.data.freshness_timestamp).toLocaleString() : 'Waiting for first collection'
  const nextActions = [
    alerts.data?.data?.some(alert => alert.severity === 'critical') && { label: 'Review critical incidents', detail: 'Critical signals should be triaged first.', to: '/alerts?severity=critical&status=open' },
    connections.data?.some(connection => connection.status === 'error') && { label: 'Repair a connection', detail: 'A platform connection is blocking fresh data.', to: '/connections' },
    summary.isSuccess && !summary.data?.freshness_timestamp && { label: 'Collect first metrics', detail: 'Finish account assignment and verify access.', to: '/connections' },
    !(reportRuns.data?.runs || []).some(run => String(run.client_id) === String(selectedClientId) && run.status === 'complete') && { label: 'Generate the first report', detail: 'Create a client-safe baseline snapshot.', to: '/scheduled-reports' },
  ].filter(Boolean).slice(0, 3)

  if (!clients.isLoading && clients.data?.length === 0) return (
    <div style={{ padding: 40, maxWidth: 900 }}>
      <p style={{ color: '#2563eb', fontWeight: 700, fontSize: 12, letterSpacing: '.08em', textTransform: 'uppercase' }}>Performance operations</p>
      <h1 style={{ font: '700 34px Syne, sans-serif', color: '#0f172a', margin: '8px 0 12px' }}>Start with your first client.</h1>
      <p style={{ color: '#64748b', maxWidth: 560, lineHeight: 1.6 }}>Create a client, connect Google Ads or Meta, then hourly collection and alert evaluation will begin.</p>
      {user?.role === 'admin' && <button onClick={() => navigate('/clients')} className="ops-primary"><Plus size={16}/> Add client</button>}
    </div>
  )

  return (
    <div style={{ padding: '28px 34px 48px', maxWidth: 1280, margin: '0 auto' }}>
      <header style={{ display: 'flex', alignItems: 'end', justifyContent: 'space-between', gap: 20, marginBottom: 30 }} className="fade-up">
        <div><p className="ops-eyebrow">Command center</p><h1 className="ops-title">Performance, across every client.</h1><p className="ops-subtitle">Signals, pacing and account health · {dates.start_date} — {dates.end_date}</p></div>
        <button onClick={() => navigate('/alerts')} className="ops-primary"><BellRing size={14}/> Review alerts</button>
      </header>

      <section className="ops-status-line fade-up-delay-1">
        <span><DatabaseZap size={14}/> {client?.ad_accounts_count || 0} connected accounts</span>
        <span><Clock3 size={14}/> Freshness: {freshness}</span>
        <span className={(alerts.data?.total || 0) > 0 ? 'ops-danger' : 'ops-ok'}><AlertTriangle size={14}/> {alerts.data?.total || 0} open incidents</span>
        <button onClick={() => { summary.refetch(); trends.refetch(); campaigns.refetch(); alerts.refetch() }}><RefreshCw size={13}/> Refresh view</button>
      </section>

      <AsyncStateNotice queries={dashboardQueries} partialText="Some dashboard data could not be loaded. Available sections remain visible."/>
      {summary.data?.currencies?.length > 1 && <div className="ops-notice">Accounts use multiple currencies. Totals are shown separately; no exchange-rate blending has been applied.</div>}

      <section className="ops-kpis fade-up-delay-2">
        {metricDefs.map(([label, key, kind]) => <div key={key} className="ops-kpi"><span>{label}</span><strong>{enabled && summary.isPending ? <i className="ops-kpi-loading skeleton" aria-label={`${label} loading`}/> : kind === 'money' ? money(metrics[key], currency) : kind === 'percent' ? metrics[key] == null ? '—' : `${fmt(metrics[key])}%` : fmt(metrics[key])}</strong></div>)}
      </section>

      {nextActions.length > 0 && <section className="dashboard-next"><div><p className="ops-eyebrow">Next best actions</p><h2>Move the account forward</h2></div>{nextActions.map((item, index) => <button key={item.label} onClick={() => navigate(item.to)}><span>{index + 1}</span><div><strong>{item.label}</strong><small>{item.detail}</small></div><ArrowUpRight size={14}/></button>)}</section>}

      <div className="ops-grid">
        <section className="ops-main-panel">
          <div className="ops-section-heading"><div><h2>Spend trend</h2><p>Daily account totals in {currency}</p></div><span>{dates.start_date} — {dates.end_date}</span></div>
          <div style={{ height: 250 }}>
            {trends.isPending ? <div className="dashboard-chart-loading skeleton" aria-label="Spend trend loading"/> : chartData.length ? <ResponsiveContainer width="100%" height="100%"><AreaChart data={chartData}><defs><linearGradient id="spendFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stopColor="#2563eb" stopOpacity={.24}/><stop offset="100%" stopColor="#2563eb" stopOpacity={0}/></linearGradient></defs><XAxis dataKey="date" axisLine={false} tickLine={false} tick={{ fontSize: 11, fill: '#94a3b8' }}/><YAxis axisLine={false} tickLine={false} tick={{ fontSize: 11, fill: '#94a3b8' }}/><Tooltip formatter={v => money(v, currency)} contentStyle={{ border: 0, borderRadius: 9, boxShadow: '0 12px 35px rgba(15,23,42,.15)' }}/><Area type="monotone" dataKey="spend" stroke="#2563eb" strokeWidth={2} fill="url(#spendFill)"/></AreaChart></ResponsiveContainer> : trends.isSuccess ? <Empty text="No activity in this period."/> : null}
          </div>
          <div className="ops-section-heading" style={{ marginTop: 28 }}><div><h2>Campaign performance</h2><p>Decision metrics, ranked by spend</p></div></div>
          <div className="ops-table-wrap"><table className="ops-table"><thead><tr><th>Campaign</th><th>Spend</th><th>Conversions</th><th>CPL</th><th>ROAS</th><th>CTR</th></tr></thead><tbody>
            {(campaigns.data?.campaigns || []).sort((a,b) => b.metrics.spend - a.metrics.spend).slice(0, 12).map(row => <tr key={row.campaign.id}><td><strong>{row.campaign.name}</strong><small>{row.campaign.type || row.campaign.status}</small></td><td>{money(row.metrics.spend, row.currency)}</td><td>{fmt(row.metrics.conversions)}</td><td>{row.metrics.cpl == null ? '—' : money(row.metrics.cpl, row.currency)}</td><td>{row.metrics.roas == null ? '—' : `${fmt(row.metrics.roas)}×`}</td><td>{row.metrics.ctr == null ? '—' : `${fmt(row.metrics.ctr)}%`}</td></tr>)}
          </tbody></table>{campaigns.isPending ? <div className="dashboard-table-loading skeleton" aria-label="Campaign performance loading"/> : campaigns.isSuccess && !campaigns.data?.campaigns?.length ? <Empty text="No campaign rows in this period."/> : null}</div>
        </section>

        <aside className="ops-alert-rail">
          <div className="ops-section-heading"><div><h2>Needs attention</h2><p>Open incidents, highest severity first</p></div><button onClick={() => navigate('/alerts')}>All <ArrowUpRight size={13}/></button></div>
          {(alerts.data?.data || []).slice(0, 8).map(alert => <button className="ops-alert-row" key={alert.id} onClick={() => navigate(`/alerts/${alert.id}`)}><i data-severity={alert.severity}/><span><strong>{alert.title}</strong><small>{alert.client.name} · {alert.campaign?.name || alert.ad_account?.name || 'Account'}</small></span><time>{new Date(alert.triggered_at).toLocaleDateString()}</time></button>)}
          {alerts.isPending ? <div className="dashboard-table-loading skeleton" aria-label="Alerts loading"/> : alerts.isSuccess && !alerts.data?.data?.length ? <Empty text="No open incidents. Monitoring continues."/> : null}
        </aside>
      </div>
    </div>
  )
}

function Empty({ text }) { return <div style={{ color: '#94a3b8', fontSize: 13, padding: '28px 0' }}>{text}</div> }
