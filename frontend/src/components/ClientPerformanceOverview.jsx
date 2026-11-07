import { useQuery } from '@tanstack/react-query'
import { ArrowUpRight, Clock3, DatabaseZap } from 'lucide-react'
import { Link } from 'react-router-dom'
import { api } from '../api/client'
import { accountPerformancePath } from '../lib/performanceRoutes'
import { metricColumnsFor, objectiveMetricKeys } from '../lib/performance'
import { AsyncStateNotice } from './ui/AsyncState'
import PerformancePeriodControl from './PerformancePeriodControl'
import PerformanceTrendChart from './PerformanceTrendChart'

export default function ClientPerformanceOverview({ client, clientId, range, dates, onPeriodChange }) {
  const performance = useQuery({
    queryKey: ['client-performance', clientId, dates],
    queryFn: () => api.getClientPerformance(clientId, dates).then(response => response.data),
    enabled: Boolean(client && clientId && dates.start_date && dates.end_date),
  })
  const metricColumns = metricColumnsFor(objectiveMetricKeys(client?.objective))
  const accounts = performance.data?.accounts || []
  const groups = performance.data?.currency_groups || []
  const accountQuery = new URLSearchParams({ range, ...dates }).toString()

  return <section className="client-performance-overview">
    <header className="client-performance-toolbar"><div><h2>Account performance</h2><p>Completed-day results across every connected account.</p></div><PerformancePeriodControl range={range} startDate={dates.start_date} endDate={dates.end_date} timezone={client?.timezone} onChange={onPeriodChange}/></header>
    <AsyncStateNotice queries={[performance]} partialText="Some client performance could not be loaded."/>

    {groups.length > 1 && <div className="client-currency-notice"><DatabaseZap size={14}/><span>Metrics are separated by currency so spend, cost, and revenue are never blended.</span></div>}
    <div className="client-currency-summaries">
      {groups.map(group => <article className="client-currency-summary" key={group.currency}>
        <header><div><span>{group.currency} portfolio</span><strong>{client?.objective === 'lead_gen' ? 'Lead performance' : client?.objective === 'ecommerce' ? 'Commerce performance' : 'Performance summary'}</strong></div><small className={freshnessState(group.freshness_timestamp)}><Clock3 size={12}/>{freshnessLabel(group.freshness_timestamp)}</small></header>
        <div className="client-summary-kpis">{metricColumns.map(column => <div key={column.key}><span>{column.label}</span><strong>{column.format(group.metrics?.[column.key], group.currency)}</strong></div>)}</div>
        <PerformanceTrendChart series={group.series} currency={group.currency} columns={metricColumns}/>
      </article>)}
    </div>

    <div className="client-account-performance"><header><div><h3>Connected accounts</h3><p>Compare delivery and outcomes, then open an account for campaign detail.</p></div><span>{accounts.length} {accounts.length === 1 ? 'account' : 'accounts'}</span></header>
      <div className="performance-table-shell"><div className="performance-table-scroll"><table className="performance-table client-account-performance-table"><thead><tr><th>Account</th><th>Platform</th><th>Status</th>{metricColumns.map(column => <th className="numeric-header" key={column.key}>{column.label}</th>)}<th>Data freshness</th><th className="action-header" aria-label="Open account"/></tr></thead><tbody>
        {accounts.map(row => <tr key={row.account.id}><td className="sticky-column"><Link to={accountPerformancePath(clientId, row.account.id, accountQuery)}><strong>{row.account.name}</strong><small>{row.account.external_id} · {row.account.timezone}</small></Link></td><td><span className={`platform-badge ${row.account.platform}`}>{row.account.platform === 'google' ? 'Google Ads' : 'Meta'}</span></td><td><span className="entity-status">{row.account.status || 'Unknown'}</span></td>{metricColumns.map(column => <td className="numeric-cell" key={column.key}>{row.freshness_timestamp ? column.format(row.metrics?.[column.key], row.account.currency) : '—'}</td>)}<td><span className={`account-freshness ${freshnessState(row.freshness_timestamp)}`}>{freshnessLabel(row.freshness_timestamp)}</span></td><td><Link className="client-account-open" aria-label={`Open ${row.account.name}`} to={accountPerformancePath(clientId, row.account.id, accountQuery)}><ArrowUpRight size={14}/></Link></td></tr>)}
      </tbody>{accounts.length > 1 && <tfoot>{groups.map(group => {
        const groupAccountCount = accounts.filter(row => row.account.currency === group.currency).length
        return <tr key={group.currency}><td className="sticky-column"><strong>{group.currency} total</strong><small>{groupAccountCount} {groupAccountCount === 1 ? 'account' : 'accounts'}</small></td><td>{group.currency}</td><td>—</td>{metricColumns.map(column => <td className="numeric-cell" key={column.key}>{column.format(group.metrics?.[column.key], group.currency)}</td>)}<td><span className={`account-freshness ${freshnessState(group.freshness_timestamp)}`}>{freshnessLabel(group.freshness_timestamp)}</span></td><td/></tr>
      })}</tfoot>}</table></div>{performance.isSuccess && accounts.length === 0 && <div className="performance-empty-row">No ad accounts are assigned to this client.</div>}</div>
    </div>
  </section>
}

function freshnessState(value) {
  if (!value) return 'missing'
  return Date.now() - Date.parse(value) > 36 * 60 * 60 * 1000 ? 'stale' : 'fresh'
}

function freshnessLabel(value) {
  if (!value) return 'No data collected'
  const label = new Intl.DateTimeFormat('en', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }).format(new Date(value))
  return freshnessState(value) === 'stale' ? `Stale · ${label}` : `Updated ${label}`
}
