import { createElement } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Activity, AlertTriangle, Cable, FileText } from 'lucide-react'
import { api } from '../api/client'
import { AsyncStateNotice } from './ui/AsyncState'

export function ScopedAlerts({ clientId, accountId, limit = 8 }) {
  const filters = { client_id: clientId || undefined, ad_account_id: accountId || undefined, per_page: Math.max(10, limit) }
  const query = useQuery({ queryKey: ['alerts', filters], queryFn: () => api.getAlerts(filters).then(response => response.data) })
  const rows = (query.data?.data || []).slice(0, limit)
  return <OperationsList icon={AlertTriangle} title="Alerts" query={query} empty="No alerts in this scope.">
    {rows.map(alert => <Link className="scoped-operation-row" key={alert.id} to={`/alerts/${alert.id}?client_id=${alert.client_id}&ad_account_id=${alert.ad_account_id || ''}`}><span><strong>{alert.title}</strong><small>{alert.client?.name} · {alert.ad_account?.name || 'Client-wide'} · {alert.status}</small></span><em data-severity={alert.severity}>{alert.severity}</em></Link>)}
  </OperationsList>
}

export function ScopedDataHealth({ clientId, accountId, limit = 8 }) {
  const filters = { client_id: clientId || undefined, ad_account_id: accountId || undefined, per_page: Math.max(10, limit) }
  const query = useQuery({ queryKey: ['data-quality', filters], queryFn: () => api.getDataQualityIssues(filters).then(response => response.data) })
  const rows = (query.data?.data || []).slice(0, limit)
  return <OperationsList icon={Activity} title="Data health" query={query} empty="No data-quality issues in this scope.">
    {rows.map(issue => <article className="scoped-operation-row" key={issue.id}><span><strong>{issue.title}</strong><small>{issue.client?.name} · {issue.ad_account?.name || 'Client-wide'} · {issue.status}</small></span><em data-severity={issue.severity}>{issue.severity}</em></article>)}
  </OperationsList>
}

export function ScopedConnections({ clientId, accountId }) {
  const filters = { client_id: clientId || undefined, ad_account_id: accountId || undefined }
  const query = useQuery({ queryKey: ['connections', filters], queryFn: () => api.getConnections(filters).then(response => response.data) })
  return <OperationsList icon={Cable} title="Connections" query={query} empty="No connected platform matches this scope.">
    {(query.data || []).map(connection => <article className="scoped-operation-row" key={connection.id}><span><strong>{connection.name}</strong><small>{connection.platform} · {connection.ad_accounts_count} assigned account{connection.ad_accounts_count === 1 ? '' : 's'}{connection.last_error ? ` · ${connection.last_error}` : ''}</small></span><em data-status={connection.status}>{connection.status}</em></article>)}
  </OperationsList>
}

export function ScopedReports({ clientId, accountId, limit = 8 }) {
  const filters = { client_id: clientId || undefined, ad_account_id: accountId || undefined }
  const performance = useQuery({ queryKey: ['report-schedules', filters], queryFn: () => api.getReportSchedules(filters).then(response => response.data) })
  const audits = useQuery({ queryKey: ['account-audits', filters], queryFn: () => api.getReports({ ...filters, per_page: 25 }).then(response => response.data) })
  return <section className="scoped-operations-panel"><header><span><FileText size={16}/></span><div><h2>Reports</h2><p>Performance snapshots and account audits for this scope.</p></div></header><AsyncStateNotice queries={[performance, audits]}/><div className="scoped-report-columns"><div><h3>Performance reports</h3>{(performance.data?.runs || []).slice(0, limit).map(run => <a className="scoped-operation-row" key={run.id} href={run.share_url || undefined} target={run.share_url ? '_blank' : undefined} rel="noreferrer"><span><strong>{run.ad_account?.name || run.client?.name}</strong><small>{run.period_start?.slice(0, 10)} — {run.period_end?.slice(0, 10)}</small></span><em data-status={run.status}>{run.status}</em></a>)}{!performance.isLoading && !(performance.data?.runs || []).length && <p className="scoped-empty">No performance reports.</p>}</div><div><h3>Account audits</h3>{(audits.data?.data || []).slice(0, limit).map(report => <Link className="scoped-operation-row" key={report.id} to={`/reports/${report.id}`}><span><strong>{report.ad_account?.name || `${report.platform} account`}</strong><small>{report.period} · {report.grade || 'Not graded'}</small></span><em data-status={report.status}>{report.status}</em></Link>)}{!audits.isLoading && !(audits.data?.data || []).length && <p className="scoped-empty">No account audits.</p>}</div></div></section>
}

function OperationsList({ icon, title, query, empty, children }) {
  const hasRows = Array.isArray(query.data) ? query.data.length > 0 : (query.data?.data || []).length > 0
  return <section className="scoped-operations-panel"><header><span>{createElement(icon, { size: 16 })}</span><div><h2>{title}</h2><p>Current records for the selected client and account.</p></div></header><AsyncStateNotice queries={[query]}/><div className="scoped-operation-list">{children}{query.isSuccess && !hasRows && <p className="scoped-empty">{empty}</p>}</div></section>
}
