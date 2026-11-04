import { useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ArrowUpRight, BarChart3, Download, LogOut } from 'lucide-react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { api } from '../api/client'
import PerformancePeriodControl from '../components/PerformancePeriodControl'
import PerformanceTrendChart from '../components/PerformanceTrendChart'
import { AsyncStateNotice } from '../components/ui/AsyncState'
import { useAuth } from '../context/AuthContext'
import { completedDateRange, metricColumnsFor, objectiveMetricKeys } from '../lib/performance'

export default function ClientPortalPage({ preview = false }) {
  const { clientId } = useParams(); const navigate = useNavigate(); const { logout } = useAuth(); const [search, setSearch] = useSearchParams()
  const clients = useQuery({ queryKey: ['portal-clients'], queryFn: () => api.getPortalClients().then(response => response.data) })
  const client = clients.data?.find(item => String(item.id) === String(clientId))
  useEffect(() => { if (!clientId && clients.data?.length) navigate(`/portal/clients/${clients.data[0].id}`, { replace: true }) }, [clientId, clients.data, navigate])
  const range = search.get('range') || '30d'
  const preset = completedDateRange(Number(range === 'custom' ? 30 : range.replace('d', '')), client?.timezone)
  const dates = { start_date: search.get('start_date') || preset.start_date, end_date: search.get('end_date') || preset.end_date }
  const performance = useQuery({ queryKey: ['portal-client-performance', clientId, dates], queryFn: () => api.getPortalClientPerformance(clientId, dates).then(response => response.data), enabled: Boolean(client) })
  const columns = metricColumnsFor(objectiveMetricKeys(client?.objective))
  const query = new URLSearchParams({ range, ...dates }).toString()
  const clientBase = preview ? '/portal/preview/clients' : '/portal/clients'
  const updatePeriod = next => setSearch(current => { const merged = new URLSearchParams(current); Object.entries(next).forEach(([key, value]) => value ? merged.set(key, value) : merged.delete(key)); return merged }, { replace: true })
  const exportUrl = format => `/api/portal/clients/${clientId}/performance/export/${format}?${new URLSearchParams(dates)}`

  if (clients.isSuccess && !clients.data.length) return <PortalShell clients={[]} logout={logout} preview={preview}><section className="portal-empty"><BarChart3 size={34}/><h1>No reports are available yet</h1><p>Your account manager needs to enable at least one ad account for the client portal.</p></section></PortalShell>
  return <PortalShell clients={clients.data || []} clientId={clientId} logout={logout} preview={preview}><main className="portal-page">
    <AsyncStateNotice queries={[clients, performance]}/>
    <header className="portal-page-head"><div><p>Client performance report</p><h1>{client?.name || 'Loading report…'}</h1><span>{dates.start_date} — {dates.end_date} · Completed days</span></div><div className="portal-actions"><a href={exportUrl('pdf')}><Download size={14}/>PDF report</a><a href={exportUrl('xlsx')}><Download size={14}/>Excel table</a><a href={exportUrl('csv')}><Download size={14}/>CSV table</a><PerformancePeriodControl range={range} startDate={dates.start_date} endDate={dates.end_date} timezone={client?.timezone} onChange={updatePeriod}/></div></header>
    {(performance.data?.currency_groups || []).map(group => <section className="portal-summary" key={group.currency}><header><div><span>{group.currency} portfolio</span><h2>Performance summary</h2></div></header><div className="client-summary-kpis">{columns.map(column => <div key={column.key}><span>{column.label}</span><strong>{column.format(group.metrics?.[column.key], group.currency)}</strong></div>)}</div><PerformanceTrendChart series={group.series} currency={group.currency} columns={columns}/></section>)}
    <section className="portal-table-section"><header><div><h2>Advertising accounts</h2><p>Open an account to explore its permitted campaigns and ads.</p></div><span>{performance.data?.accounts?.length || 0} visible</span></header><div className="performance-table-shell"><div className="performance-table-scroll"><table className="performance-table"><thead><tr><th>Account</th><th>Platform</th><th>Scope</th>{columns.map(column => <th className="numeric-header" key={column.key}>{column.label}</th>)}<th/></tr></thead><tbody>{(performance.data?.accounts || []).map(row => <tr key={row.account.id}><td className="sticky-column"><strong>{row.account.name}</strong><small>{row.account.external_id} · {row.account.timezone}</small></td><td><span className={`platform-badge ${row.account.platform}`}>{row.account.platform === 'google' ? 'Google Ads' : 'Meta'}</span></td><td><span className="portal-scope-badge">{row.visibility_mode === 'selected_campaigns' ? 'Selected campaigns' : 'Full account'}</span></td>{columns.map(column => <td className="numeric-cell" key={column.key}>{column.format(row.metrics?.[column.key], row.account.currency)}</td>)}<td><Link className="client-account-open" to={`${clientBase}/${clientId}/accounts/${row.account.id}?${query}`} aria-label={`Open ${row.account.name}`}><ArrowUpRight size={14}/></Link></td></tr>)}</tbody></table></div></div></section>
  </main></PortalShell>
}

export function PortalShell({ clients, clientId, logout, preview = false, children }) {
  return <div className="portal-shell"><header className="portal-topbar"><Link to={preview ? `/clients/${clientId}?tab=client-access` : '/portal'} className="portal-brand"><span><BarChart3 size={18}/></span><div><strong>SharkAds</strong><small>{preview ? 'Client portal preview' : 'Client portal'}</small></div></Link><div className="portal-topbar-actions">{clients.length > 1 && <select aria-label="Select client" value={clientId || ''} onChange={event => window.location.assign(`${preview ? '/portal/preview/clients' : '/portal/clients'}/${event.target.value}`)}>{clients.map(client => <option key={client.id} value={client.id}>{client.name}</option>)}</select>}<button onClick={logout}><LogOut size={14}/>Sign out</button></div></header>{children}</div>
}
