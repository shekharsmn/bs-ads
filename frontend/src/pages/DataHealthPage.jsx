import { useQuery } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { Activity, CircleAlert } from 'lucide-react'
import { api } from '../api/client'
import { AsyncState } from '../components/ui/AsyncState'
import ScopeFilterBar from '../components/ScopeFilterBar'

export default function DataHealthPage() {
  const [search, setSearch] = useSearchParams()
  const clientId = search.get('client_id') || ''
  const accountId = search.get('ad_account_id') || undefined
  const status = search.get('status') || ''
  const severity = search.get('severity') || ''
  const setFilter = (key, value) => setSearch(current => { const next = new URLSearchParams(current); value ? next.set(key, value) : next.delete(key); return next }, { replace: true })
  const setScope = (nextClientId, nextAccountId) => { const next = new URLSearchParams(search); nextClientId ? next.set('client_id', nextClientId) : next.delete('client_id'); nextAccountId ? next.set('ad_account_id', nextAccountId) : next.delete('ad_account_id'); setSearch(next, { replace: true }) }
  const query = useQuery({ queryKey: ['data-quality', clientId, accountId, status, severity], queryFn: () => api.getDataQualityIssues({ client_id: clientId || undefined, ad_account_id: accountId, status: status || undefined, severity: severity || undefined }).then(r => r.data) })
  const issues = query.data?.data || []

  return <main className="data-health-page">
    <header className="governance-header"><span><Activity size={18}/></span><div><h1>Data health</h1><p>Collection freshness, consistency, account access, and conversion integrity across accessible accounts.</p></div></header>
    <ScopeFilterBar clientId={clientId} accountId={accountId || ''} onScopeChange={setScope} onAccountChange={value => setFilter('ad_account_id', value)}><select value={status} onChange={e => setFilter('status', e.target.value)}><option value="open">Open</option><option value="resolved">Resolved</option><option value="">All statuses</option></select><select value={severity} onChange={e => setFilter('severity', e.target.value)}><option value="">All severities</option><option value="critical">Critical</option><option value="warning">Warning</option></select></ScopeFilterBar>
    <AsyncState query={query} loadingText="Checking data health…" errorText="Data health could not be loaded." empty={query.isSuccess && issues.length === 0} emptyText="No matching data-quality issues. Scheduled checks will keep monitoring these accounts.">
      <div className="quality-list">{issues.map(issue => <article key={issue.id} className={issue.severity}><CircleAlert size={18}/><div><header><strong>{issue.title}</strong><span>{issue.severity}</span></header><p>{issue.ad_account?.name} · {issue.client?.name}</p><small>Last detected {new Date(issue.last_detected_at).toLocaleString()}</small><details><summary>Evidence and remediation</summary><pre>{JSON.stringify(issue.evidence, null, 2)}</pre><p>{issue.remediation}</p></details></div></article>)}</div>
    </AsyncState>
  </main>
}
