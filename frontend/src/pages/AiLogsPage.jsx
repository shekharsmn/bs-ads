import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { BarChart3, ChevronDown, ChevronLeft, ChevronRight, Cpu, ExternalLink } from 'lucide-react'
import { api } from '../api/client'

function fmtTokens(value) {
  const number = Number(value || 0)
  if (number >= 1_000_000) return `${(number / 1_000_000).toFixed(2)}M`
  if (number >= 1_000) return `${(number / 1_000).toFixed(1)}K`
  return String(number)
}

function fmtCost(value, precision = 5) {
  return value == null ? '—' : `$${Number(value).toFixed(precision)}`
}

function fmtDatetime(iso) {
  if (!iso) return '—'
  return new Date(iso).toLocaleString('en-US', {
    month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit',
  })
}

const CALL_TYPE_LABELS = {
  transcript: 'Transcript',
  static_analysis: 'Static analysis',
  video_analysis: 'Video analysis',
  context: 'Brand context',
  personas: 'Personas',
  creative: 'Creative review',
  insights: 'Insights',
}

function CallTypeBadge({ type }) {
  return <span className={`ai-stage-badge ${type || 'unknown'}`}>{CALL_TYPE_LABELS[type] || 'Unknown'}</span>
}

function UsageSummary({ summary, groupCount }) {
  const totalTokens = Number(summary.input_tokens || 0) + Number(summary.output_tokens || 0)
  return <div className="ai-usage-summary">
    <div className="ai-usage-spend"><small>Estimated spend</small><strong>{fmtCost(summary.estimated_cost, 4)}</strong><span>{Number(summary.estimated_request_count || 0)} current-price estimates · {Number(summary.unpriced_request_count || 0)} unpriced</span></div>
    <div><small>Audits & reports</small><strong>{groupCount || 0}</strong></div>
    <div><small>AI requests</small><strong>{summary.request_count || 0}</strong></div>
    <div><small>Total tokens</small><strong>{fmtTokens(totalTokens)}</strong><span>{fmtTokens(summary.input_tokens)} in · {fmtTokens(summary.output_tokens)} out</span></div>
  </div>
}

function RequestDetails({ requests }) {
  return <div className="ai-request-details">
    <div className="ai-request-detail-head"><span>Stage</span><span>Provider / model</span><span>Requested</span><span>Tokens</span><span>Cost</span><span>Status</span></div>
    {requests.map(request => <div className="ai-request-detail-row" key={`${request.log_source}:${request.id}`}>
      <span><CallTypeBadge type={request.call_type}/>{request.request_name && request.log_source === 'report' && <small>{request.request_name}</small>}</span>
      <span><strong>{request.provider || 'Gemini'}</strong><small title={request.model}>{request.model || '—'}</small></span>
      <span>{fmtDatetime(request.created_at)}</span>
      <span className="ai-mono">{fmtTokens(request.input_tokens)} in<br/>{fmtTokens(request.output_tokens)} out</span>
      <strong className="ai-request-cost">{fmtCost(request.estimated_cost)}{request.cost_basis === 'current_catalog' && <small>current estimate</small>}{request.cost_basis === 'unpriced' && <small>unpriced</small>}</strong>
      <span className={`ai-request-status ${request.status}`}>{request.status || 'complete'}</span>
    </div>)}
  </div>
}

export default function AiLogsPage() {
  const [accountId, setAccountId] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [source, setSource] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [expanded, setExpanded] = useState(null)

  const { data: accountsData } = useQuery({
    queryKey: ['accounts'],
    queryFn: () => api.getAccounts().then(response => response.data),
  })
  const accounts = accountsData ?? []

  const params = { page, per_page: 20, view: 'grouped' }
  if (accountId) params.account_id = accountId
  if (dateFrom) params.date_from = dateFrom
  if (dateTo) params.date_to = dateTo
  if (source) params.source = source
  if (status) params.status = status

  const { data, isLoading, isError } = useQuery({
    queryKey: ['ai-logs', params],
    queryFn: () => api.getAiLogs(params).then(response => response.data),
    keepPreviousData: true,
  })

  const groups = data?.data ?? []
  const summary = data?.summary ?? {}
  const meta = data?.meta ?? {}
  const updateFilter = setter => event => { setter(event.target.value); setPage(1); setExpanded(null) }
  const clearFilters = () => { setAccountId(''); setDateFrom(''); setDateTo(''); setSource(''); setStatus(''); setPage(1); setExpanded(null) }

  return <main className="ai-usage-page">
    <header className="ai-usage-header fade-up">
      <div><span className="ai-usage-icon"><Cpu size={17}/></span><div><h1>AI usage</h1><p>Spend grouped by audit or report, with every underlying model request available for review.</p></div></div>
    </header>

    <section className="ai-usage-toolbar fade-up">
      <label><span>Meta account</span><select value={accountId} onChange={updateFilter(setAccountId)}><option value="">All accounts</option>{accounts.map(account => <option key={account.id} value={account.id}>{account.label || account.account_name}</option>)}</select></label>
      <label><span>Source</span><select value={source} onChange={updateFilter(setSource)}><option value="">All sources</option><option value="content_audit">Content audits</option><option value="report">Performance reports</option></select></label>
      <label><span>Status</span><select value={status} onChange={updateFilter(setStatus)}><option value="">All statuses</option><option value="complete">Complete</option><option value="failed">Failed</option><option value="running">Running</option></select></label>
      <label><span>From</span><input type="date" value={dateFrom} onChange={updateFilter(setDateFrom)}/></label>
      <label><span>To</span><input type="date" value={dateTo} onChange={updateFilter(setDateTo)}/></label>
      {(accountId || dateFrom || dateTo || source || status) && <button onClick={clearFilters}>Clear filters</button>}
    </section>

    {!isLoading && groups.length > 0 && <UsageSummary summary={summary} groupCount={meta.total}/>}

    <section className="ai-usage-ledger fade-up">
      <div className="ai-usage-ledger-title"><div><BarChart3 size={16}/><strong>Spend by request</strong></div><span>{meta.total || 0} audits and reports</span></div>
      {isLoading ? <div className="ai-usage-empty"><span className="ai-usage-loader"/><p>Loading usage…</p></div> : isError ? <div className="ai-usage-empty"><Cpu size={28}/><p>AI usage could not be loaded.</p></div> : groups.length === 0 ? <div className="ai-usage-empty"><Cpu size={28}/><p>No AI usage found for these filters.</p></div> : <div className="ai-usage-table-wrap"><table className="ai-usage-table">
        <thead><tr><th>Audit or report</th><th>Requests</th><th>Input</th><th>Output</th><th>Last used</th><th>Spend</th><th/></tr></thead>
        <tbody>{groups.map(group => {
          const isOpen = expanded === group.group_id
          const destination = group.audit_id ? `/ad-library/audits/${group.audit_id}` : `/reports/${group.report_id}`
          const title = group.group_name || `Performance report #${group.report_id}`
          return <FragmentRow key={group.group_id} group={group} isOpen={isOpen} destination={destination} title={title} onToggle={() => setExpanded(isOpen ? null : group.group_id)}/>
        })}</tbody>
      </table></div>}
    </section>

    {meta.last_page > 1 && <nav className="ai-usage-pagination"><span>Showing {meta.from}–{meta.to} of {meta.total} audits and reports</span><div><button onClick={() => setPage(current => Math.max(1, current - 1))} disabled={page <= 1}><ChevronLeft size={13}/> Prev</button><span>{page} / {meta.last_page}</span><button onClick={() => setPage(current => Math.min(meta.last_page, current + 1))} disabled={page >= meta.last_page}>Next <ChevronRight size={13}/></button></div></nav>}
  </main>
}

function FragmentRow({ group, isOpen, destination, title, onToggle }) {
  return <>
    <tr className={`ai-usage-group ${isOpen ? 'open' : ''}`} onClick={onToggle}>
      <td><div className="ai-usage-request"><span className={`ai-usage-kind ${group.log_source}`}>{group.audit_id ? 'Content audit' : 'Performance report'}</span><strong>{title}</strong><small>{group.scope_name || 'No account'}{group.failed_count > 0 ? ` · ${group.failed_count} failed` : ''}</small></div></td>
      <td><strong>{group.request_count}</strong><small>model calls</small></td>
      <td className="ai-mono">{fmtTokens(group.input_tokens)}</td>
      <td className="ai-mono">{fmtTokens(group.output_tokens)}</td>
      <td>{fmtDatetime(group.latest_request_at)}</td>
      <td className="ai-usage-group-cost">{fmtCost(group.estimated_cost, 4)}{Number(group.estimated_request_count || 0) > 0 && <small>estimated</small>}{Number(group.unpriced_request_count || 0) > 0 && <small>{group.unpriced_request_count} unpriced</small>}</td>
      <td><button className="ai-usage-expand" aria-label={isOpen ? 'Collapse request details' : 'Expand request details'} onClick={event => { event.stopPropagation(); onToggle() }}><ChevronDown size={15}/></button></td>
    </tr>
    {isOpen && <tr className="ai-usage-expanded"><td colSpan="7"><div className="ai-usage-expanded-head"><span>{group.request_count} requests in this {group.audit_id ? 'audit' : 'report'}</span><Link to={destination} onClick={event => event.stopPropagation()}>Open {group.audit_id ? 'audit' : 'report'} <ExternalLink size={11}/></Link></div><RequestDetails requests={group.requests || []}/></td></tr>}
  </>
}
